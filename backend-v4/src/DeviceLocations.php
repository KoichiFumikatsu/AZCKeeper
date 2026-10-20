<?php
declare(strict_types=1);
namespace Keeper;

final class DeviceLocations
{
    public const OPERATIONS = ['getUserLocations','getDeviceLocations','getSiteLocation','setSiteLocation','setDeviceLost'];
    private ?array $sites = null;
    private ?string $calendarDay = null;

    public function __construct(private Database $db) {}

    public static function publicIp(Request $r): ?string
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? '';
        $trusted = array_filter(array_map('trim', explode(',', Config::get('TRUSTED_PROXY_IPS'))));
        $ip = $peer;
        if (in_array($peer, $trusted, true)) {
            $hops = array_map('trim', explode(',', $r->header('x-forwarded-for')));
            $ip = end($hops) ?: $r->header('x-real-ip');
        }
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? inet_ntop(inet_pton($ip)) : null;
    }

    public static function validatePoint(object $p): void
    {
        $coords = [isset($p->latitude), isset($p->longitude), isset($p->accuracy_m)];
        if (count(array_filter($coords)) !== 0 && count(array_filter($coords)) !== 3) { throw new ApiError(422,'validation_failed'); }
        if ($p->network_type !== 'wifi' && (isset($p->wifi_ssid) || isset($p->wifi_bssid))) { throw new ApiError(422,'validation_failed'); }
    }

    private function sites(string $tenant): array
    {
        return $this->sites ??= $this->db->run("SELECT s.* FROM site_location_settings s JOIN org_units u ON u.tenant_id=s.tenant_id AND u.id=s.site_id WHERE s.tenant_id=? AND u.kind='site' AND u.active=TRUE AND u.deleted_at IS NULL ORDER BY s.site_id",[$tenant])->fetchAll();
    }

    /** Una coincidencia ambigua nunca elige una sede por orden de consulta. */
    public static function classify(object $p, ?string $ip, array $sites): array
    {
        $net = []; $geo = [];
        foreach ($sites as $site) {
            $match = $ip !== null && in_array($ip,json_decode($site['public_ips'],true),true);
            foreach (json_decode($site['wifi'],true) as $wifi) {
                if (($p->wifi_ssid ?? null) === $wifi['ssid'] && ($wifi['bssid'] === null || strcasecmp($p->wifi_bssid ?? '',$wifi['bssid']) === 0)) { $match = true; }
            }
            if ($match) { $net[] = $site['site_id']; }
            if (isset($p->latitude,$p->longitude,$p->accuracy_m,$site['latitude'],$site['longitude'],$site['radius_m']) && $p->accuracy_m < $site['radius_m']) {
                $lat = deg2rad((float)$site['latitude'] - $p->latitude); $lon = deg2rad((float)$site['longitude'] - $p->longitude);
                $a = sin($lat/2)**2 + cos(deg2rad($p->latitude))*cos(deg2rad((float)$site['latitude']))*sin($lon/2)**2;
                if (6371000 * 2 * asin(sqrt(min(1,max(0,$a)))) <= $site['radius_m']) { $geo[] = $site['site_id']; }
            }
        }
        if (count($net) === 1) { return ['en_sede',$net[0],'red']; }
        if (count($net) > 1) {
            $both = array_values(array_intersect($net,$geo));
            return count($both) === 1 ? ['en_sede',$both[0],'geocerca'] : ['en_sede',null,'red'];
        }
        if ($geo) { return ['en_sede',count($geo) === 1 ? $geo[0] : null,'geocerca']; }
        return [isset($p->latitude) || $ip !== null || !empty($p->wifi_ssid) || !empty($p->wifi_bssid) ? 'fuera' : 'sin_dato',null,null];
    }

    private function workHours(string $tenant, string $user, string $at): bool
    {
        $day = substr($at,0,10);
        if ($this->calendarDay !== $day) {
            ActivityCalendar::create($this->db,$tenant,date('Y-m-d',strtotime("$day UTC -2 days")),date('Y-m-d',strtotime("$day UTC +2 days")));
            $this->calendarDay = $day;
        }
        return (bool)$this->db->one('SELECT 1 inside FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=a.schedule_id
            WHERE a.tenant_id=? AND a.user_id=? AND a.starts_at<=? AND (a.ends_at IS NULL OR a.ends_at>?)
            AND ?>=c.work_start AND ?<c.work_start+INTERVAL c.expected_seconds SECOND
            AND (c.lunch_start IS NULL OR ?<c.lunch_start OR ?>=c.lunch_end)
            AND '.ActivityCalendar::expected().' > 0
            AND NOT EXISTS (SELECT 1 FROM absences ab WHERE ab.tenant_id=a.tenant_id AND ab.user_id=a.user_id AND ab.status=\'approved\' AND ab.starts_at<=? AND ab.ends_at>?) LIMIT 1',
            [$tenant,$user,$at,$at,$at,$at,$at,$at,$at,$at]);
    }

    public function ingest(array $c, Request $r, object $p): array
    {
        self::validatePoint($p);
        $tenant=$c['tenant_id']; $device=$c['device_id']; $id=Util::bin($p->event_id); $at=Util::sqlTime($p->at);
        $old=$this->db->one('SELECT body_hash FROM device_locations WHERE tenant_id=? AND device_id=? AND id=?',[$tenant,$device,$id]);
        if ($old) { return Ingest::ack($p->event_id,hash_equals($old['body_hash'],Util::hash($p))?'duplicate':'rejected',hash_equals($old['body_hash'],Util::hash($p))?null:'event_conflict'); }
        if (strtotime($p->at)>time()+300) { throw new ApiError(422,'invalid_range'); }
        $d=$this->db->one('SELECT lost_at FROM devices WHERE tenant_id=? AND id=?',[$tenant,$device]);
        if (!$d || ($d['lost_at']===null && strtotime($p->at)<time()-90*86400)) { throw new ApiError(422,'invalid_range'); }
        $assignment=$this->db->one('SELECT user_id FROM device_assignments WHERE tenant_id=? AND device_id=? AND starts_at<=? AND (ends_at IS NULL OR ends_at>?)',[$tenant,$device,$at,$at]);
        if (!$assignment) { throw new ApiError(422,'invalid_range'); }
        $user=$assignment['user_id'];
        if (!in_array('ubicacion',PersonPlans::functions($this->db,$tenant,$user),true)) { return Ingest::ack($p->event_id); }
        $policy=$this->db->one('SELECT document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1',[$tenant,$device]);
        if (!(json_decode($policy['document']??'{}')->location_tracking??false)) { return Ingest::ack($p->event_id); }
        $ip=self::publicIp($r);
        // La cabecera describe la red al enviar, incluso en reintentos del mismo cuerpo durable.
        $current=$ip!==null && strtolower($r->header('x-keeper-network-id'))===strtolower($p->network_id) && abs(time()-strtotime($p->at))<=120;
        [$result,$site,$by]=self::classify($p,$current?$ip:null,$this->sites($tenant));
        $this->db->run('INSERT INTO device_locations (tenant_id,id,device_id,user_id,at,latitude,longitude,accuracy_m,network_type,wifi_ssid,wifi_bssid,public_ip,ip_at_capture,result,site_id,matched_by,in_work_hours,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$tenant,$id,$device,$user,$at,$p->latitude??null,$p->longitude??null,$p->accuracy_m??null,$p->network_type,$p->wifi_ssid??null,isset($p->wifi_bssid)?strtolower($p->wifi_bssid):null,$ip,(int)$current,$result,$site,$by,(int)$this->workHours($tenant,$user,$at),Util::hash($p)]);
        return Ingest::ack($p->event_id);
    }

    private static function dto(array $r): array
    {
        $out=[];
        foreach (['id','device_id','user_id'] as $k) { $out[$k]=Util::id($r[$k]); }
        foreach (['network_type','wifi_ssid','wifi_bssid','result','site_name','matched_by'] as $k) { $out[$k]=$r[$k]; }
        foreach (['latitude','longitude','accuracy_m'] as $k) { $out[$k]=$r[$k]===null?null:(float)$r[$k]; }
        return $out+['at'=>Util::time($r['at']),'site_id'=>$r['site_id']===null?null:Util::id($r['site_id']),'in_work_hours'=>(bool)$r['in_work_hours']];
    }

    public function history(array $c, Request $r, ?AdminAccess $access, bool $device): array
    {
        $tenant=$c['tenant_id']; $id=Util::bin($r->resourceId); $include=($_GET['include_out_of_hours']??'false')==='true';
        if (!Features::has($this->db,$tenant,'ubicacion') && !($c['platform']??false)) { throw new ApiError(403,'permission_denied'); }
        if ($include && !($access?->full()??false)) { throw new ApiError(403,'permission_denied'); }
        $d=$device?$this->db->one('SELECT user_id,lost_at,lost_reason FROM devices WHERE tenant_id=? AND id=?',[$tenant,$id]):null;
        if ($device && !$d) { throw new ApiError(404,'resource_not_found'); }
        $user=$device?$d['user_id']:$id;
        $person=$access?->user($user)??$this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$tenant,$user]);
        if (!$person) { throw new ApiError(404,'resource_not_found'); }
        $zone=$this->db->one('SELECT COALESCE(ds.timezone,s.timezone,t.timezone) timezone FROM tenants t LEFT JOIN schedules s ON s.tenant_id=t.tenant_id AND s.id=? LEFT JOIN schedules ds ON ds.tenant_id=t.tenant_id AND ds.id=t.default_schedule_id AND (s.id IS NULL OR s.placeholder=TRUE) WHERE t.tenant_id=?',[$person['schedule_id'],$tenant])['timezone'];
        $day=new \DateTimeImmutable($_GET['day'],new \DateTimeZone($zone));
        $today=new \DateTimeImmutable('today',new \DateTimeZone($zone));
        if ($day>$today || $day<$today->modify('-89 days')) { throw new ApiError(422,'invalid_range'); }
        [$scope,$scopeArgs]=$access?->usersSql('u')??['1=1',[]];
        $where='l.tenant_id=? AND l.'.($device?'device_id':'user_id').'=? AND EXISTS (SELECT 1 FROM users u WHERE u.tenant_id=l.tenant_id AND u.id=l.user_id AND '.$scope.')'.($include?'':' AND l.in_work_hours=TRUE');
        $args=[$tenant,$id,...$scopeArgs];
        $select='SELECT l.*,s.name site_name FROM device_locations l LEFT JOIN org_units s ON s.tenant_id=l.tenant_id AND s.id=l.site_id WHERE ';
        $latest=$this->db->one($select.$where.' ORDER BY l.at DESC,l.device_id DESC,l.id DESC LIMIT 1',$args);
        if ($include) {
            (new Audit($this->db))->record($c,$r,'location.out_of_hours_read',$device?'device':'user',$id,['day:'.$_GET['day'],'latest','user:'.Util::id($user)]);
        }
        $resources=new Resources($this->db,['cursor_context'=>Util::json([Util::id($tenant),Util::id($c['principal_id']),$r->path,$_GET['day'],$include,$scope,array_map('bin2hex',$scopeArgs)])],new Validator());
        $cursor=isset($_GET['cursor'])?$resources->decodeCursor($_GET['cursor']):null;
        $where.=' AND l.at>=? AND l.at<?';
        $utc=static fn($v)=>$v->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        array_push($args,$utc($day),$utc($day->modify('+1 day')));
        if ($cursor) { $where.=' AND (l.at,l.device_id,l.id)>(?,?,?)'; array_push($args,$cursor['at'],Util::bin($cursor['device']),Util::bin($cursor['id'])); }
        $rows=$this->db->run($select.$where.' ORDER BY l.at,l.device_id,l.id LIMIT 501',$args)->fetchAll();
        $next=null;
        if (count($rows)>500) { array_pop($rows); $last=end($rows); $next=$resources->encodeCursor(['at'=>$last['at'],'device'=>Util::id($last['device_id']),'id'=>Util::id($last['id']),'exp'=>time()+900]); }
        return ['data'=>array_map([self::class,'dto'],$rows),'next_cursor'=>$next,'latest'=>$latest?self::dto($latest):null,'can_include_out_of_hours'=>$access?->full()??false,'lost_at'=>isset($d['lost_at'])?Util::time($d['lost_at']):null,'lost_reason'=>$d['lost_reason']??null];
    }

    public function admin(array $c, Request $r, AdminAccess $access): array
    {
        $op=$r->operation['operationId']; $id=Util::bin($r->resourceId); $tenant=$c['tenant_id']; $b=$r->body;
        if ($op==='getUserLocations' || $op==='getDeviceLocations') { return $this->history($c,$r,$access,$op==='getDeviceLocations'); }
        if (!Features::has($this->db,$tenant,'ubicacion') && !($c['platform']??false)) { throw new ApiError(403,'permission_denied'); }
        if ($op==='setDeviceLost') {
            $d=$this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE',[$tenant,$id]);
            if (!$d) { throw new ApiError(404,'resource_not_found'); } $access->user($d['user_id']);
            if ($b->confirmation!==($b->lost?'PERDIDO':'CERRAR') || ($b->lost && trim($b->reason)==='')) { throw new ApiError(422,'validation_failed'); }
            if ($b->lost && $d['lost_at']!==null) { throw new ApiError(409,'invalid_transition'); }
            $at=$b->lost?Util::sqlTime(Util::now()):null;
            $this->db->run('UPDATE devices SET lost_at=?,lost_by=?,lost_reason=?,version=version+1 WHERE tenant_id=? AND id=?',[$at,$b->lost?$c['principal_id']:null,$b->lost?trim($b->reason):null,$tenant,$id]);
            (new Audit($this->db))->record($c,$r,$b->lost?'location.lost_opened':'location.lost_closed','device',$id,['lost_at','lost_by','lost_reason']);
            return ['lost_at'=>$at===null?null:Util::time($at),'lost_reason'=>$b->lost?trim($b->reason):null];
        }
        $site=$this->db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind='site' AND deleted_at IS NULL",[$tenant,$id]);
        if (!$site) { throw new ApiError(404,'resource_not_found'); }
        if (!$access->full() && !$access->allowsUnitTarget('site',$id)) { throw new ApiError(403,'permission_denied'); }
        if ($op==='setSiteLocation') {
            $ips=[]; foreach ($b->public_ips as $ip) {
                if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) { throw new ApiError(422,'validation_failed'); }
                $ips[]=inet_ntop(inet_pton($ip));
            }
            $coords=[$b->latitude,$b->longitude,$b->radius_m];
            if (count(array_filter($coords,static fn($v)=>$v!==null))!==0 && count(array_filter($coords,static fn($v)=>$v!==null))!==3) { throw new ApiError(422,'validation_failed'); }
            foreach ($b->wifi as $wifi) { if ($wifi->ssid==='') { throw new ApiError(422,'validation_failed'); } if ($wifi->bssid!==null) { $wifi->bssid=strtolower($wifi->bssid); } }
            $this->db->run('INSERT INTO site_location_settings (tenant_id,site_id,public_ips,wifi,latitude,longitude,radius_m) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE public_ips=VALUES(public_ips),wifi=VALUES(wifi),latitude=VALUES(latitude),longitude=VALUES(longitude),radius_m=VALUES(radius_m),version=version+1',[$tenant,$id,Util::json(array_values(array_unique($ips))),Util::json($b->wifi),...$coords]);
            $this->db->run('INSERT INTO location_reclassification (tenant_id,until_at) VALUES (?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE until_at=VALUES(until_at),cursor_at=NULL,cursor_device=NULL,cursor_id=NULL',[$tenant]);
            (new Audit($this->db))->record($c,$r,'location.site_updated','org_unit',$id,['public_ips','wifi','geofence']);
        }
        $row=$this->db->one('SELECT * FROM site_location_settings WHERE tenant_id=? AND site_id=?',[$tenant,$id]);
        $ips=$this->db->run('SELECT DISTINCT l.public_ip FROM device_locations l JOIN devices d ON d.tenant_id=l.tenant_id AND d.id=l.device_id JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE l.tenant_id=? AND u.site_id=? AND l.public_ip IS NOT NULL AND l.received_at>=UTC_TIMESTAMP(6)-INTERVAL 7 DAY LIMIT 50',[$tenant,$id])->fetchAll();
        return ['public_ips'=>$row?json_decode($row['public_ips']):[],'wifi'=>$row?json_decode($row['wifi']):[],'latitude'=>isset($row['latitude'])?(float)$row['latitude']:null,'longitude'=>isset($row['longitude'])?(float)$row['longitude']:null,'radius_m'=>isset($row['radius_m'])?(float)$row['radius_m']:null,'version'=>(int)($row['version']??1),'suggested_ips'=>array_column($ips,'public_ip')];
    }

    public function reclassify(string $tenant, int $batch = 100): int
    {
        return $this->db->transaction(fn()=>$this->reclassifyBatch($tenant,$batch));
    }

    private function reclassifyBatch(string $tenant, int $batch): int
    {
        $job=$this->db->one('SELECT * FROM location_reclassification WHERE tenant_id=? FOR UPDATE',[$tenant]);
        if (!$job) { return 0; }
        $this->sites=null; $sites=$this->sites($tenant);
        $args=[$tenant,$job['until_at'],$job['until_at']];
        $cursor='';
        if ($job['cursor_at']!==null) {
            $cursor=' AND at>=? AND (at,device_id,id)>(?,?,?)';
            array_push($args,$job['cursor_at'],$job['cursor_at'],$job['cursor_device'],$job['cursor_id']);
        }
        $args[]=$batch;
        $rows=$this->db->run('SELECT * FROM device_locations WHERE tenant_id=? AND at>=?-INTERVAL 7 DAY AND at<=?'.$cursor.' ORDER BY at,device_id,id LIMIT ?',$args)->fetchAll();
        foreach ($rows as $row) {
            $point=(object)array_filter($row,static fn($v)=>$v!==null);
            foreach (['latitude','longitude','accuracy_m'] as $key) { if (isset($point->$key)) { $point->$key=(float)$point->$key; } }
            [$result,$match,$by]=self::classify($point,$row['ip_at_capture']?$row['public_ip']:null,$sites);
            $this->db->run('UPDATE device_locations SET result=?,site_id=?,matched_by=? WHERE tenant_id=? AND device_id=? AND id=?',[$result,$match,$by,$tenant,$row['device_id'],$row['id']]);
        }
        if (count($rows)<$batch) { $this->db->run('DELETE FROM location_reclassification WHERE tenant_id=?',[$tenant]); }
        else {
            $last=end($rows);
            $this->db->run('UPDATE location_reclassification SET cursor_at=?,cursor_device=?,cursor_id=? WHERE tenant_id=?',[$last['at'],$last['device_id'],$last['id'],$tenant]);
        }
        return count($rows);
    }

    public function purge(string $tenant, int $batch, float $deadline): int
    {
        $count=0;
        foreach ($this->db->run('SELECT id FROM devices WHERE tenant_id=? AND lost_at IS NULL',[$tenant])->fetchAll() as $d) {
            do {
                if (microtime(true)>=$deadline) { return $count; }
                $n=$this->db->transaction(function () use ($tenant,$d,$batch) {
                    $device=$this->db->one('SELECT lost_at FROM devices WHERE tenant_id=? AND id=? FOR UPDATE',[$tenant,$d['id']]);
                    if (!$device || $device['lost_at']!==null) { return 0; }
                    return $this->db->run('DELETE FROM device_locations WHERE tenant_id=? AND device_id=? AND at<UTC_TIMESTAMP(6)-INTERVAL 90 DAY LIMIT ?',[$tenant,$d['id'],$batch])->rowCount();
                });
                $count+=$n;
            } while ($n===$batch);
        }
        return $count;
    }
}
