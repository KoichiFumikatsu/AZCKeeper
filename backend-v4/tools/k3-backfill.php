<?php
declare(strict_types=1);

trait K3Backfill
{
    private const FOCUS_PRESENCE = ['first_activity_time', 'scheduled_start', 'punctuality_minutes',
        'productivity_pct', 'constancy_pct', 'deep_work_sessions', 'longest_focus_streak_seconds'];

    private function activityUtc(?string $value): ?string
    {
        if ($value === null) { return null; }
        $format = str_contains($value, '.') ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s';
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value, new DateTimeZone($this->datetimeZone));
        if ($date === false || $date->format($format) !== $value) { throw new RuntimeException('DATETIME de actividad inválido.'); }
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    private static function focusPresence(array $rows): array
    {
        usort($rows, static fn($a, $b) => strcmp($a['first_activity_time'] ?? '99:99:99', $b['first_activity_time'] ?? '99:99:99')
            ?: ((int) $a['device_id'] <=> (int) $b['device_id']));
        $first = $rows[0];
        $mean = static function (string $column) use ($rows): ?float {
            $values = array_filter(array_column($rows, $column), static fn($v) => $v !== null);
            return $values ? round(array_sum($values) / count($values), 2) : null;
        };
        return ['first_activity_time' => $first['first_activity_time'], 'scheduled_start' => $first['scheduled_start'],
            'punctuality_minutes' => $first['first_activity_time'] === null || $first['punctuality_minutes'] === null ? null : (int) $first['punctuality_minutes'],
            'productivity_pct' => $mean('productivity_pct'), 'constancy_pct' => $mean('constancy_pct'),
            'deep_work_sessions' => (int) array_sum(array_column($rows, 'deep_work_sessions')),
            'longest_focus_streak_seconds' => (int) max(array_column($rows, 'longest_focus_streak_seconds'))];
    }

    private function backfillGroups(string $table, string $columns): Generator
    {
        $group = [];
        $key = null;
        foreach ($this->sourceRows($table, 'SELECT id,user_id,device_id,day_date,' . $columns . ' FROM ' . $table,
            [], ['user_id' => 'ASC', 'day_date' => 'ASC', 'device_id' => 'ASC', 'id' => 'DESC']) as $row) {
            $next = $row['user_id'] . ':' . $row['day_date'];
            if ($key !== null && $key !== $next) { yield array_values($group); $group = []; }
            $key = $next;
            // Highest id wins, including duplicates that straddle source pages.
            $group[$row['device_id']] ??= $row;
        }
        if ($group) { yield array_values($group); }
    }

    private function exportBackfill(): void
    {
        $stream = @fopen($this->exportBackfill, 'xb');
        if ($stream === false) { throw new RuntimeException('No se pudo crear el backfill; la ruta debe ser nueva.'); }
        $complete = false;
        $records = 0;
        $write = static function (array $row) use ($stream): void {
            $line = self::json($row) . "\n";
            if (fwrite($stream, $line) !== strlen($line)) { throw new RuntimeException('Escritura del backfill incompleta.'); }
        };
        try {
            $write(['type' => 'k3-backfill', 'version' => 1, 'source_database' => $this->source->select('SELECT DATABASE()')->fetchColumn(),
                'activity_timezone' => 'UTC', 'focus_times' => 'tenant-local']);
            foreach (['activity' => ['keeper_activity_day', 'first_event_at,last_event_at'],
                'focus' => ['keeper_focus_daily', implode(',', self::FOCUS_PRESENCE)]] as $type => [$table, $columns]) {
                $exported = 0;
                foreach ($this->backfillGroups($table, $columns) as $rows) {
                    $record = ['type' => $type, 'legacy_user_id' => (string) $rows[0]['user_id'], 'day' => $rows[0]['day_date']];
                    if ($type === 'activity') {
                        $record['devices'] = array_map(fn($r) => ['legacy_device_id' => (string) $r['device_id'],
                            'first_activity' => $this->activityUtc($r['first_event_at']), 'last_activity' => $this->activityUtc($r['last_event_at'])], $rows);
                    } else { $record += self::focusPresence($rows); }
                    self::validateBackfillRecord($record);
                    $write($record);
                    $records++;
                    $exported++;
                }
                $this->backfillStats[$table] = ['leidas' => $this->reads[$table], 'exportadas' => $exported,
                    'actualizadas' => 0, 'no_encontradas' => 0];
            }
            $write(['type' => 'end', 'records' => $records]);
            if (!fflush($stream)) { throw new RuntimeException('No se pudo completar el backfill.'); }
            $complete = true;
        } finally {
            fclose($stream);
            if (!$complete) { @unlink($this->exportBackfill); }
        }
    }

