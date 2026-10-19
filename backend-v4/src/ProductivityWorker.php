<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Recalcula los dias pendientes (rollup_days.dirty) de episode_daily, day_summary, focus_daily y cumplimiento.
 *
 * Cada dia (cliente + fecha) se calcula en dos pasos (2026-10-19, deadlocks con los envios de los equipos):
 *  1. Preparar, SIN transaccion ni bloqueos: lee la revision del dia y arma en tablas temporales todo lo que sale de los
 *     episodios (la parte lenta: segmentos, rachas, cumplimiento, constancia).
 *  2. Escribir, en una transaccion corta: borra y reescribe el dia desde esas tablas y lo marca limpio solo si su
 *     input_revision sigue siendo la leida en el paso 1 (compare-and-set). Si entro un envio mientras tanto, lo escrito
 *     queda (es un calculo completo del dia con los datos leidos) pero el dia sigue pendiente y se recalcula despues.
 * Orden de bloqueos igual al del envio de un equipo: primero la fila de tenants (el sync la toma al autenticar,
 * Auth::deviceContext ... FOR UPDATE) y al final la de rollup_days (el sync la toma al marcar dias). Antes el calculo tomaba
 * rollup_days al empezar y tenants al insertar (llave foranea de episode_daily): orden inverso, deadlock 1213.
 * Un fallo en el paso 2 deshace solo ese dia; los dias ya escritos en la misma corrida quedan confirmados.
 */
final class ProductivityWorker
{
    public const VERSION='activity-b1-1';
    /** @param ?\Closure $probe solo para pruebas: se llama con ('prepared'|'written', tenant, day). */
    public function __construct(private Database $db, private ?\Closure $probe = null) {}

