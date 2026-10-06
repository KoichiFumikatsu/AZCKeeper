<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Calendarios de festivos (uno por pais o los que se quieran). Cada persona usa, en orden: el calendario que se le
 * asigno, el del pais de su sede, el calendario por defecto de la empresa. Se asigna en grupo, como los horarios.
 */
final class HolidayCalendars
{
    private string $tenant;
    public function __construct(private Database $db,private array $c,private AdminAccess $access,private Request $r) { $this->tenant=$c['tenant_id']; }

    /** SQL del calendario efectivo de la persona de la asignacion $a (columna user_id y site_id). */
    public static function effective(string $a='a'): string
    {
        return "COALESCE((SELECT u.holiday_calendar_id FROM users u JOIN holiday_calendars hu ON hu.tenant_id=u.tenant_id AND hu.id=u.holiday_calendar_id AND hu.active=TRUE WHERE u.tenant_id=$a.tenant_id AND u.id=$a.user_id),"
            ."(SELECT hs.id FROM org_units su JOIN holiday_calendars hs ON hs.tenant_id=su.tenant_id AND hs.country_code=su.country_code AND hs.active=TRUE WHERE su.tenant_id=$a.tenant_id AND su.id=$a.site_id ORDER BY hs.is_default DESC,hs.name LIMIT 1),"
            ."(SELECT hd.id FROM holiday_calendars hd WHERE hd.tenant_id=$a.tenant_id AND hd.is_default=TRUE AND hd.active=TRUE ORDER BY hd.name LIMIT 1))";
    }

    public function dispatch(string $op,?string $id,?object $b): array
    {
        if ($op==='listHolidayCalendars') {
            $limit=(int)($_GET['limit']??50); $offset=(int)($_GET['offset']??0);
            $rows=$this->db->run('SELECT * FROM holiday_calendars WHERE tenant_id=? ORDER BY is_default DESC,name LIMIT ? OFFSET ?',[$this->tenant,$limit+1,$offset])->fetchAll();
            $more=count($rows)>$limit; if ($more) { array_pop($rows); }
            return AdminApi::response(['data'=>array_map([$this,'dto'],$rows),'next_offset'=>$more?$offset+$limit:null]);
        }
        $old=$id===null?null:$this->row($id);
        if ($op==='getHolidayCalendar') { return AdminApi::response($this->dto($old)); }
        $this->access->requireFull();
        if ($op==='assignHolidayCalendar') {
            if (!$old['active']) { throw new ApiError(409,'invalid_transition',[],'calendar_inactive'); }
            $ids=array_map([Util::class,'bin'],$b->user_ids);
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $found=(int)$this->db->one("SELECT COUNT(*) n FROM users WHERE tenant_id=? AND id IN ($marks)",[$this->tenant,...$ids])['n'];
            if ($found!==count($ids)) { throw new ApiError(422,'validation_failed',[],'unknown_user'); }
            $before=array_column($this->db->run("SELECT DISTINCT holiday_calendar_id c FROM users WHERE tenant_id=? AND id IN ($marks) AND holiday_calendar_id IS NOT NULL",[$this->tenant,...$ids])->fetchAll(),'c');
            $this->db->run("UPDATE users SET holiday_calendar_id=?,version=version+1 WHERE tenant_id=? AND id IN ($marks)",[$id,$this->tenant,...$ids]);
            $this->recompute([$id,...$before]);
            (new Audit($this->db))->record($this->c,$this->r,$op,'holiday_calendar',$id,['user_ids']);
            return AdminApi::response($this->dto($this->row($id)));
        }
        $country=$b->country_code;
        if ($b->is_default && !$b->active) { throw new ApiError(422,'validation_failed',[],'default_calendar_inactive'); }
        $dup=$this->db->one('SELECT id FROM holiday_calendars WHERE tenant_id=? AND name=?',[$this->tenant,$b->name]);
        if ($dup && $dup['id']!==$id) { throw new ApiError(409,'invalid_transition',[],'duplicate_name'); }
        if ($b->is_default) { $this->db->run('UPDATE holiday_calendars SET is_default=FALSE,version=version+1 WHERE tenant_id=? AND is_default=TRUE AND id<>?',[$this->tenant,$id??'']); }
        if ($old) {
            $this->db->run('UPDATE holiday_calendars SET name=?,country_code=?,is_default=?,active=?,version=version+1 WHERE tenant_id=? AND id=?',[$b->name,$country,(int)$b->is_default,(int)$b->active,$this->tenant,$id]);
        } else {
            $id=Util::bin(Util::uuid());
            $this->db->run('INSERT INTO holiday_calendars (tenant_id,id,name,country_code,is_default,active) VALUES (?,?,?,?,?,?)',[$this->tenant,$id,$b->name,$country,(int)$b->is_default,(int)$b->active]);
        }
        // Cambiar pais, defecto o estado cambia quien usa que festivos: se recalculan los dias festivos de todos los calendarios.
        if ($old && ($old['country_code']!==$country || (bool)$old['is_default']!==$b->is_default || (bool)$old['active']!==$b->active)) {
            $this->recompute(array_column($this->db->run('SELECT id FROM holiday_calendars WHERE tenant_id=?',[$this->tenant])->fetchAll(),'id'));
        }
        (new Audit($this->db))->record($this->c,$this->r,$op,'holiday_calendar',$id,array_keys(get_object_vars($b)));
        return AdminApi::response($this->dto($this->row($id)),$old?200:201);
    }

    /** Recalcula la jornada esperada de los dias festivos (ya pasados) de estos calendarios. */
    public function recompute(array $calendars): void { self::refresh($this->db,$this->tenant,$calendars); }
    /** Lo mismo sin sesion de panel (fuentes de datos). null = todos los calendarios de la empresa. */
    public static function refresh(Database $db,string $tenant,?array $calendars=null): void
    {
        $calendars??=array_column($db->run('SELECT id FROM holiday_calendars WHERE tenant_id=?',[$tenant])->fetchAll(),'id');
        $calendars=array_values(array_unique(array_filter($calendars)));
        if (!$calendars) { return; }
        $marks=implode(',',array_fill(0,count($calendars),'?'));
        $days=array_column($db->run("SELECT DISTINCT day FROM holidays WHERE tenant_id=? AND calendar_id IN ($marks) AND day<=UTC_DATE() AND day>=UTC_DATE()-INTERVAL 400 DAY",[$tenant,...$calendars])->fetchAll(),'day');
        if ($days) { ActivityCalendar::refresh($db,$tenant,$days); }
    }

    private function row(string $id): array
    {
        $row=$this->db->one('SELECT * FROM holiday_calendars WHERE tenant_id=? AND id=?',[$this->tenant,$id]);
        if (!$row) { throw new ApiError(404,'resource_not_found'); } return $row;
    }
    public function dto(array $r): array
    {
        $people=(int)$this->db->one('SELECT COUNT(*) n FROM users WHERE tenant_id=? AND holiday_calendar_id=?',[$this->tenant,$r['id']])['n'];
        $holidays=(int)$this->db->one('SELECT COUNT(*) n FROM holidays WHERE tenant_id=? AND calendar_id=?',[$this->tenant,$r['id']])['n'];
        return ['id'=>Util::id($r['id']),'name'=>$r['name'],'country_code'=>$r['country_code'],'is_default'=>(bool)$r['is_default'],'active'=>(bool)$r['active'],'people'=>$people,'holidays'=>$holidays,'version'=>(int)$r['version']];
    }
}
