<?php
declare(strict_types=1);
namespace Keeper;

final class ActivityCalendar
{
    public static function expected(string $assignment='a', string $calendar='c'): string
    {
        // Festivo: del calendario efectivo de la persona (el suyo, el del pais de su sede o el por defecto); los festivos
        // viejos sin calendario siguen la regla anterior (toda la empresa o las sociedades marcadas).
        return "IF(EXISTS (SELECT 1 FROM holidays h WHERE h.tenant_id=$assignment.tenant_id AND h.day=$calendar.day AND (h.calendar_id=".HolidayCalendars::effective($assignment)." OR h.calendar_id IS NULL AND (NOT EXISTS (SELECT 1 FROM holiday_society_links hl WHERE hl.tenant_id=h.tenant_id AND hl.holiday_id=h.id) OR EXISTS (SELECT 1 FROM holiday_society_links hl WHERE hl.tenant_id=h.tenant_id AND hl.holiday_id=h.id AND hl.society_id=$assignment.sociedad_id))))"
            // Ausencia aprobada (vacaciones, incapacidad...) que cubre la jornada: el dia no se espera trabajo.
            ." OR EXISTS (SELECT 1 FROM absences ab WHERE ab.tenant_id=$assignment.tenant_id AND ab.user_id=$assignment.user_id AND ab.status='approved' AND ab.starts_at<=$calendar.work_start AND ab.ends_at>=$calendar.work_start+INTERVAL $calendar.expected_seconds SECOND),0,$calendar.expected_seconds)";
    }
    public static function create(Database $db, string $tenant, string $from, string $to): void
    {
        $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
        $db->run('CREATE TEMPORARY TABLE activity_calendar (schedule_id BINARY(16),day DATE,timezone VARCHAR(64),starts_at DATETIME(6),ends_at DATETIME(6),work_start DATETIME(6),expected_seconds INT,utc_offset INT,start_local TIME,PRIMARY KEY(schedule_id,day)) ENGINE=InnoDB');
        $schedules=$db->run('SELECT s.id,s.timezone,s.start_local,s.end_local FROM schedules s WHERE s.tenant_id=?',[$tenant])->fetchAll();
        $days=[];
        foreach ($db->run('SELECT schedule_id,weekday,start_local,end_local FROM schedule_days WHERE tenant_id=?',[$tenant])->fetchAll() as $d) { $days[$d['schedule_id']][(int)$d['weekday']]=$d; }
        foreach ($schedules as $s) {
            $zone=new \DateTimeZone($s['timezone']);
            for ($day=new \DateTimeImmutable($from,$zone); $day->format('Y-m-d')<$to; $day=$day->modify('+1 day')) {
                // Un dia con franja propia (p. ej. sabado medio dia) usa la suya; los demas, la del horario.
                $own=$days[$s['id']][(int)$day->format('N')]??null;
                $startLocal=$own['start_local']??$s['start_local']; $endLocal=$own['end_local']??$s['end_local'];
                $start=new \DateTimeImmutable($day->format('Y-m-d').' '.$startLocal,$zone);
                $end=new \DateTimeImmutable($day->format('Y-m-d').' '.$endLocal,$zone);
                if ($end<=$start) { $end=$end->modify('+1 day'); }
                $work=$own!==null;
                $sql=static fn(\DateTimeImmutable $d)=>$d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
                $db->run('INSERT INTO activity_calendar VALUES (?,?,?,?,?,?,?,?,?)',[$s['id'],$day->format('Y-m-d'),$s['timezone'],$sql($day),$sql($day->modify('+1 day')),$sql($start),$work?$end->getTimestamp()-$start->getTimestamp():0,$day->getOffset(),$startLocal]);
            }
        }
    }

    public static function refresh(Database $db,string $tenant,array $days): void
    {
        foreach (array_unique($days) as $day) {
            self::create($db,$tenant,$day,(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
            try {
                $expected=self::expected('a');
                $db->run("UPDATE day_summary d JOIN user_assignments a ON a.tenant_id=d.tenant_id AND a.id=d.user_assignment_id JOIN activity_calendar c ON c.schedule_id=a.schedule_id AND c.day=d.day
                    SET d.expected_seconds=$expected,d.coverage_percent=IF($expected=0,0,LEAST(100,100*(d.active_seconds+d.idle_seconds+COALESCE(d.locked_seconds,0))/NULLIF($expected,0))),
                    d.late_seconds=IF($expected=0 OR d.first_activity IS NULL,NULL,GREATEST(0,TIMESTAMPDIFF(SECOND,c.work_start,d.first_activity))) WHERE d.tenant_id=? AND d.day=?",[$tenant,$day]);
                $db->run("UPDATE focus_daily f JOIN user_assignments a ON a.tenant_id=f.tenant_id AND a.id=f.user_assignment_id JOIN activity_calendar c ON c.schedule_id=a.schedule_id AND c.day=f.day
                    SET f.punctuality_minutes=IF($expected=0 OR f.first_activity_time IS NULL,NULL,TIMESTAMPDIFF(MINUTE,TIMESTAMP(f.day,f.first_activity_time),TIMESTAMP(f.day,COALESCE(f.scheduled_start,c.start_local)))) WHERE f.tenant_id=? AND f.day=?",[$tenant,$day]);
            } finally { $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar'); }
        }
    }
}
