<?php
declare(strict_types=1);
namespace Keeper;

final class ScheduleSettings
{
    public function __construct(private Database $db,private array $c,private AdminAccess $access,private Request $r) {}
    public function dispatch(?object $b): array
    {
        $this->access->requireFull(); $tenant=$this->c['tenant_id'];
        if ($b!==null) {
            $schedule=$b->default_schedule_id===null?null:Util::bin($b->default_schedule_id);
            if ($schedule!==null && !$this->db->one('SELECT id FROM schedules WHERE tenant_id=? AND id=? AND placeholder=FALSE',[$tenant,$schedule])) { throw new ApiError(404,'resource_not_found'); }
            $this->db->run('UPDATE tenants SET punctuality_margin_minutes=?,default_schedule_id=?,version=version+1 WHERE tenant_id=?',[$b->punctuality_margin_minutes,$schedule,$tenant]);
            $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)',[$tenant]);
            $day=(new \DateTimeImmutable('today',new \DateTimeZone($this->c['timezone'])))->modify('-29 days')->format('Y-m-d');
            $this->db->run("INSERT INTO punctuality_margins SELECT ?,'1000-01-01',15 WHERE NOT EXISTS (SELECT 1 FROM punctuality_margins WHERE tenant_id=?)",[$tenant,$tenant]);
            $this->db->run('INSERT INTO punctuality_margins VALUES (?,?,?) ON DUPLICATE KEY UPDATE minutes=VALUES(minutes)',[$tenant,$day,$b->punctuality_margin_minutes]);
            $days=[];
            for ($n=0;$n<30;$n++) {
                $date=(new \DateTimeImmutable($day))->modify("+$n days")->format('Y-m-d');
                $days[]=$date;
                $this->db->run('INSERT INTO rollup_days (tenant_id,day) VALUES (?,?) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL',[$tenant,$date]);
            }
            ActivityCalendar::refresh($this->db,$tenant,$days);
            (new Audit($this->db))->record($this->c,$this->r,'putScheduleSettings','tenant',$tenant,['punctuality_margin_minutes','default_schedule_id']);
        }
        $row=$this->db->one('SELECT punctuality_margin_minutes,default_schedule_id FROM tenants WHERE tenant_id=?',[$tenant]);
        return AdminApi::response(['punctuality_margin_minutes'=>(int)$row['punctuality_margin_minutes'],'default_schedule_id'=>$row['default_schedule_id']===null?null:Util::id($row['default_schedule_id'])]);
    }
}
