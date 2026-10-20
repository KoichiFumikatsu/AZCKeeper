<?php
declare(strict_types=1);
namespace Keeper;

final class ActivityCalendar
{
    public static function scheduledStart(string $assignment='a'): string
    {
        return "IF(EXISTS(SELECT 1 FROM tenants t LEFT JOIN schedules s ON s.tenant_id=t.tenant_id AND s.id=$assignment.schedule_id WHERE t.tenant_id=$assignment.tenant_id AND t.default_schedule_id IS NOT NULL AND (s.placeholder=TRUE OR s.id IS NULL)),c.start_local,COALESCE(f.scheduled_start,c.start_local))";
    }
    public static function expected(string $assignment='a', string $calendar='c'): string
    {
        // Festivo: del calendario efectivo de la persona (el suyo, el del pais de su sede o el por defecto); los festivos
        // viejos sin calendario siguen la regla anterior (toda la empresa o las sociedades marcadas).
        return "IF(EXISTS (SELECT 1 FROM holidays h WHERE h.tenant_id=$assignment.tenant_id AND h.day=$calendar.day AND (h.calendar_id=".HolidayCalendars::effective($assignment)." OR h.calendar_id IS NULL AND (NOT EXISTS (SELECT 1 FROM holiday_society_links hl WHERE hl.tenant_id=h.tenant_id AND hl.holiday_id=h.id) OR EXISTS (SELECT 1 FROM holiday_society_links hl WHERE hl.tenant_id=h.tenant_id AND hl.holiday_id=h.id AND hl.society_id=$assignment.sociedad_id))))"
            // Ausencia aprobada (vacaciones, incapacidad...) que cubre la jornada: el dia no se espera trabajo.
            ." OR EXISTS (SELECT 1 FROM absences ab WHERE ab.tenant_id=$assignment.tenant_id AND ab.user_id=$assignment.user_id AND ab.status='approved' AND ab.starts_at<=$calendar.work_start AND ab.ends_at>=$calendar.work_start+INTERVAL $calendar.expected_seconds SECOND),0,$calendar.expected_seconds)";
    }
    /**
     * Jornada (J) de las formulas de productividad y focus (decision 2026-10-09), en segundos, para una asignacion y un
     * dia del calendario: la franja del horario MENOS el almuerzo y MENOS las ausencias aprobadas que la tocan (tambien
     * las de unas horas). Festivo, dia sin franja o ausencia que cubre la jornada: 0 (el dia queda fuera del periodo).
     * Solo cuenta la parte de la franja que ya paso: hoy pesa hasta ahora y un dia futuro pesa 0; si no, a las 10 a. m.
     * la jornada completa del dia ya contaria en contra.
     * Dos ausencias que se pisan restan dos veces su cruce (GREATEST deja el dia en 0, nunca negativo).
     */
    public static function workday(string $assignment='a', string $calendar='c'): string
    {
        $c=$calendar; $start="$c.work_start"; $cut="LEAST($c.work_start+INTERVAL $c.expected_seconds SECOND,UTC_TIMESTAMP(6))";
        $overlap=static fn(string $s,string $e,string $from,string $to)=>"GREATEST(0,TIMESTAMPDIFF(SECOND,GREATEST($s,$from),LEAST($e,$to)))";
        $lunch="IF($c.lunch_start IS NULL,0,".$overlap("$c.lunch_start","$c.lunch_end",$start,$cut).')';
        // La parte de una ausencia que cae en el almuerzo ya esta descontada con el almuerzo.
        $absent="COALESCE((SELECT SUM(".$overlap('ab.starts_at','ab.ends_at',$start,$cut)."-IF($c.lunch_start IS NULL,0,".$overlap('ab.starts_at','ab.ends_at',"GREATEST($start,$c.lunch_start)","LEAST($cut,$c.lunch_end)").'))'
            ." FROM absences ab WHERE ab.tenant_id=$assignment.tenant_id AND ab.user_id=$assignment.user_id AND ab.status='approved' AND ab.starts_at<$cut AND ab.ends_at>$start),0)";
        return 'IF('.self::expected($assignment,$calendar)."=0,0,GREATEST(0,TIMESTAMPDIFF(SECOND,$start,$cut)-$lunch-$absent))";
    }
    /** Tabla temporal vacia del calendario (DDL: fuera de una transaccion de solo lectura). */
    public static function table(Database $db): void
    {
        $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
        $db->run('CREATE TEMPORARY TABLE activity_calendar (schedule_id BINARY(16),day DATE,timezone VARCHAR(64),starts_at DATETIME(6),ends_at DATETIME(6),work_start DATETIME(6),expected_seconds INT,utc_offset INT,start_local TIME,lunch_start DATETIME(6) NULL,lunch_end DATETIME(6) NULL,PRIMARY KEY(schedule_id,day)) ENGINE=InnoDB');
    }
    /** $reuse: la tabla ya existe (ProductivityFormula::prepare) y solo se vacia y llena, sin DDL. */
    public static function create(Database $db, string $tenant, string $from, string $to, bool $reuse=false): void
    {
        if ($reuse) { $db->run('DELETE FROM activity_calendar'); } else { self::table($db); }
        $schedules=$db->run('SELECT s.id,COALESCE(d.id,s.id) effective_id,COALESCE(d.timezone,s.timezone) timezone,COALESCE(d.start_local,s.start_local) start_local,COALESCE(d.end_local,s.end_local) end_local,
            IF(d.id IS NULL,s.lunch_start_local,d.lunch_start_local) lunch_start_local,IF(d.id IS NULL,s.lunch_end_local,d.lunch_end_local) lunch_end_local
            FROM schedules s JOIN tenants t ON t.tenant_id=s.tenant_id LEFT JOIN schedules d ON d.tenant_id=t.tenant_id AND d.id=t.default_schedule_id AND s.placeholder=TRUE WHERE s.tenant_id=?',[$tenant])->fetchAll();
        $days=[];
        foreach ($db->run('SELECT schedule_id,weekday,start_local,end_local,lunch_start_local,lunch_end_local FROM schedule_days WHERE tenant_id=?',[$tenant])->fetchAll() as $d) { $days[$d['schedule_id']][(int)$d['weekday']]=$d; }
        $sql=static fn(\DateTimeImmutable $d)=>$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $rows=[];
        // Por lotes: un periodo de un ano con varios horarios eran miles de INSERT de una fila.
        $flush=static function () use ($db,&$rows): void {
            if (!$rows) { return; }
            $db->run('INSERT INTO activity_calendar VALUES '.implode(',',array_fill(0,count($rows),'(?,?,?,?,?,?,?,?,?,?,?)')),array_merge(...$rows));
            $rows=[];
        };
        foreach ($schedules as $s) {
            $zone=new \DateTimeZone($s['timezone']);
            for ($day=new \DateTimeImmutable($from,$zone); $day->format('Y-m-d')<$to; $day=$day->modify('+1 day')) {
                // Un dia con franja propia (p. ej. sabado medio dia) usa la suya, con su almuerzo; los demas, la del horario.
                $own=$days[$s['effective_id']][(int)$day->format('N')]??null;
                $startLocal=$own['start_local']??$s['start_local']; $endLocal=$own['end_local']??$s['end_local'];
                $ownHours=$own!==null && $own['start_local']!==null;
                $lunchStart=$ownHours?$own['lunch_start_local']:$s['lunch_start_local']; $lunchEnd=$ownHours?$own['lunch_end_local']:$s['lunch_end_local'];
                $start=new \DateTimeImmutable($day->format('Y-m-d').' '.$startLocal,$zone);
                $end=new \DateTimeImmutable($day->format('Y-m-d').' '.$endLocal,$zone);
                if ($end<=$start) { $end=$end->modify('+1 day'); }
                $lunch=[null,null];
                if ($lunchStart!==null && $lunchEnd!==null) {
                    $ls=new \DateTimeImmutable($day->format('Y-m-d').' '.$lunchStart,$zone); $le=new \DateTimeImmutable($day->format('Y-m-d').' '.$lunchEnd,$zone);
                    // Jornada nocturna: un almuerzo antes de la entrada es del dia siguiente.
                    if ($ls<$start) { $ls=$ls->modify('+1 day'); $le=$le->modify('+1 day'); }
                    if ($le>$ls) { $lunch=[$sql($ls),$sql($le)]; }
                }
                $work=$own!==null;
                $rows[]=[$s['id'],$day->format('Y-m-d'),$s['timezone'],$sql($day),$sql($day->modify('+1 day')),$sql($start),$work?$end->getTimestamp()-$start->getTimestamp():0,$day->getOffset(),$startLocal,...$lunch];
                if (count($rows)>=200) { $flush(); }
            }
        }
        $flush();
    }

    public static function refresh(Database $db,string $tenant,array $days): void
    {
        foreach (array_unique($days) as $day) {
            self::create($db,$tenant,$day,(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
            try {
                $expected=self::expected('a');
                $start=self::scheduledStart();
                $db->run("UPDATE day_summary d JOIN user_assignments a ON a.tenant_id=d.tenant_id AND a.id=d.user_assignment_id JOIN activity_calendar c ON c.schedule_id=COALESCE(a.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=a.tenant_id)) AND c.day=d.day
                    SET d.expected_seconds=$expected,d.coverage_percent=IF($expected=0,0,LEAST(100,100*(d.active_seconds+d.idle_seconds+COALESCE(d.locked_seconds,0))/NULLIF($expected,0))),
                    d.late_seconds=IF($expected=0 OR d.first_activity IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,c.work_start,d.first_activity))) WHERE d.tenant_id=? AND d.day=?",[$tenant,$day]);
                $db->run("UPDATE focus_daily f JOIN user_assignments a ON a.tenant_id=f.tenant_id AND a.id=f.user_assignment_id JOIN activity_calendar c ON c.schedule_id=COALESCE(a.schedule_id,(SELECT default_schedule_id FROM tenants WHERE tenant_id=a.tenant_id)) AND c.day=f.day
                    SET f.scheduled_start=$start,f.punctuality_minutes=IF($expected=0 OR f.first_activity_time IS NULL,NULL,TIMESTAMPDIFF(MINUTE,TIMESTAMP(f.day,f.first_activity_time),TIMESTAMP(f.day,$start))) WHERE f.tenant_id=? AND f.day=?",[$tenant,$day]);
            } finally { $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
        }
    }
}
