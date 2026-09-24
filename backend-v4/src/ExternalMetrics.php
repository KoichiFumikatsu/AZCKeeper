<?php
declare(strict_types=1);
namespace Keeper;

final class ExternalMetrics
{
    public function __construct(private Database $db, private array $c, private string $from, private string $to, private array $filters) {}

    public function rows(string $group = 'day', ?array $members = null): array
    {
        $column = match ($group) { 'day'=>'x.day', 'user'=>'x.user_id', 'area'=>'a.area_id', 'total'=>"'total'" };
        $where=''; $args=[];
        foreach ($this->filters as $field=>$id) { $where.=" AND a.$field=?"; $args[]=$id; }
        if ($members!==null) {
            if (!$members) { return []; }
            $where.=' AND x.user_id IN ('.implode(',',array_fill(0,count($members),'?')).')'; array_push($args,...$members);
        }
        // Summary wins per assignment/day; episode rollups fill only missing summaries, never add twice.
        $sql="WITH facts AS (
            SELECT tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,calculation_version,calculated_at,data_through
            FROM day_summary WHERE tenant_id=? AND day>=? AND day<?
            UNION ALL
            SELECT e.tenant_id,e.day,e.user_id,e.user_assignment_id,SUM(e.active_seconds),SUM(e.idle_seconds),SUM(IF(e.category='productive',e.active_seconds,0)),0,GROUP_CONCAT(DISTINCT e.calculation_version),MIN(e.calculated_at),MIN(e.data_through)
            FROM episode_daily e WHERE e.tenant_id=? AND e.day>=? AND e.day<?
              AND NOT EXISTS (SELECT 1 FROM day_summary s WHERE s.tenant_id=e.tenant_id AND s.day=e.day AND s.user_id=e.user_id AND s.user_assignment_id=e.user_assignment_id)
            GROUP BY e.tenant_id,e.day,e.user_id,e.user_assignment_id
        ) SELECT $column bucket,SUM(x.active_seconds) active_seconds,SUM(x.idle_seconds) idle_seconds,SUM(x.productive_seconds) productive_seconds,SUM(x.expected_seconds) expected_seconds,
            AVG(f.focus_score) focus_score,MIN(LEAST(x.calculated_at,COALESCE(f.calculated_at,x.calculated_at))) calculated_at,
            MIN(LEAST(x.data_through,COALESCE(f.data_through,x.data_through))) data_through,
            GROUP_CONCAT(DISTINCT x.calculation_version) versions,GROUP_CONCAT(DISTINCT f.calculation_version) focus_versions
            FROM facts x JOIN user_assignments a ON a.tenant_id=x.tenant_id AND a.id=x.user_assignment_id AND a.user_id=x.user_id
            LEFT JOIN focus_daily f ON f.tenant_id=x.tenant_id AND f.day=x.day AND f.user_id=x.user_id AND f.user_assignment_id=x.user_assignment_id
            WHERE x.tenant_id=?$where GROUP BY $column ORDER BY $column";
        return $this->db->run($sql,[$this->c['tenant_id'],$this->from,$this->to,$this->c['tenant_id'],$this->from,$this->to,$this->c['tenant_id'],...$args])->fetchAll();
    }
    public static function percent(array $row): ?float { return (int)$row['active_seconds']>0 ? min(100,round(100*(int)$row['productive_seconds']/(int)$row['active_seconds'],2)) : null; }
    public static function score(?array $row): ?float { return isset($row['focus_score'])?(float)$row['focus_score']:null; }
    public static function point(array $row): array
    {
        return ['day'=>$row['bucket'],'active_seconds'=>(int)$row['active_seconds'],'idle_seconds'=>(int)$row['idle_seconds'],'focus_score'=>self::score($row),'productivity_percent'=>self::percent($row)];
    }
    public function report(?array $members = null): array
    {
        $rows=$this->rows('day',$members); $versions=[]; $through=null; $calculated=null; $active=0; $expected=0;
        foreach ($rows as $row) {
            foreach (explode(',',$row['versions'].','.($row['focus_versions']??'')) as $version) { if ($version!=='') { $versions[$version]=true; } }
            $through=$through===null?$row['data_through']:min($through,$row['data_through']);
            $calculated=$calculated===null?$row['calculated_at']:min($calculated,$row['calculated_at']);
            $active+=(int)$row['active_seconds']; $expected+=(int)$row['expected_seconds'];
        }
        return ['tenant_id'=>Util::id($this->c['tenant_id']),'from'=>$this->from,'to'=>$this->to,'timezone'=>$this->c['timezone'],
            'calculated_at'=>$calculated===null?null:Util::time($calculated),'data_through'=>$through===null?null:Util::time($through),
            'calculation_version'=>count($versions)===1?array_key_first($versions):($versions?'mixed':'unavailable'),
            'coverage_percent'=>$expected>0?min(100,round(100*$active/$expected,2)):0,'series'=>array_map([self::class,'point'],$rows)];
    }
}
