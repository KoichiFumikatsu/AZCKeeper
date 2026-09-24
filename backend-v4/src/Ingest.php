<?php
declare(strict_types=1);
namespace Keeper;

final class Ingest
{
    public function __construct(private Database $db, private array $c, private Request $r) {}
    public static function ack(string $id, string $status = 'accepted', ?string $code = null): array
    {
        $a = ['event_id' => $id, 'status' => $status, 'retryable' => false];
        if ($code !== null) { $a['code'] = $code; }
        return $a;
    }
    public static function unique(array $events, string $field = 'event_id'): void
    {
        $ids = [];
        foreach ($events as $event) {
            $id = strtolower($event->$field);
            if (isset($ids[$id])) { throw new ApiError(422, 'validation_failed'); }
            $ids[$id] = true;
        }
    }
    public function partial(object $e, callable $fn, string $field = 'event_id'): array
    {
        $this->db->run('SAVEPOINT ingest_event');
        try { $ack = $fn(); $this->db->run('RELEASE SAVEPOINT ingest_event'); return $ack; }
        catch (ApiError $error) {
            if ($error->status >= 500) { throw $error; }
            $this->db->run('ROLLBACK TO SAVEPOINT ingest_event');
            $this->db->run('RELEASE SAVEPOINT ingest_event');
            return self::ack($e->$field, 'rejected', $error->errorCode);
        }
    }
    private function args(string $id): array { return [$this->c['tenant_id'], $this->c['device_id'], Util::bin($id)]; }
    private function duplicate(string $table, object $e, string $field = 'event_id'): ?array
    {
        $row = $this->db->one("SELECT body_hash FROM $table WHERE tenant_id=? AND device_id=? AND $field=?", $this->args($e->$field));
        if (!$row) { return null; }
        $same = hash_equals($row['body_hash'], Util::hash($e));
        return self::ack($e->$field, $same ? 'duplicate' : 'rejected', $same ? null : 'event_conflict');
    }
    private function write(string $sql, array $args, string $conflict = 'event_conflict'): void
    {
        try { $this->db->run($sql, $args); }
        catch (\PDOException $error) {
            if ($error->getCode() !== '23000' || (int) ($error->errorInfo[1] ?? 0) !== 1062) { throw $error; }
            throw new ApiError(409, $conflict);
        }
    }
    private function range(string $at): void
    {
        if (strtotime($at) > time() + 300) { throw new ApiError(422, 'invalid_range'); }
    }
    private function markDays(string $start, string $end): void
    {
        $tz = new \DateTimeZone($this->c['timezone']);
        $a = (new \DateTimeImmutable($start))->setTimezone($tz)->setTime(0, 0);
        $b = (new \DateTimeImmutable($end))->modify('-1 microsecond')->setTimezone($tz)->format('Y-m-d');
        do {
            $this->db->run('INSERT INTO rollup_days (tenant_id,day,dirty,input_revision) VALUES (?,?,TRUE,1) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$this->c['tenant_id'], $a->format('Y-m-d')]);
            $a = $a->modify('+1 day');
        } while ($a->format('Y-m-d') <= $b);
    }
    public function episode(object $e): array
    {
        if ($ack = $this->duplicate('episode_ingest_keys', $e)) { return $ack; }
        $a = new \DateTimeImmutable($e->started_at); $b = new \DateTimeImmutable($e->ended_at);
        $seconds = (float) $b->format('U.u') - (float) $a->format('U.u');
        if ($seconds <= 0 || $seconds > 86400 || $e->active_seconds + $e->idle_seconds > floor($seconds) || ($e->call_seconds??0)>$e->active_seconds) { throw new ApiError(422, 'invalid_range'); }
        $this->range($e->ended_at);
        $settings = $this->db->one('SELECT late_arrival_days FROM retention_settings WHERE tenant_id=? LOCK IN SHARE MODE', [$this->c['tenant_id']]);
        if (!$settings) { throw new ApiError(503, 'temporarily_unavailable'); }
        if ($a->getTimestamp() < time() - (int) $settings['late_arrival_days'] * 86400) { throw new ApiError(422, 'event_too_old'); }
        $start = Util::sqlTime($e->started_at); $end = Util::sqlTime($e->ended_at);
        if ($start < $this->c['assignment_start'] || ($this->c['assignment_end'] !== null && $end > $this->c['assignment_end'])) { throw new ApiError(422, 'assignment_conflict'); }
        $assignment = $this->db->one('SELECT a.id,s.timezone FROM user_assignments a JOIN schedules s ON s.tenant_id=a.tenant_id AND s.id=a.schedule_id WHERE a.tenant_id=? AND a.user_id=? AND a.starts_at<=? AND (a.ends_at IS NULL OR a.ends_at>=?) LOCK IN SHARE MODE', [$this->c['tenant_id'], $this->c['user_id'], $start, $end]);
        if (!$assignment) { throw new ApiError(422, 'assignment_conflict'); }
        // The episode trigger writes the global dedupe ledger atomically with the raw event.
        $this->write('INSERT INTO episodes (tenant_id,device_id,event_id,user_id,device_assignment_id,user_assignment_id,event_date,started_at,ended_at,process_name,window_title,active_seconds,idle_seconds,call_seconds,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,NULL,?,?,?,?)', [...$this->args($e->event_id), $this->c['user_id'], $this->c['assignment_id'], $assignment['id'], substr($start, 0, 10), $start, $end, $e->process_name, $e->active_seconds, $e->idle_seconds, $e->call_seconds??0, Util::hash($e)]);
        $this->c['timezone']=$assignment['timezone'];
        $this->markDays($e->started_at, $e->ended_at);
        return self::ack($e->event_id);
    }
    public function log(object $e): array
    {
        if ($ack = $this->duplicate('client_logs', $e)) { return $ack; }
        $this->range($e->at);
        $f = $e->fields ?? (object) [];
        foreach ([$e->code, $e->component, $f->control_id ?? null, $f->error_code ?? null] as $code) {
            if ($code !== null && (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.:-]{0,99}$/D', $code) || preg_match('/(?:password|secret|token|bearer|authorization|(?:^|[_.:-])pin(?:$|[_.:-]))/i', $code))) { throw new ApiError(422, 'validation_failed'); }
        }
        if (isset($f->command_id)) { $this->ownCommand($f->command_id); }
        $this->write('INSERT INTO client_logs (tenant_id,device_id,event_id,at,level,code,component,control_id,command_id,attempt,error_code,body_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [...$this->args($e->event_id), Util::sqlTime($e->at), $e->level, $e->code, $e->component, $f->control_id ?? null, isset($f->command_id) ? Util::bin($f->command_id) : null, $f->attempt ?? null, $f->error_code ?? null, Util::hash($e)]);
        return self::ack($e->event_id);
    }
    public function security(object $e): array
    {
        $this->range($e->observed_at);
        $controls = [];
        foreach ($e->controls as $control) {
            if (isset($controls[$control->control_id])) { throw new ApiError(422, 'validation_failed'); }
            $controls[$control->control_id] = true;
            $this->range($control->observed_at);
        }
        $row = $this->db->one('SELECT * FROM security_reports WHERE tenant_id=? AND device_id=? AND event_id=?', $this->args($e->event_id));
        if ($row) {
            $same = $row['observed_at'] === Util::sqlTime($e->observed_at) && hash_equals($row['report_hash'], hex2bin($e->report_hash)) && Util::canonical(json_decode($row['controls'])) === Util::canonical($e->controls);
            return self::ack($e->event_id, $same ? 'duplicate' : 'rejected', $same ? null : 'event_conflict');
        }
        $this->write('INSERT INTO security_reports (tenant_id,device_id,event_id,observed_at,report_hash,controls) VALUES (?,?,?,?,?,?)', [...$this->args($e->event_id), Util::sqlTime($e->observed_at), hex2bin($e->report_hash), Util::json($e->controls)]);
        (new Audit($this->db))->record($this->c, $this->r, 'security.reported', 'security_report', Util::bin($e->event_id), ['controls']);
        return self::ack($e->event_id);
    }
    private function ownCommand(string $id): array
    {
        $q = $this->db->one('SELECT *,expires_at<=UTC_TIMESTAMP(6) expired FROM device_command WHERE tenant_id=? AND device_id=? AND id=? FOR UPDATE', $this->args($id));
        if (!$q) { throw new ApiError(404, 'resource_not_found'); }
        return $q;
    }
    public function command(string $id, object $e): array
    {
        $command = $this->ownCommand($id);
        $hash = hash('sha256', strtolower($id) . Util::canonical($e), true);
        $row = $this->db->one('SELECT body_hash FROM device_command_results WHERE tenant_id=? AND device_id=? AND event_id=?', $this->args($e->event_id));
        if ($row) {
            if (!hash_equals($row['body_hash'], $hash)) { throw new ApiError(409, 'invalid_transition'); }
            return self::ack($e->event_id, 'duplicate');
        }
        $this->range($e->at);
        $terminal = in_array($e->status, ['succeeded', 'failed'], true);
        $resultCode = $terminal ? ($e->code ?? 'unspecified') : null;
        if (in_array($command['status'], ['succeeded', 'failed'], true)) {
            if ($command['status'] === $e->status && $command['result_code'] === $resultCode && $command['completed_at'] === Util::sqlTime($e->at)) { return self::ack($e->event_id, 'duplicate'); }
            throw new ApiError(409, 'invalid_transition');
        }
        if ($command['expired'] || in_array($command['status'], ['cancelled', 'expired'], true) || Util::sqlTime($e->at) < $command['created_at']) { throw new ApiError(409, 'invalid_transition'); }
        $this->write('INSERT INTO device_command_results (tenant_id,device_id,event_id,command_id,status,at,code,body_hash) VALUES (?,?,?,?,?,?,?,?)', [...$this->args($e->event_id), Util::bin($id), $e->status, Util::sqlTime($e->at), $e->code ?? null, $hash], 'invalid_transition');
        $this->db->run('UPDATE device_command SET status=?,result_code=?,completed_at=? WHERE tenant_id=? AND device_id=? AND id=?', [$e->status, $resultCode, $terminal ? Util::sqlTime($e->at) : null, ...$this->args($id)]);
        (new Audit($this->db))->record($this->c, $this->r, 'command.result', 'command', Util::bin($id), ['status', 'result']);
        return self::ack($e->event_id);
    }
    /** Contadores del desglose que viajan junto a los totales; el orden fija el de las columnas. */
    private const BREAKDOWN = ['call_seconds', 'work_hours_active_seconds', 'work_hours_idle_seconds',
        'lunch_active_seconds', 'lunch_idle_seconds', 'after_hours_active_seconds', 'after_hours_idle_seconds',
        'sample_count', 'utc_offset_minutes'];

    public function activity(object $e): array
    {
        if ($e->active_seconds + $e->idle_seconds > 86400 || $e->day > (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d')) { throw new ApiError(422, 'invalid_range'); }
        // Un dato imposible se rechaza en el borde: las llamadas son un subconjunto del tiempo
        // activo y el desglose no puede sumar mas que su total. K3 no comprobaba ninguna de las
        // dos y por eso tiene dias con 184 horas de llamada.
        if (($e->call_seconds ?? 0) > $e->active_seconds) { throw new ApiError(422, 'call_exceeds_activity'); }
        foreach ([['active_seconds', 'work_hours_active_seconds', 'lunch_active_seconds', 'after_hours_active_seconds'],
                  ['idle_seconds', 'work_hours_idle_seconds', 'lunch_idle_seconds', 'after_hours_idle_seconds']] as $split) {
            $total = array_shift($split);
            $parts = array_map(static fn($f) => $e->$f ?? null, $split);
            if (!in_array(null, $parts, true) && array_sum($parts) > $e->$total + 60) { throw new ApiError(422, 'breakdown_exceeds_total'); }
        }
        $day = new \DateTimeImmutable($e->day, new \DateTimeZone($this->c['timezone']));
        if (Util::sqlTime($day->modify('+1 day')->format(DATE_RFC3339)) <= $this->c['assignment_start']) { throw new ApiError(422, 'assignment_conflict'); }
        $rows = $this->db->run('SELECT * FROM activity_snapshots WHERE tenant_id=? AND device_id=? AND (day=? OR snapshot_id=?) FOR UPDATE', [$this->c['tenant_id'], $this->c['device_id'], $e->day, Util::bin($e->snapshot_id)])->fetchAll();
        $existing = null;
        foreach ($rows as $row) {
            if ($row['day'] !== $e->day) { return self::ack($e->snapshot_id, 'rejected', 'event_conflict'); }
            $existing = $row;
        }
        if ($existing) {
            if ((int) $existing['sequence'] >= $e->sequence) {
                $same = (int) $existing['active_seconds'] === $e->active_seconds && (int) $existing['idle_seconds'] === $e->idle_seconds;
                if ((int) $existing['sequence'] === $e->sequence) { $same = $same && hash_equals($existing['body_hash'], Util::hash($e)); }
                return self::ack($e->snapshot_id, $same ? 'duplicate' : 'rejected', $same ? null : 'event_conflict');
            }
            if ((int) $existing['active_seconds'] > $e->active_seconds || (int) $existing['idle_seconds'] > $e->idle_seconds) { return self::ack($e->snapshot_id, 'rejected', 'invalid_range'); }
        }
        // Desglose horario y marcas del dia. Ausente = DESCONOCIDO, nunca cero: un agente
        // antiguo que no lo reporta no debe aparecer con una jornada de cero segundos.
        $extra = [];
        foreach (self::BREAKDOWN as $field) { $extra[$field] = $e->$field ?? null; }
        $extra['first_activity_at'] = isset($e->first_activity_at) ? Util::sqlTime($e->first_activity_at) : null;
        $extra['last_activity_at'] = isset($e->last_activity_at) ? Util::sqlTime($e->last_activity_at) : null;

        $columns = array_keys($extra);
        if ($existing) {
            $set = implode(',', array_map(static fn($c) => "$c=?", $columns));
            $this->write("UPDATE activity_snapshots SET snapshot_id=?,sequence=?,active_seconds=?,idle_seconds=?,$set,body_hash=?,received_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND day=?",
                [Util::bin($e->snapshot_id), $e->sequence, $e->active_seconds, $e->idle_seconds, ...array_values($extra), Util::hash($e), $this->c['tenant_id'], $this->c['device_id'], $e->day]);
        } else {
            $names = implode(',', $columns);
            $holes = implode(',', array_fill(0, count($columns), '?'));
            $this->write("INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,$names,body_hash,received_at) VALUES (?,?,?,?,?,?,?,?,?,$holes,?,UTC_TIMESTAMP(6))",
                [$this->c['tenant_id'], $this->c['device_id'], $e->day, Util::bin($e->snapshot_id), $e->sequence, $this->c['user_id'], $this->c['assignment_id'], $e->active_seconds, $e->idle_seconds, ...array_values($extra), Util::hash($e)]);
        }
        return self::ack($e->snapshot_id);
    }
    public function checkIn(object $e): array
    {
        $this->range($e->occurred_at);
        if (isset($e->site_id) && !$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=\'site\' AND active=TRUE', [$this->c['tenant_id'], Util::bin($e->site_id)])) { throw new ApiError(404, 'resource_not_found'); }
        $existing = $this->db->one('SELECT * FROM check_ins WHERE tenant_id=? AND source=\'agent\' AND source_identity=? AND event_id=?', [$this->c['tenant_id'], Util::id($this->c['device_id']), Util::bin($e->event_id)]);
        if ($existing && !hash_equals($existing['body_hash'], Util::hash($e))) { throw new ApiError(409, 'idempotency_conflict'); }
        $id = $existing['id'] ?? Util::bin(Util::uuid());
        if (!$existing) {
            $l = $e->location ?? (object) [];
            if (isset($l->accuracy_m) && $l->accuracy_m > 999999999) { throw new ApiError(422, 'invalid_range'); }
            $this->write('INSERT INTO check_ins (tenant_id,id,event_id,source_identity,user_id,device_id,site_id,source,direction,occurred_at,latitude,longitude,accuracy_m,body_hash) VALUES (?,?,?,?,?,?,?,\'agent\',?,?,?,?,?,?)', [$this->c['tenant_id'], $id, Util::bin($e->event_id), Util::id($this->c['device_id']), $this->c['user_id'], $this->c['device_id'], isset($e->site_id) ? Util::bin($e->site_id) : null, $e->direction, Util::sqlTime($e->occurred_at), $l->latitude ?? null, $l->longitude ?? null, $l->accuracy_m ?? null, Util::hash($e)], 'idempotency_conflict');
            (new Audit($this->db))->record($this->c, $this->r, 'check_in.recorded', 'check_in', $id, ['direction']);
        }
        $result = ['id' => Util::id($id), 'tenant_id' => Util::id($this->c['tenant_id']), 'user_id' => Util::id($this->c['user_id']), 'occurred_at' => Util::time(Util::sqlTime($e->occurred_at)), 'source' => 'agent', 'direction' => $e->direction, 'site_id' => isset($e->site_id) ? strtolower($e->site_id) : null];
        if (isset($e->location)) { $result['location'] = $e->location; }
        return $result;
    }
}