    private static function validateBackfillRecord(array $row): void
    {
        $fail = static function (): never { throw new RuntimeException('Registro backfill inválido.'); };
        $id = static fn($v) => is_string($v) && preg_match('/^[1-9][0-9]{0,19}$/D', $v);
        if (!in_array($row['type'] ?? null, ['activity', 'focus'], true)
            || !$id($row['legacy_user_id'] ?? null) || !self::validDate($row['day'] ?? null)) { $fail(); }
        $columns = $row['type'] === 'activity' ? ['devices'] : self::FOCUS_PRESENCE;
        $expected = ['type', 'legacy_user_id', 'day', ...$columns];
        if (array_diff(array_keys($row), $expected) || array_diff($expected, array_keys($row))) { $fail(); }
        if ($row['type'] === 'activity') {
            if (!is_array($row['devices']) || !array_is_list($row['devices']) || !$row['devices']) { $fail(); }
            $seen = [];
            foreach ($row['devices'] as $device) {
                if (!is_array($device) || count($device) !== 3 || !$id($device['legacy_device_id'] ?? null)
                    || !array_key_exists('first_activity', $device) || !array_key_exists('last_activity', $device)
                    || isset($seen[$device['legacy_device_id']])) { $fail(); }
                $seen[$device['legacy_device_id']] = true;
                foreach (['first_activity', 'last_activity'] as $column) {
                    $value = $device[$column];
                    if ($value === null) { continue; }
                    if (!is_string($value)) { $fail(); }
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
                    if (!$date || $date->format('Y-m-d H:i:s.u') !== $value) { $fail(); }
                }
                if ($device['first_activity'] !== null && $device['last_activity'] !== null
                    && $device['first_activity'] > $device['last_activity']) { $fail(); }
            }
            return;
        }
        foreach (['first_activity_time', 'scheduled_start'] as $column) {
            if ($row[$column] !== null && (!is_string($row[$column]) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', $row[$column]))) { $fail(); }
        }
        foreach (['punctuality_minutes' => [-32768, 32767], 'productivity_pct' => [0, 100], 'constancy_pct' => [0, 100],
            'deep_work_sessions' => [0, 4294967295], 'longest_focus_streak_seconds' => [0, 4294967295]] as $column => [$min, $max]) {
            $value = $row[$column];
            $decimal = in_array($column, ['productivity_pct', 'constancy_pct'], true);
            if ($value === null && !in_array($column, ['deep_work_sessions', 'longest_focus_streak_seconds'], true)) { continue; }
            if ((!is_int($value) && !($decimal && is_float($value))) || $value < $min || $value > $max) { $fail(); }
        }
    }

    private function backfillRecords($stream): Generator
    {
        rewind($stream);
        $lineNumber = 0;
        $records = 0;
        $ended = false;
        while (($line = fgets($stream, 1048577)) !== false) {
            $lineNumber++;
            try { $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new RuntimeException('JSON backfill inválido en línea ' . $lineNumber . '.'); }
            if (!str_ends_with($line, "\n") || !is_array($row) || $ended) { throw new RuntimeException('Backfill truncado o inválido.'); }
            if ($lineNumber === 1) {
                if (($row['type'] ?? null) !== 'k3-backfill' || ($row['version'] ?? null) !== 1
                    || ($row['activity_timezone'] ?? null) !== 'UTC' || ($row['focus_times'] ?? null) !== 'tenant-local'
                    || !is_string($row['source_database'] ?? null) || $row['source_database'] === '') {
                    throw new RuntimeException('Cabecera backfill incompatible.');
                }
                if (strcasecmp($row['source_database'], (string) $this->sql('SELECT DATABASE()')->fetchColumn()) === 0) {
                    throw new RuntimeException('Destino rechazado: coincide con K3.');
                }
                continue;
            }
            if (($row['type'] ?? null) === 'end') {
                if (($row['records'] ?? null) !== $records) { throw new RuntimeException('Conteo final backfill inválido.'); }
                $ended = true;
                continue;
            }
            self::validateBackfillRecord($row);
            $records++;
            yield $row;
        }
        if (!$ended || !feof($stream)) { throw new RuntimeException('Backfill incompleto: falta el cierre.'); }
    }

    private function importBackfill(): void
    {
        $stream = @fopen($this->importBackfill, 'rb');
        if ($stream === false) { throw new RuntimeException('No se pudo abrir el backfill.'); }
        try {
            // Validate the entire file before committing any batch, including its completion marker.
            foreach ($this->backfillRecords($stream) as $_) {}
            $batch = [];
            foreach ($this->backfillRecords($stream) as $row) {
                $batch[] = $row;
                if (count($batch) >= self::BATCH) { $this->applyBackfillBatch($batch); $batch = []; }
            }
            if ($batch) { $this->applyBackfillBatch($batch); }
        } finally { fclose($stream); }
    }

    private function applyBackfillBatch(array $batch): void
    {
        $before = $this->backfillStats;
        if (!$this->dry) { $this->dev->beginTransaction(); }
        try {
            $ids = array_values(array_unique(array_column($batch, 'legacy_user_id')));
            $refs = [];
            foreach ($this->sql("SELECT external_ref,user_id FROM user_external_refs WHERE tenant_id=? AND source='k3' AND external_ref IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ')', [$this->tenant, ...$ids]) as $ref) { $refs[$ref['external_ref']] = $ref['user_id']; }
            foreach ($batch as $row) {
                $this->backfillStats['user_external_refs'] ??= ['leidas' => 0, 'encontradas' => 0, 'no_encontradas' => 0];
                $this->backfillStats['user_external_refs']['leidas']++;
                $user = $refs[$row['legacy_user_id']] ?? null;
                if ($user === null) { $this->backfillStats['user_external_refs']['no_encontradas']++; continue; }
                $this->backfillStats['user_external_refs']['encontradas']++;
                if ($row['type'] === 'focus') {
                    $this->patchBackfill('focus_daily', array_intersect_key($row, array_flip(self::FOCUS_PRESENCE)), $user, $row['day']);
                    continue;
                }
                $first = $last = null;
                foreach ($row['devices'] as $device) {
                    if ($device['first_activity'] !== null) { $first = min($first ?? $device['first_activity'], $device['first_activity']); }
                    if ($device['last_activity'] !== null) { $last = max($last ?? $device['last_activity'], $device['last_activity']); }
                    $this->patchBackfill('episode_daily', ['first_activity' => $device['first_activity'], 'last_activity' => $device['last_activity']],
                        $user, $row['day'], $this->id('device', $device['legacy_device_id']));
                }
                $this->patchBackfill('day_summary', ['first_activity' => $first, 'last_activity' => $last], $user, $row['day']);
            }
            if (!$this->dry) { $this->dev->commit(); }
        } catch (Throwable $e) {
            if ($this->dev->inTransaction()) { $this->dev->rollBack(); }
            $this->backfillStats = $before;
            throw $e;
        }
    }

    private function patchBackfill(string $table, array $values, string $user, string $day, ?string $device = null): void
    {
        $this->backfillStats[$table] ??= ['leidas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0, 'no_encontradas' => 0];
        $stats = &$this->backfillStats[$table];
        $stats['leidas']++;
        // El backfill parchea filas que importó esta herramienta, sin importar con qué revisión
        // se escribieron: un import previo (k3-import-v1) sigue siendo válido para completar horas.
        $where = "tenant_id=? AND user_id=? AND day=? AND calculation_version LIKE 'k3-import-%'";
        $args = [$this->tenant, $user, $day];
        if ($device !== null) { $where .= " AND device_id=? AND process_name='__k3_daily__'"; $args[] = $device; }
        $count = (int) $this->sql("SELECT COUNT(*) FROM `$table` WHERE $where", $args)->fetchColumn();
        if ($count === 0) { $stats['no_encontradas']++; return; }
        $columns = array_keys($values);
        $different = 'NOT (' . implode(' AND ', array_map(static fn($c) => "`$c` <=> ?", $columns)) . ')';
        if ($this->dry) {
            $changed = (int) $this->sql("SELECT COUNT(*) FROM `$table` WHERE $where AND $different", [...$args, ...array_values($values)])->fetchColumn();
        } else {
            $changed = $this->sql("UPDATE `$table` SET " . implode(',', array_map(static fn($c) => "`$c`=?", $columns))
                . " WHERE $where AND $different", [...array_values($values), ...$args, ...array_values($values)])->rowCount();
        }
        $stats['actualizadas'] += $changed;
        $stats['sin_cambios'] += $count - $changed;
    }
}
