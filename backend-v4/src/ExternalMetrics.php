<?php
declare(strict_types=1);
namespace Keeper;

final class ExternalMetrics
{
    private bool $built = false;
    public function __construct(private Database $db, private array $c, private string $from, private string $to, private array $filters) {}

    public function rows(string $group = 'day', ?array $members = null): array
    {
        $column = match ($group) { 'day'=>'x.day', 'user'=>'x.user_id', 'area'=>'x.area_id', 'total'=>"'total'" };
        $where=''; $args=[];
        if ($members!==null) {
            if (!$members) { return []; }
            $where=' WHERE x.user_id IN ('.implode(',',array_fill(0,count($members),'?')).')'; $args=$members;
        }
        // Una fila por (dia, persona) del calendario, con los dias laborables sin actividad en cero (ProductivityFormula);
        // se arma una vez por consulta a la API y cada agrupacion la lee.
        if (!$this->built) {
            $filter=''; $fargs=[];
            foreach ($this->filters as $field=>$id) { $filter.=ExternalApi::filterSql('a',$field); $fargs[]=$id; }
            ProductivityFormula::facts($this->db,$this->c['tenant_id'],$this->from,$this->to,$filter,$fargs,true);
            $this->built=true;
        }
        return $this->db->run("SELECT $column bucket,".ProductivityFormula::sums()." FROM workday_facts x$where GROUP BY $column ORDER BY $column",$args)->fetchAll();
    }
    public static function percent(array $row): ?float { return ProductivityFormula::percents($row)['productivity_percent']; }
    public static function score(?array $row): ?float { return $row===null?null:ProductivityFormula::percents($row)['focus_score']; }
    public static function point(array $row): array { return ProductivityFormula::point($row,$row['bucket']); }
    public function report(?array $members = null): array
    {
        $rows=$this->rows('day',$members); $versions=[]; $through=null; $calculated=null; $active=0; $expected=0;
        foreach ($rows as $row) {
            if (!$row['measured']) { continue; }
            foreach (explode(',',$row['versions'].','.($row['focus_versions']??'')) as $version) { if ($version!=='') { $versions[$version]=true; } }
            $through=$through===null?$row['data_through']:min($through,$row['data_through']);
            $calculated=$calculated===null?$row['calculated_at']:min($calculated,$row['calculated_at']);
            $active+=(int)$row['summary_active_seconds']; $expected+=(int)$row['summary_expected_seconds'];
        }
        $period=array_fill_keys(ProductivityFormula::FIELDS,0);
        foreach ($rows as $row) { foreach (ProductivityFormula::FIELDS as $field) { $period[$field]+=(int)$row[$field]; } }
        return ['tenant_id'=>Util::id($this->c['tenant_id']),'from'=>$this->from,'to'=>$this->to,'timezone'=>$this->c['timezone'],
            'calculated_at'=>$calculated===null?null:Util::time($calculated),'data_through'=>$through===null?null:Util::time($through),
            'calculation_version'=>count($versions)===1?array_key_first($versions):($versions?'mixed':'unavailable'),
            'coverage_percent'=>$expected>0?min(100,round(100*$active/$expected,2)):0]+ProductivityFormula::percents($period)+$period+['series'=>array_map([self::class,'point'],$rows)];
    }
}