    public function run(?string $tenant=null, int $maxDays=5, int $seconds=20): array
    {
        if ($maxDays<1 || $maxDays>366 || $seconds<1 || $seconds>300) { throw new \InvalidArgumentException('Invalid work budget'); }
        $start=microtime(true); $done=0; $requeued=0;
        $tenants=array_column($this->db->run('SELECT tenant_id FROM tenants WHERE deleted_at IS NULL'.($tenant===null?'':' AND tenant_id=?'),$tenant===null?[]:[Util::bin($tenant)])->fetchAll(),'tenant_id');
        foreach ($tenants as $t) { $this->db->run("INSERT INTO productivity_cron_status (tenant_id,last_run,status) VALUES (?,UTC_TIMESTAMP(6),'running') ON DUPLICATE KEY UPDATE last_run=VALUES(last_run),status='running'",[$t]); }
        $this->db->run('SET SESSION innodb_lock_wait_timeout=2');
        try {
        // Existing/imported aggregates outside raw retention must never be replaced with empty data.
        while ($done<$maxDays && microtime(true)-$start<$seconds) {
            $row=$this->db->one('SELECT r.tenant_id,r.day FROM rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id WHERE r.dirty=TRUE AND r.day>=UTC_DATE()-INTERVAL s.raw_days DAY AND r.day>=DATE_SUB(UTC_DATE(),INTERVAL s.aggregate_months MONTH)'.($tenant===null?'':' AND r.tenant_id=?').' ORDER BY r.day,r.tenant_id LIMIT 1',$tenant===null?[]:[Util::bin($tenant)]);
            if (!$row) { break; }
            $lock='rollup:'.bin2hex($row['tenant_id']).':'.$row['day'];
            if (!(int)$this->db->one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']) { break; }
            try { if ($this->calculate($row['tenant_id'],$row['day'])===false) { $requeued++; } $done++; }
            finally { $this->db->run('SELECT RELEASE_LOCK(?)',[$lock]); }
        }
            foreach ($tenants as $t) { $this->db->run("UPDATE productivity_cron_status SET status='ok',last_success=UTC_TIMESTAMP(6),last_error=NULL WHERE tenant_id=?",[$t]); }
            // requeued: dias recalculados que siguen pendientes porque llego un envio durante el calculo.
            return ['days'=>$done,'requeued'=>$requeued,'elapsed_seconds'=>round(microtime(true)-$start,3),'calculation_version'=>self::VERSION];
        } catch (\Throwable $e) {
            foreach ($tenants as $t) { $this->db->run("UPDATE productivity_cron_status SET status='failed',last_error=? WHERE tenant_id=?",[mb_substr($e->getMessage(),0,1000),$t]); }
            throw $e;
        }
    }

    /** true: dia recalculado y limpio; false: recalculado pero sigue pendiente (llego un envio); null: ya estaba limpio. */
    private function calculate(string $tenant,string $day): ?bool
    {
        // Sin bloqueo: la revision se lee ANTES que los episodios. Un envio confirma episodios y revision juntos, asi que
        // cualquier episodio que el calculo no alcance a ver deja input_revision por encima de la leida.
        $checkpoint=$this->db->one('SELECT dirty,input_revision,UTC_TIMESTAMP(6) through_at FROM rollup_days WHERE tenant_id=? AND day=?',[$tenant,$day]);
        if (!$checkpoint || !$checkpoint['dirty']) { return null; }
        $revision=(int)$checkpoint['input_revision']; $through=$checkpoint['through_at'];
        try {
            [$occupied,$compliance]=$this->prepare($tenant,$day);
            $this->probe('prepared',$tenant,$day);
            // innodb_lock_wait_timeout=2: si un envio tiene tomada la fila de tenants mas de eso, se reintenta la escritura
            // (las tablas temporales siguen validas y el compare-and-set cubre lo que haya llegado mientras).
            for ($attempt=1; ; $attempt++) {
                try { return $this->db->transaction(fn (): bool => $this->write($tenant,$day,$revision,$through,$occupied,$compliance)); }
                catch (\PDOException $e) {
                    if ($attempt>=3 || (int)($e->errorInfo[1]??0)!==1205) { throw $e; }
                    usleep(random_int(50000,200000)*$attempt);
                }
            }
        } finally {
            foreach (['activity_segments','activity_runs','activity_focus','activity_calendar'] as $t) { $this->db->run("DROP TEMPORARY TABLE IF EXISTS $t"); }
        }
    }

    /** Paso 1: solo lecturas de tablas reales (lecturas consistentes, sin bloqueos en READ COMMITTED) y tablas temporales. */
    private function prepare(string $tenant,string $day): array
    {
        $next=(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
        ActivityCalendar::create($this->db,$tenant,$day,$next);
        foreach (['activity_segments','activity_runs','activity_focus'] as $t) { $this->db->run("DROP TEMPORARY TABLE IF EXISTS $t"); }
        // Broad UTC date bounds prune partitions; exact local bounds split midnight episodes.
        $this->db->run("CREATE TEMPORARY TABLE activity_segments ENGINE=InnoDB AS
            SELECT x.*,FLOOR(active_seconds*b/duration)-FLOOR(active_seconds*a/duration) active,
              FLOOR(idle_seconds*b/duration)-FLOOR(idle_seconds*a/duration) idle,
              COALESCE(FLOOR(call_seconds*FLOOR(active_seconds*b/duration)/NULLIF(active_seconds,0))-FLOOR(call_seconds*FLOOR(active_seconds*a/duration)/NULLIF(active_seconds,0)),0) calls
            FROM (SELECT e.user_id,e.user_assignment_id,e.device_id,e.event_id,e.process_name,
              -- Sistema (Keeper, pantalla de bloqueo) es de la base de la plataforma y manda sobre empresa y excepciones.
              IF(kp.category='system','system',COALESCE(op.category,oa.category,NULLIF(kt.category,'unclassified'),NULLIF(kp.category,'unclassified'),
                IF(e.site IS NULL,NULL,COALESCE(NULLIF(bt.category,'unclassified'),NULLIF(bp.category,'unclassified'))),'unclassified')) category,e.active_seconds,e.idle_seconds,e.call_seconds,
              GREATEST(e.started_at,c.starts_at) first_at,LEAST(e.ended_at,c.ends_at) last_at,
              TIMESTAMPDIFF(MICROSECOND,e.started_at,e.ended_at) duration,
              TIMESTAMPDIFF(MICROSECOND,e.started_at,GREATEST(e.started_at,c.starts_at)) a,
              TIMESTAMPDIFF(MICROSECOND,e.started_at,LEAST(e.ended_at,c.ends_at)) b
            FROM episodes e FORCE INDEX (episode_rollup_day) JOIN user_assignments u ON u.tenant_id=e.tenant_id AND u.id=e.user_assignment_id
            JOIN activity_calendar c ON c.schedule_id=COALESCE(u.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=u.tenant_id))
            LEFT JOIN app_classification_overrides op ON op.tenant_id=e.tenant_id AND op.position_id=u.position_id AND op.process_name=IF(e.site IS NULL,e.process_name,CONCAT('site:',e.site))
            LEFT JOIN app_classification_overrides oa ON oa.tenant_id=e.tenant_id AND oa.area_id=u.area_id AND oa.process_name=IF(e.site IS NULL,e.process_name,CONCAT('site:',e.site))
            LEFT JOIN app_classification kt ON kt.tenant_id=e.tenant_id AND kt.process_name=IF(e.site IS NULL,e.process_name,CONCAT('site:',e.site))
            LEFT JOIN app_classification kp ON kp.tenant_id=0x00000000000040008000000000000001 AND kp.process_name=IF(e.site IS NULL,e.process_name,CONCAT('site:',e.site))
            LEFT JOIN app_classification bt ON bt.tenant_id=e.tenant_id AND bt.process_name=e.process_name
            LEFT JOIN app_classification bp ON bp.tenant_id=0x00000000000040008000000000000001 AND bp.process_name=e.process_name
            WHERE e.tenant_id=? AND e.event_date BETWEEN DATE_SUB(?,INTERVAL 2 DAY) AND DATE_ADD(?,INTERVAL 1 DAY)
              AND e.started_at<c.ends_at AND e.ended_at>c.starts_at) x",[$tenant,$day,$day]);
        // Las ventanas de sistema (0056) no cuentan en ninguna medida de ventanas: ni uso por app, ni productividad,
        // distraccion o sin clasificar, ni rachas de focus, cambios de contexto o constancia. Se quitan aqui para que
        // un vistazo a Keeper entre dos ratos de Word no corte la racha ni cuente dos cambios.
        $this->db->run("DELETE FROM activity_segments WHERE category='system'");
        $this->db->run("CREATE TEMPORARY TABLE activity_runs ENGINE=InnoDB AS
            SELECT z.*,SUM(new_run) OVER (PARTITION BY user_id,user_assignment_id,device_id ORDER BY first_at,event_id ROWS UNBOUNDED PRECEDING) run_id
            FROM (SELECT y.*,IF(previous_category IS NULL OR previous_category<>'productive' OR category<>'productive' OR previous_end<first_at-INTERVAL 120 SECOND OR idle>120 OR COALESCE(previous_idle,0)>120,1,0) new_run,
            IF(previous_process IS NOT NULL AND previous_process<>process_name,1,0) switched
            FROM (SELECT s.*,LAG(process_name) OVER w previous_process,LAG(category) OVER w previous_category,LAG(last_at) OVER w previous_end,LAG(idle) OVER w previous_idle
              FROM activity_segments s WINDOW w AS (PARTITION BY user_id,user_assignment_id,device_id ORDER BY first_at,event_id)) y) z");
        $this->db->run("CREATE TEMPORARY TABLE activity_focus ENGINE=InnoDB AS
            SELECT user_id,user_assignment_id,SUM(IF(productive>=1500,productive,0)) focus_seconds,SUM(switched) context_switches,
              SUM(IF(productive>=1500,productive,0)) deep_work_seconds,SUM(distraction) distraction_seconds,
              SUM(IF(productive>=1500,1,0)) deep_work_sessions,MAX(productive) longest_focus_streak_seconds
            FROM (SELECT user_id,user_assignment_id,device_id,run_id,SUM(IF(category='productive',active,0)) productive,
              SUM(idle) idle,SUM(switched) switched,SUM(IF(category='unproductive',active,0)) distraction
              FROM activity_runs GROUP BY user_id,user_assignment_id,device_id,run_id) r GROUP BY user_id,user_assignment_id");
        // Cumplimiento lee con el calendario de un dia y lo reemplaza por el de siete; se rehace el de un dia para el paso 2.
        $compliance=(new ComplianceWorker($this->db))->collect($tenant,$day);
        ActivityCalendar::create($this->db,$tenant,$day,$next);
        return [$this->occupied($tenant),$compliance];
    }

    /** Paso 2, dentro de la transaccion. Devuelve si el dia quedo limpio. */
    private function write(string $tenant,string $day,int $revision,string $through,array $occupied,?array $compliance): bool
    {
        // Primer bloqueo de la transaccion: la fila del cliente, en modo compartido (el mismo que piden las llaves foraneas
        // al insertar). Si un envio la tiene, se espera a que termine ANTES de tener ningun otro bloqueo.
        $this->db->run('SELECT tenant_id FROM tenants WHERE tenant_id=? LOCK IN SHARE MODE',[$tenant]);
        foreach (['episode_daily','focus_daily','day_summary'] as $table) { $this->db->run("DELETE FROM $table WHERE tenant_id=? AND day=? AND calculation_version NOT LIKE 'k3-import-%'",[$tenant,$day]); }
        $this->db->run('INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,call_seconds,episode_count,calculation_version,calculated_at,data_through,first_activity,last_activity)
            SELECT ?,?,user_id,user_assignment_id,device_id,process_name,category,SUM(active),SUM(idle),SUM(calls),COUNT(*),?,UTC_TIMESTAMP(6),?,MIN(IF(active>0,first_at,NULL)),MAX(IF(active>0,last_at,NULL)) FROM activity_segments GROUP BY user_id,user_assignment_id,device_id,process_name,category',[$tenant,$day,self::VERSION,$through]);
        $expected=ActivityCalendar::expected('u');
        // Los totales del dia salen del snapshot del agente cuando existe, y de los
        // episodios cuando no. Son dos señales distintas: el snapshot mide interaccion
        // con teclado/raton (es lo que medía keeper_activity_day de K3) y los episodios
        // miden ventanas. Un equipo encendido sin ventana en primer plano no genera
        // episodios pero si actividad, asi que tomar solo episodios perdia ese tiempo.
        // Totales y desglose se toman de la MISMA fuente para que el reparto no pueda
        // sumar mas que su total.
        $this->db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,call_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through,first_activity,last_activity,late_seconds,
              work_hours_active_seconds,work_hours_idle_seconds,lunch_active_seconds,lunch_idle_seconds,after_hours_active_seconds,after_hours_idle_seconds,sample_count,utc_offset_minutes,simulated_seconds,remote_seconds,
              locked_seconds,work_hours_locked_seconds,lunch_locked_seconds,after_hours_locked_seconds,
              window_active_seconds,unproductive_seconds,unclassified_seconds,focus_block_seconds)
            SELECT ?,?,u.user_id,u.id,COALESCE(s.active,e.active,0),COALESCE(s.idle,e.idle,0),COALESCE(s.calls,e.calls,0),COALESCE(e.productive,0),$expected,
            IF($expected=0,0,LEAST(100,100*COALESCE(s.active+s.idle+COALESCE(s.locked,0),e.active+e.idle,0)/NULLIF($expected,0))),100*e.productive/NULLIF(e.active,0),?,c.timezone,UTC_TIMESTAMP(6),?,
            COALESCE(s.first_at,e.first_at),COALESCE(s.last_at,e.last_at),
            IF($expected=0 OR COALESCE(s.first_at,e.first_at) IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,c.work_start,COALESCE(s.first_at,e.first_at)))),
            s.work_active,s.work_idle,s.lunch_active,s.lunch_idle,s.after_active,s.after_idle,s.samples,s.utc_offset,s.simulated,s.remote,s.locked,s.work_locked,s.lunch_locked,s.after_locked,
            e.active,e.unproductive,e.unclassified,COALESCE(fb.blocks,0)
            FROM user_assignments u JOIN activity_calendar c ON c.schedule_id=COALESCE(u.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=u.tenant_id))
            LEFT JOIN (SELECT user_id,SUM(focus_seconds) blocks FROM activity_focus GROUP BY user_id) fb ON fb.user_id=u.user_id
            LEFT JOIN (SELECT user_id,SUM(active) active,SUM(idle) idle,SUM(calls) calls,SUM(IF(category='productive',active,0)) productive,
              SUM(IF(category='unproductive',active,0)) unproductive,SUM(IF(category='unclassified',active,0)) unclassified,
              MIN(IF(active>0,first_at,NULL)) first_at,MAX(IF(active>0,last_at,NULL)) last_at FROM activity_segments GROUP BY user_id) e ON e.user_id=u.user_id
            LEFT JOIN (SELECT user_id,SUM(active_seconds) active,SUM(idle_seconds) idle,SUM(call_seconds) calls,
              MIN(first_activity_at) first_at,MAX(last_activity_at) last_at,SUM(sample_count) samples,MIN(utc_offset_minutes) utc_offset,
              -- Entrada simulada/remota (agente 4.0.22+): SUM ignora NULL; NULL si ningun dispositivo lo reporta.
              SUM(simulated_seconds) simulated,SUM(remote_seconds) remote,
              -- Bloqueado (agente 4.0.24+), igual: NULL si ningun dispositivo lo reporta.
              SUM(locked_seconds) locked,SUM(work_hours_locked_seconds) work_locked,SUM(lunch_locked_seconds) lunch_locked,SUM(after_hours_locked_seconds) after_locked,
              -- Si algun dispositivo no reporta el desglose, el reparto del dia queda
              -- DESCONOCIDO en vez de una suma parcial que aparentaria estar completa.
              IF(COUNT(*)=COUNT(work_hours_active_seconds),SUM(work_hours_active_seconds),NULL) work_active,
              IF(COUNT(*)=COUNT(work_hours_idle_seconds),SUM(work_hours_idle_seconds),NULL) work_idle,
              IF(COUNT(*)=COUNT(lunch_active_seconds),SUM(lunch_active_seconds),NULL) lunch_active,
              IF(COUNT(*)=COUNT(lunch_idle_seconds),SUM(lunch_idle_seconds),NULL) lunch_idle,
              IF(COUNT(*)=COUNT(after_hours_active_seconds),SUM(after_hours_active_seconds),NULL) after_active,
              IF(COUNT(*)=COUNT(after_hours_idle_seconds),SUM(after_hours_idle_seconds),NULL) after_idle
              FROM activity_snapshots WHERE tenant_id=? AND day=? GROUP BY user_id) s ON s.user_id=u.user_id
            WHERE u.tenant_id=? AND u.starts_at<c.ends_at AND (u.ends_at IS NULL OR u.ends_at>c.starts_at)
              -- Un dia = una fila por persona, en la ULTIMA asignacion que toca el dia (misma regla que la importacion
              -- de K3). Antes cada asignacion del dia recibia el total del snapshot y un cambio de ficha duplicaba horas.
              AND NOT EXISTS (SELECT 1 FROM user_assignments later WHERE later.tenant_id=u.tenant_id AND later.user_id=u.user_id AND later.starts_at>u.starts_at AND later.starts_at<c.ends_at)
              AND NOT EXISTS (SELECT 1 FROM day_summary old WHERE old.tenant_id=u.tenant_id AND old.day=c.day AND old.user_id=u.user_id)",[$tenant,$day,self::VERSION,$through,$tenant,$day,$tenant]);
        $this->db->run('INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,context_switches,deep_work_seconds,distraction_seconds,calculation_version,calculated_at,data_through,first_activity_time,scheduled_start,punctuality_minutes,productivity_pct,constancy_pct,deep_work_sessions,longest_focus_streak_seconds)
            SELECT d.tenant_id,d.day,d.user_id,d.user_assignment_id,COALESCE(f.focus_seconds,0),100*COALESCE(f.focus_seconds,0)/NULLIF(d.productive_seconds,0),COALESCE(f.context_switches,0),COALESCE(f.deep_work_seconds,0),COALESCE(f.distraction_seconds,0),?,UTC_TIMESTAMP(6),?,
              TIME(d.first_activity+INTERVAL c.utc_offset SECOND),c.start_local,IF(d.expected_seconds=0,NULL,TIMESTAMPDIFF(MINUTE,d.first_activity,c.work_start)),
              100*d.productive_seconds/NULLIF(d.window_active_seconds,0),NULL,COALESCE(f.deep_work_sessions,0),COALESCE(f.longest_focus_streak_seconds,0)
            FROM day_summary d JOIN user_assignments u ON u.tenant_id=d.tenant_id AND u.id=d.user_assignment_id JOIN activity_calendar c ON c.schedule_id=COALESCE(u.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=u.tenant_id))
            LEFT JOIN (SELECT user_id,SUM(focus_seconds) focus_seconds,SUM(context_switches) context_switches,SUM(deep_work_seconds) deep_work_seconds,SUM(distraction_seconds) distraction_seconds,
              SUM(deep_work_sessions) deep_work_sessions,MAX(longest_focus_streak_seconds) longest_focus_streak_seconds FROM activity_focus GROUP BY user_id) f ON f.user_id=d.user_id WHERE d.tenant_id=? AND d.day=?
            AND NOT EXISTS (SELECT 1 FROM focus_daily old WHERE old.tenant_id=d.tenant_id AND old.day=d.day AND old.user_assignment_id=d.user_assignment_id)',[self::VERSION,$through,$tenant,$day]);
        $this->constancy($tenant,$day,$occupied);
        (new ComplianceWorker($this->db))->apply($tenant,$day,$compliance);
        $this->probe('written',$tenant,$day);
        // Ultimo bloqueo: rollup_days, con compare-and-set sobre la revision leida al empezar.
        $clean=$this->db->run('UPDATE rollup_days r JOIN retention_settings s ON s.tenant_id=r.tenant_id SET r.dirty=FALSE,r.calculated_revision=r.input_revision,r.calculation_version=?,r.calculated_at=UTC_TIMESTAMP(6),r.data_through=?,r.finalized_at=IF(r.day<UTC_DATE()-INTERVAL (s.late_arrival_days+2) DAY,UTC_TIMESTAMP(6),NULL) WHERE r.tenant_id=? AND r.day=? AND r.dirty=TRUE AND r.input_revision=?',[self::VERSION,$through,$tenant,$day,$revision])->rowCount()>0;
        // Llego un envio durante el calculo: lo escrito corresponde a la revision leida y el dia sigue pendiente.
        if (!$clean) { $this->db->run('UPDATE rollup_days SET calculated_revision=GREATEST(calculated_revision,?),calculation_version=?,calculated_at=UTC_TIMESTAMP(6),data_through=? WHERE tenant_id=? AND day=? AND input_revision>=?',[$revision,self::VERSION,$through,$tenant,$day,$revision]); }
        return $clean;
    }

    private function probe(string $point,string $tenant,string $day): void
    {
        if ($this->probe!==null) { ($this->probe)($point,$tenant,$day); }
    }

    /** Bloques de 30 min de la jornada con actividad, por asignacion (lee solo tablas temporales y asignaciones). */
    private function occupied(string $tenant): array
    {
        $occupied=[];
        $rows=$this->db->run('SELECT s.user_assignment_id,s.first_at,s.last_at,c.work_start,c.expected_seconds FROM activity_segments s JOIN user_assignments u ON u.tenant_id=? AND u.id=s.user_assignment_id JOIN activity_calendar c ON c.schedule_id=COALESCE(u.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=u.tenant_id)) WHERE s.active>0',[$tenant]);
        foreach ($rows as $r) {
            $start=strtotime($r['work_start'].' UTC'); $end=$start+(int)$r['expected_seconds'];
            $a=max($start,strtotime($r['first_at'].' UTC')); $b=min($end,strtotime($r['last_at'].' UTC'));
            for ($block=(int)floor(($a-$start)/1800);$a<$b && $start+$block*1800<$b;$block++) { $occupied[bin2hex($r['user_assignment_id'])][$block]=true; }
        }
        return $occupied;
    }

    private function constancy(string $tenant,string $day,array $occupied): void
    {
        foreach ($this->db->run('SELECT user_assignment_id,expected_seconds FROM day_summary WHERE tenant_id=? AND day=?',[$tenant,$day])->fetchAll() as $r) {
            $pct=$r['expected_seconds']>0?round(100*count($occupied[bin2hex($r['user_assignment_id'])]??[])/ceil($r['expected_seconds']/1800),2):null;
            $this->db->run("UPDATE focus_daily SET constancy_pct=? WHERE tenant_id=? AND day=? AND user_assignment_id=? AND calculation_version NOT LIKE 'k3-import-%'",[$pct,$tenant,$day,$r['user_assignment_id']]);
        }
    }
}
