<?php
declare(strict_types=1);
namespace Keeper;

// Entradas por puerta (§7): push por cedula y DataSync por vinculo externo, con referencia de puerta compartida.
// Idempotente por (fuente, event_id). Lo que no
// se puede emparejar (puerta o persona desconocida) queda en cuarentena 30 dias y se reintenta cuando aparezca.
final class DoorEvents
{
    public function __construct(private Database $db) {}

    public static function identity(string $integration): string { return 'integration:' . Util::id($integration); }
    public static function sourceIdentity(string $source): string { return 'data_source:' . Util::id($source); }

    /** Eventos REST: la persona se resuelve exclusivamente por el vinculo de esta fuente. */
    public function ingestSource(string $tenant, string $sourceId, array $e, string $fromDate, string $timezone, bool $simulate): array
    {
        $ref = $e['external_id'] ?? null;
        $res = static fn (string $status, ?string $reason = null) => ['event_id' => is_string($ref) ? $ref : null, 'status' => $status, 'reason' => $reason];
        if (!is_string($ref) || $ref === '' || mb_strlen($ref) > 160
            || !is_string($e['person_external_id'] ?? null) || $e['person_external_id'] === '' || mb_strlen($e['person_external_id']) > 160
            || !array_key_exists('direction', $e) || !in_array($e['direction'], ['in', 'out', null], true)) { return $res('rejected', 'invalid_item'); }
        if (!is_string($e['door_ref'] ?? null) || trim($e['door_ref']) === '' || mb_strlen($e['door_ref']) > 160) { return $res('rejected', 'invalid_door_ref'); }
        if (!is_string($e['occurred_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $e['occurred_at'])) { return $res('rejected', 'invalid_time'); }
        try { $at = new \DateTimeImmutable($e['occurred_at']); } catch (\Exception) { return $res('rejected', 'invalid_time'); }
        if (\DateTimeImmutable::getLastErrors() !== false) { return $res('rejected', 'invalid_time'); }
        if ($at < new \DateTimeImmutable($fromDate, new \DateTimeZone($timezone))) { return $res('ignored', 'before_from_date'); }
        if ($at > new \DateTimeImmutable('+10 minutes')) { return $res('rejected', 'time_out_of_range'); }
        $source = self::sourceIdentity($sourceId);
        $eventId = substr(hash('sha256', $source . '|' . $ref, true), 0, 16);
        if ($this->db->one("SELECT id FROM check_ins WHERE tenant_id=? AND source='door' AND source_identity=? AND event_id=?", [$tenant, $source, $eventId])
            || $this->db->one('SELECT id FROM unmatched_check_ins WHERE tenant_id=? AND source_identity=? AND event_ref=?', [$tenant, $source, $ref])) { return $res('duplicate'); }
        [$status, $reason] = $this->store($tenant, $source, $eventId, $ref, $e['door_ref'], null, $e['direction'], $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'), Util::hash($e), $sourceId, $e['person_external_id'], $simulate);
        return $res($status, $reason);
    }

    /** @return array{results: array, accepted: int, duplicate: int, quarantined: int, rejected: int, ignored: int} */
    public function ingest(string $tenant, string $integration, array $events): array
    {
        $source = self::identity($integration); $out = []; $totals = ['accepted' => 0, 'duplicate' => 0, 'quarantined' => 0, 'rejected' => 0, 'ignored' => 0];
        foreach ($events as $e) {
            $result = $this->one($tenant, $source, $e);
            $totals[$result['status']]++; $out[] = $result;
        }
        return ['results' => $out] + $totals;
    }

    private function one(string $tenant, string $source, object $e): array
    {
        $ref = $e->event_id;
        $res = static fn (string $status, ?string $reason = null) => ['event_id' => $ref, 'status' => $status, 'reason' => $reason];
        if (!$e->granted) { return $res('ignored', 'not_granted'); }
        try { $at = new \DateTimeImmutable($e->occurred_at); } catch (\Exception) { return $res('rejected', 'invalid_time'); }
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        if ($at > new \DateTimeImmutable('+10 minutes') || $at < new \DateTimeImmutable('-400 days')) { return $res('rejected', 'time_out_of_range'); }
        $eventId = substr(hash('sha256', $source . '|' . $ref, true), 0, 16);
        if ($this->db->one("SELECT id FROM check_ins WHERE tenant_id=? AND source='door' AND source_identity=? AND event_id=?", [$tenant, $source, $eventId])
            || $this->db->one('SELECT id FROM unmatched_check_ins WHERE tenant_id=? AND source_identity=? AND event_ref=?', [$tenant, $source, $ref])) { return $res('duplicate'); }
        $document = DeviceIntake::document($e->document);
        [$status, $reason] = $this->store($tenant, $source, $eventId, $ref, $e->door_ref, $document, $e->direction, $at->format('Y-m-d H:i:s.u'), Util::hash($e));
        return $res($status, $reason);
    }

    /** Guarda la entrada o la deja en cuarentena. Devuelve [status, reason]. */
    private function store(string $tenant, string $source, string $eventId, string $ref, string $doorRef, ?string $document, ?string $direction, string $at, string $hash, ?string $sourceId = null, ?string $personExternal = null, bool $simulate = false): array
    {
        $door = $this->db->one('SELECT * FROM doors WHERE tenant_id=? AND source_ref=?', [$tenant, $doorRef]);
        if ($door && (!$door['active'] || !$door['counts_for_presence'])) { return ['ignored', 'door_not_for_presence']; }
        $users = $sourceId !== null
            ? array_column($this->db->run("SELECT u.id user_id FROM entity_links l JOIN users u ON u.tenant_id=l.tenant_id AND u.id=l.entity_id WHERE l.tenant_id=? AND l.source_id=? AND l.entity_type='user' AND l.external_id=?", [$tenant, $sourceId, $personExternal])->fetchAll(), 'user_id')
            : ($door ? array_column($this->db->run("SELECT DISTINCT r.user_id FROM user_external_refs r JOIN users u ON u.tenant_id=r.tenant_id AND u.id=r.user_id AND u.status='active' WHERE r.tenant_id=? AND r.source IN ('document','k3:cc') AND r.external_ref=?", [$tenant, $document])->fetchAll(), 'user_id') : []);
        if (count($users) > 1) { return ['rejected', 'document_ambiguous']; }
        if (!$door || !$users) {
            if (!$simulate) { $this->db->run('INSERT INTO unmatched_check_ins (tenant_id,id,source_identity,event_ref,door_ref,document,direction,occurred_at,reason,data_source_id,person_external_id) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason)',
                [$tenant, Util::bin(Util::uuid()), $source, $ref, $doorRef, $document, $direction, $at, $door ? 'unknown_person' : 'unknown_door', $sourceId, $personExternal]); }
            return ['quarantined', $door ? 'unknown_person' : 'unknown_door'];
        }
        if (!$simulate) { $this->db->run("INSERT INTO check_ins (tenant_id,id,event_id,source_identity,user_id,door_id,site_id,source,direction,occurred_at,body_hash) VALUES (?,?,?,?,?,?,?,'door',?,?,?)",
            [$tenant, Util::bin(Util::uuid()), $eventId, $source, $users[0], $door['id'], $door['site_id'], $direction, $at, $hash]); }
        return ['accepted', null];
    }

    /** Reintenta la cuarentena de una empresa (tras crear una puerta o cargar personas) y purga lo de mas de 30 dias. */
    public function rematch(string $tenant): int
    {
        $this->db->run('DELETE FROM unmatched_check_ins WHERE tenant_id=? AND created_at < UTC_TIMESTAMP(6) - INTERVAL 30 DAY', [$tenant]);
        $done = 0;
        foreach ($this->db->run('SELECT * FROM unmatched_check_ins WHERE tenant_id=? ORDER BY occurred_at LIMIT 5000 FOR UPDATE', [$tenant])->fetchAll() as $q) {
            if ($q['data_source_id'] !== null && !Features::has($this->db, $tenant, 'puertas')) { continue; }
            $eventId = substr(hash('sha256', $q['source_identity'] . '|' . $q['event_ref'], true), 0, 16);
            $hash = hash('sha256', $q['source_identity'] . '|' . $q['event_ref'] . '|' . $q['occurred_at'], true);
            [$status] = $this->store($tenant, $q['source_identity'], $eventId, $q['event_ref'], $q['door_ref'], $q['document'], $q['direction'], $q['occurred_at'], $hash, $q['data_source_id'], $q['person_external_id']);
            if ($status !== 'quarantined') { $this->db->run('DELETE FROM unmatched_check_ins WHERE tenant_id=? AND id=?', [$tenant, $q['id']]); }
            if ($status === 'accepted') { $done++; }
        }
        return $done;
    }
}
