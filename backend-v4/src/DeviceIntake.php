<?php
declare(strict_types=1);
namespace Keeper;

// Alta de equipos a escala (docs/architecture/v4-alta-equipos.md). Un paquete igual para todos con la clave de alta
// de la empresa; el equipo pide alta con su serie y el cruce se hace SIEMPRE contra el registro propio de equipos
// esperados, nunca contra un sistema externo. Lo que no cruza queda pendiente para IT.
final class DeviceIntake
{
    public const RENAME_MIN_AGENT = '4.0.8';
    private const DOCUMENT_SOURCES = ['document', 'k3:cc'];

    public function __construct(private Database $db) {}

    public static function serial(?string $value): ?string
    {
        if ($value === null) { return null; }
        $value = strtoupper(trim(preg_replace('/\s+/', ' ', $value)));
        return $value === '' ? null : $value;
    }
    public static function asset(?string $value): ?string
    {
        if ($value === null) { return null; }
        $value = strtoupper(trim($value));
        return $value === '' ? null : $value;
    }
    public static function document(string $value): string { return preg_replace('/[\s.\-]/', '', trim($value)); }
    // ACT_0015 -> ACT-0015: el guion bajo no es valido en nombres DNS; mismo patron que el agente (ComputerName.IsValid).
    public static function computerName(string $asset): ?string
    {
        $name = substr(trim(preg_replace('/[^A-Z0-9-]+/', '-', strtoupper($asset)), '-'), 0, 15);
        return preg_match('/^(?![0-9]+$)[A-Za-z0-9-]{1,15}$/D', $name) ? $name : null;
    }

    public static function enqueueCommand(Database $db, string $tenant, array $device, string $type, string $reason, ?string $parameters, string $expires): string
    {
        $seq = (int) $db->one('SELECT COALESCE(MAX(sequence),0)+1 n FROM device_command WHERE tenant_id=? AND device_id=?', [$tenant, $device['id']])['n'];
        $seq = max($seq, (int) $device['command_sequence'] + 1);
        $id = Util::bin(Util::uuid());
        $db->run('UPDATE devices SET command_sequence=? WHERE tenant_id=? AND id=?', [$seq, $tenant, $device['id']]);
        $db->run('INSERT INTO device_command (tenant_id,id,device_id,sequence,type,reason,parameters,created_at,expires_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?)', [$tenant, $id, $device['id'], $seq, $type, $reason, $parameters, $expires]);
        return $id;
    }

    // ---------------------------------------------------------------- cliente

    public function client(Request $r, Signature $signature, RateLimiter $limiter): array
    {
        $b = $r->body;
        $key = $this->db->one('SELECT k.tenant_id FROM enrollment_keys k JOIN tenants t ON t.tenant_id=k.tenant_id WHERE k.key_hash=? AND k.revoked_at IS NULL AND t.status=\'active\'', [hash('sha256', $b->enrollment_key, true)]);
        if (!$key) {
            $limiter->take([['bootstrap', 'enrollment:unknown', 60, 20]]);
            throw new ApiError(401, 'invalid_credentials');
        }
        $tenant = $key['tenant_id'];
        $thumb = Signature::thumbprint($b->public_key);
        // Por empresa y por equipo, nunca por IP: 1.000 equipos detras de un mismo NAT son el caso normal.
        $limiter->take([
            ['enrollment', bin2hex($tenant . $thumb), Config::number('ENROLLMENT_DEVICE_RATE', 4), Config::number('ENROLLMENT_DEVICE_BURST', 4)],
            ['tenant', 'enrollment:' . bin2hex($tenant), Config::number('ENROLLMENT_TENANT_RATE', 600), Config::number('ENROLLMENT_TENANT_BURST', 200)],
        ]);
        $signature->verify($r, $b->public_key);
        return $this->db->transaction(function () use ($r, $b, $tenant, $thumb): array {
            $serial = self::serial($b->serial_number ?? null);
            $claimed = isset($b->claimed_document) ? self::document($b->claimed_document) : null;
            if ($claimed === '') { $claimed = null; }
            $row = $this->db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND public_key_thumbprint=? FOR UPDATE', [$tenant, $thumb]);
            if (!$row) {
                $pending = (int) $this->db->one('SELECT COUNT(*) n FROM enrollment_requests WHERE tenant_id=? AND status=\'pending\'', [$tenant])['n'];
                if ($pending >= Config::number('ENROLLMENT_PENDING_MAX', 5000)) { throw new ApiError(429, 'rate_limited', ['Retry-After' => '900']); }
                $id = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO enrollment_requests (tenant_id,id,public_key_thumbprint,serial_number,hostname,agent_version,claimed_document) VALUES (?,?,?,?,?,?,?)', [$tenant, $id, $thumb, $serial, $b->hostname, $b->agent_version, $claimed]);
                $row = $this->db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $id]);
            } else {
                $this->db->run('UPDATE enrollment_requests SET hostname=?,agent_version=?,serial_number=IF(status=\'pending\',?,serial_number),claimed_document=IF(status=\'pending\',COALESCE(?,claimed_document),claimed_document),last_seen_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$b->hostname, $b->agent_version, $serial, $claimed, $tenant, $row['id']]);
                $row = $this->db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $row['id']]);
            }
            // Se reevalua en cada reintento: IT puede cargar el CSV despues de que los equipos ya pidieron alta.
            if ($row['status'] === 'pending') { $row = $this->evaluate($row); }
            $out = ['status' => $row['status'], 'request_id' => Util::id($row['id']), 'enrollment_ticket' => null, 'device_id' => null, 'retry_after_seconds' => Config::number('ENROLLMENT_RETRY_SECONDS', 300)];
            if ($row['status'] === 'approved') {
                $out['enrollment_ticket'] = $this->ticket($row, $thumb);
                $out['retry_after_seconds'] = 0;
            } elseif ($row['status'] === 'enrolled') {
                $out['device_id'] = Util::id($row['device_id']);
                $out['retry_after_seconds'] = 0;
            } elseif ($row['status'] === 'rejected') {
                $out['retry_after_seconds'] = 21600;
            }
            return $out;
        });
    }

    private function settings(string $tenant): array
    {
        return $this->db->one('SELECT * FROM enrollment_settings WHERE tenant_id=?', [$tenant])
            ?? ['auto_approve_serial_match' => 1, 'self_identify' => 0, 'self_identify_auto_confirm' => 0, 'version' => 0];
    }

    private function evaluate(array $row): array
    {
        $tenant = $row['tenant_id']; $settings = $this->settings($tenant);
        $set = ['match_kind' => 'none', 'alert' => null, 'suggested_user_id' => null, 'expected_device_id' => null, 'asset_code' => null];
        $expected = $row['serial_number'] === null ? null
            : $this->db->one('SELECT * FROM expected_devices WHERE tenant_id=? AND pending_serial=? FOR UPDATE', [$tenant, $row['serial_number']]);
        if ($expected) {
            $set = ['match_kind' => 'serial', 'alert' => null, 'suggested_user_id' => $expected['user_id'], 'expected_device_id' => $expected['id'], 'asset_code' => $expected['asset_code']];
            // El esperado se consume con el primer equipo que coincide; un segundo con la misma serie queda para IT.
            $taken = $this->db->one('SELECT id FROM enrollment_requests WHERE tenant_id=? AND expected_device_id=? AND id<>? AND status IN (\'approved\',\'enrolled\') LIMIT 1', [$tenant, $expected['id'], $row['id']]);
            if ($taken) { $set['alert'] = 'duplicate_serial'; }
            elseif ($settings['auto_approve_serial_match'] && $this->activeUser($tenant, $expected['user_id'])) {
                $set += ['status' => 'approved', 'user_id' => $expected['user_id']];
            }
        } elseif ($row['serial_number'] !== null && ($known = $this->knownSerial($tenant, $row['serial_number']))) {
            // Misma serie que un equipo ya enrolado: reinstalacion o suplantacion. Nunca se aprueba solo.
            $set = ['match_kind' => 'none', 'alert' => 'serial_already_enrolled', 'suggested_user_id' => $known['user_id'], 'expected_device_id' => null, 'asset_code' => null];
        } elseif ($row['claimed_document'] !== null && $settings['self_identify']) {
            $users = $this->documentUsers($tenant, $row['claimed_document']);
            if (count($users) === 1) {
                $set = ['match_kind' => 'document', 'alert' => null, 'suggested_user_id' => $users[0], 'expected_device_id' => null, 'asset_code' => null];
                if ($settings['self_identify_auto_confirm']) { $set += ['status' => 'approved', 'user_id' => $users[0]]; }
            } else { $set['alert'] = 'document_not_found'; }
        }
        $fields = implode(',', array_map(static fn ($k) => "$k=?", array_keys($set)));
        $approved = isset($set['status']) ? ',decided_at=UTC_TIMESTAMP(6)' : '';
        $this->db->run("UPDATE enrollment_requests SET $fields$approved WHERE tenant_id=? AND id=?", [...array_values($set), $tenant, $row['id']]);
        return $this->db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $row['id']]);
    }

    // Serie ya usada: un esperado ya consumido o un equipo activo que la reporto en su inventario.
    private function knownSerial(string $tenant, string $serial): ?array
    {
        return $this->db->one('SELECT user_id FROM expected_devices WHERE tenant_id=? AND status=\'enrolled\' AND serial_number=? ORDER BY updated_at DESC LIMIT 1', [$tenant, $serial])
            ?? $this->db->one('SELECT user_id FROM devices WHERE tenant_id=? AND status=\'active\' AND JSON_UNQUOTE(JSON_EXTRACT(specs,\'$.serial_number\'))=? LIMIT 1', [$tenant, $serial]);
    }
    private function activeUser(string $tenant, string $user): bool
    {
        return (bool) $this->db->one('SELECT id FROM users WHERE tenant_id=? AND id=? AND status=\'active\'', [$tenant, $user]);
    }

    private function documentUsers(string $tenant, string $document): array
    {
        $in = implode(',', array_fill(0, count(self::DOCUMENT_SOURCES), '?'));
        return array_values(array_unique(array_column($this->db->run("SELECT r.user_id FROM user_external_refs r JOIN users u ON u.tenant_id=r.tenant_id AND u.id=r.user_id AND u.status='active' WHERE r.tenant_id=? AND r.source IN ($in) AND r.external_ref=?", [$tenant, ...self::DOCUMENT_SOURCES, $document])->fetchAll(), 'user_id')));
    }

    // Ticket de un uso ligado a la clave publica de la solicitud: sin la privada no sirve. Se emite en cada reintento
    // mientras siga aprobada (el anterior se revoca), asi un agente que perdio la respuesta no queda bloqueado.
    private function ticket(array $row, string $thumb): string
    {
        $tenant = $row['tenant_id'];
        if ($row['enrollment_id'] !== null) {
            $this->db->run('UPDATE enrollments SET status=\'revoked\' WHERE tenant_id=? AND id=? AND status=\'pending\'', [$tenant, $row['enrollment_id']]);
        }
        // Reinstalacion aprobada del mismo equipo (misma serie y misma persona): recupera el equipo en vez de duplicarlo.
        $device = null;
        if ($row['serial_number'] !== null) {
            $same = $this->db->run('SELECT id FROM devices WHERE tenant_id=? AND user_id=? AND status=\'active\' AND JSON_UNQUOTE(JSON_EXTRACT(specs,\'$.serial_number\'))=? LIMIT 2', [$tenant, $row['user_id'], $row['serial_number']])->fetchAll();
            if (count($same) === 1) { $device = $same[0]['id']; }
        }
        $ticket = Util::b64(random_bytes(32)); $id = Util::bin(Util::uuid());
        $this->db->run('INSERT INTO enrollments (tenant_id,id,user_id,device_id,ticket_hash,public_key_thumbprint,reason,created_at,expires_at) VALUES (?,?,?,?,?,?,\'Alta por solicitud\',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)', [$tenant, $id, $row['user_id'], $device, hash('sha256', $ticket, true), $thumb]);
        $this->db->run('UPDATE enrollment_requests SET enrollment_id=? WHERE tenant_id=? AND id=?', [$id, $tenant, $row['id']]);
        return $ticket;
    }

    // Llamado por Auth::login dentro de la misma transaccion al consumir un ticket.
    public static function onEnrolled(Database $db, string $tenant, string $enrollment, string $device): ?string
    {
        $row = $db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND enrollment_id=? AND status=\'approved\' FOR UPDATE', [$tenant, $enrollment]);
        if (!$row) { return null; }
        $db->run('UPDATE enrollment_requests SET status=\'enrolled\',device_id=? WHERE tenant_id=? AND id=?', [$device, $tenant, $row['id']]);
        if ($row['expected_device_id'] !== null) {
            $db->run('UPDATE expected_devices SET status=\'enrolled\',device_id=?,version=version+1 WHERE tenant_id=? AND id=? AND status=\'pending\'', [$device, $tenant, $row['expected_device_id']]);
        }
        if ($row['asset_code'] === null) { return $row['id']; }
        $db->run('UPDATE devices SET asset_code=? WHERE tenant_id=? AND id=?', [$row['asset_code'], $tenant, $device]);
        $d = $db->one('SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $device]);
        $name = self::computerName($row['asset_code']);
        // Renombre pendiente de reinicio: no interrumpe a la persona; los agentes viejos no conocen el comando.
        if ($name !== null && strcasecmp($name, $d['hostname']) !== 0 && version_compare($d['agent_version'], self::RENAME_MIN_AGENT, '>=')) {
            $expires = $db->one('SELECT UTC_TIMESTAMP(6)+INTERVAL 23 HOUR at')['at'];
            self::enqueueCommand($db, $tenant, $d, 'rename_computer', 'Placa de activo ' . $row['asset_code'], Util::json(['computer_name' => $name, 'restart_now' => false]), $expires);
        }
        return $row['id'];
    }

    // ---------------------------------------------------------------- admin

    public function admin(string $op, ?string $id, ?object $b, AdminAccess $access, array $c, Request $r): array
    {
        $tenant = $c['tenant_id'];
        $audit = function (string $type, string $rid, array $fields) use ($c, $r, $op): void { (new Audit($this->db))->record($c, $r, $op, $type, $rid, $fields); };
        switch ($op) {
            case 'listExpectedDevices':
                [$where, $args] = $access->usersSql();
                $status = $_GET['status'] ?? null;
                return $this->page('SELECT e.*,u.display_name FROM expected_devices e JOIN users u ON u.tenant_id=e.tenant_id AND u.id=e.user_id WHERE e.tenant_id=? AND ' . $where . ($status ? ' AND e.status=?' : '') . ' ORDER BY e.created_at DESC,e.id',
                    [$tenant, ...$args, ...($status ? [$status] : [])], fn ($x) => $this->expectedDto($x));
            case 'createExpectedDevice':
                [$created, $error] = $this->createExpected($tenant, $access, $b, 'manual');
                if ($error !== null) { throw self::rowError($error); }
                $audit('expected_device', $created, ['user_id', 'asset_code', 'serial_number']);
                return AdminApi::response($this->expectedDto($this->expected($tenant, $created)), 201);
            case 'importExpectedDevices':
                $created = 0; $errors = [];
                foreach ($b->rows as $n => $rowInput) {
                    [, $error] = $this->createExpected($tenant, $access, $rowInput, 'csv');
                    if ($error !== null) { $errors[] = ['row' => $n + 1, 'code' => $error]; } else { $created++; }
                }
                $audit('expected_device', $tenant, ['rows', 'created:' . $created]);
                return AdminApi::response(['created' => $created, 'errors' => $errors]);
            case 'cancelExpectedDevice':
                $e = $this->expected($tenant, $id, true);
                $access->user($e['user_id']);
                if ($e['status'] !== 'pending') { throw new ApiError(409, 'invalid_transition'); }
                $this->db->run('UPDATE expected_devices SET status=\'cancelled\',version=version+1 WHERE tenant_id=? AND id=?', [$tenant, $id]);
                $audit('expected_device', $id, ['status']);
                return AdminApi::response($this->expectedDto($this->expected($tenant, $id)));
        }
        // Solicitudes, claves y configuracion: un equipo sin identificar no tiene area/sede, solo alcance total.
        $access->requireFull();
        switch ($op) {
            case 'listEnrollmentRequests':
                $status = $_GET['status'] ?? null;
                return $this->page('SELECT * FROM enrollment_requests WHERE tenant_id=?' . ($status ? ' AND status=?' : '') . ' ORDER BY created_at DESC,id', [$tenant, ...($status ? [$status] : [])], fn ($x) => $this->requestDto($x));
            case 'approveEnrollmentRequest':
                $row = $this->request($tenant, $id);
                if ($row['status'] !== 'pending') { throw new ApiError(409, 'invalid_transition'); }
                $user = null; $manual = false;
                if (($b->person ?? null) !== null) {
                    [$user, $error] = $this->person($tenant, $access, $b->person);
                    if ($error !== null) { throw self::rowError($error); }
                    $manual = $user !== $row['suggested_user_id'];
                } else { $user = $row['suggested_user_id']; }
                if ($user === null || !$this->activeUser($tenant, $user)) { throw new ApiError(422, 'validation_failed'); }
                $expected = $row['expected_device_id'];
                if ($expected !== null) {
                    $e = $this->db->one('SELECT * FROM expected_devices WHERE tenant_id=? AND id=? AND status=\'pending\' FOR UPDATE', [$tenant, $expected]);
                    if (!$e || $e['user_id'] !== $user) { $expected = null; }
                }
                $asset = self::asset($b->asset_code ?? null) ?? ($expected !== null ? $row['asset_code'] : null);
                $this->db->run('UPDATE enrollment_requests SET status=\'approved\',user_id=?,asset_code=?,expected_device_id=?,match_kind=IF(?,\'manual\',match_kind),decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$user, $asset, $expected, (int) $manual, $c['principal_id'], $tenant, $id]);
                $audit('enrollment_request', $id, ['status', 'user_id', 'asset_code']);
                return AdminApi::response($this->requestDto($this->request($tenant, $id)));
            case 'rejectEnrollmentRequest':
                $row = $this->request($tenant, $id);
                if (!in_array($row['status'], ['pending', 'approved'], true)) { throw new ApiError(409, 'invalid_transition'); }
                if ($row['enrollment_id'] !== null) { $this->db->run('UPDATE enrollments SET status=\'revoked\' WHERE tenant_id=? AND id=? AND status=\'pending\'', [$tenant, $row['enrollment_id']]); }
                $this->db->run('UPDATE enrollment_requests SET status=\'rejected\',user_id=NULL,decided_by=?,decided_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$c['principal_id'], $tenant, $id]);
                $audit('enrollment_request', $id, ['status']);
                return AdminApi::response($this->requestDto($this->request($tenant, $id)));
            case 'getEnrollmentSettings':
                return AdminApi::response($this->settingsDto($this->settings($tenant)));
            case 'putEnrollmentSettings':
                $this->db->run('INSERT INTO enrollment_settings (tenant_id,auto_approve_serial_match,self_identify,self_identify_auto_confirm) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE auto_approve_serial_match=VALUES(auto_approve_serial_match),self_identify=VALUES(self_identify),self_identify_auto_confirm=VALUES(self_identify_auto_confirm),version=version+1',
                    [$tenant, (int) $b->auto_approve_serial_match, (int) $b->self_identify, (int) ($b->self_identify && $b->self_identify_auto_confirm)]);
                $audit('enrollment_settings', $tenant, array_keys(get_object_vars($b)));
                return AdminApi::response($this->settingsDto($this->settings($tenant)));
            case 'listEnrollmentKeys':
                return $this->page('SELECT * FROM enrollment_keys WHERE tenant_id=? ORDER BY created_at DESC,id', [$tenant], fn ($x) => $this->keyDto($x));
            case 'createEnrollmentKey':
                if ($c['reauthenticated_at'] === null || strtotime($c['reauthenticated_at'] . ' UTC') < time() - 300) { throw new ApiError(403, 'reauth_required'); }
                $secret = 'kek_' . Util::b64(random_bytes(32)); $kid = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO enrollment_keys (tenant_id,id,key_hash,hint) VALUES (?,?,?,?)', [$tenant, $kid, hash('sha256', $secret, true), substr($secret, 4, 6)]);
                $audit('enrollment_key', $kid, ['hint']);
                $k = $this->keyDto($this->db->one('SELECT * FROM enrollment_keys WHERE tenant_id=? AND id=?', [$tenant, $kid]));
                return AdminApi::response(['id' => $k['id'], 'key' => $secret, 'hint' => $k['hint'], 'created_at' => $k['created_at']], 201, ['Cache-Control' => 'no-store']);
            case 'revokeEnrollmentKey':
                $k = $this->db->one('SELECT * FROM enrollment_keys WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $id]);
                if (!$k) { throw new ApiError(404, 'resource_not_found'); }
                if ($k['revoked_at'] === null) { $this->db->run('UPDATE enrollment_keys SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$tenant, $id]); $audit('enrollment_key', $id, ['revoked_at']); }
                return AdminApi::response($this->keyDto($this->db->one('SELECT * FROM enrollment_keys WHERE tenant_id=? AND id=?', [$tenant, $id])));
        }
        throw new ApiError(404, 'resource_not_found');
    }

    // [id binario, null] o [null, codigo de error de fila].
    private function person(string $tenant, AdminAccess $access, string $person): array
    {
        $person = trim($person);
        if (str_contains($person, '@')) {
            $ids = array_column($this->db->run('SELECT id FROM users WHERE tenant_id=? AND email=? AND status=\'active\' LIMIT 2', [$tenant, $person])->fetchAll(), 'id');
        } else {
            $ids = $this->documentUsers($tenant, self::document($person));
        }
        if (count($ids) > 1) { return [null, 'person_ambiguous']; }
        if (!$ids) { return [null, 'person_not_found']; }
        try { $access->user($ids[0]); } catch (ApiError) { return [null, 'person_not_found']; }
        return [$ids[0], null];
    }

    // [id binario creado, null] o [null, codigo de error de fila].
    private function createExpected(string $tenant, AdminAccess $access, object $b, string $source): array
    {
        $asset = self::asset($b->asset_code ?? null); $serial = self::serial($b->serial_number ?? null);
        if ($asset === null && $serial === null) { return [null, 'missing_identifier']; }
        [$user, $error] = $this->person($tenant, $access, $b->person);
        if ($error !== null) { return [null, $error]; }
        if ($asset !== null && $this->db->one('SELECT id FROM expected_devices WHERE tenant_id=? AND pending_asset=?', [$tenant, $asset])) { return [null, 'duplicate_asset']; }
        if ($serial !== null && $this->db->one('SELECT id FROM expected_devices WHERE tenant_id=? AND pending_serial=?', [$tenant, $serial])) { return [null, 'duplicate_serial']; }
        $id = Util::bin(Util::uuid());
        $this->db->run('INSERT INTO expected_devices (tenant_id,id,user_id,asset_code,serial_number,source) VALUES (?,?,?,?,?,?)', [$tenant, $id, $user, $asset, $serial, $source]);
        return [$id, null];
    }

    // El codigo va en detail para que el panel diga exactamente que fallo (persona no encontrada, placa repetida...).
    private static function rowError(string $code): ApiError
    {
        return match ($code) {
            'person_not_found' => new ApiError(404, 'resource_not_found', [], $code),
            'missing_identifier' => new ApiError(422, 'validation_failed', [], $code),
            default => new ApiError(409, 'invalid_transition', [], $code),
        };
    }
    private function expected(string $tenant, string $id, bool $lock = false): array
    {
        $row = $this->db->one('SELECT e.*,u.display_name FROM expected_devices e JOIN users u ON u.tenant_id=e.tenant_id AND u.id=e.user_id WHERE e.tenant_id=? AND e.id=?' . ($lock ? ' FOR UPDATE' : ''), [$tenant, $id]);
        if (!$row) { throw new ApiError(404, 'resource_not_found'); }
        return $row;
    }
    private function request(string $tenant, string $id): array
    {
        $row = $this->db->one('SELECT * FROM enrollment_requests WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $id]);
        if (!$row) { throw new ApiError(404, 'resource_not_found'); }
        return $row;
    }
    private function page(string $sql, array $args, callable $map): array
    {
        $limit = (int) ($_GET['limit'] ?? 50); $offset = (int) ($_GET['offset'] ?? 0);
        $rows = $this->db->run($sql . ' LIMIT ? OFFSET ?', [...$args, $limit + 1, $offset])->fetchAll();
        $more = count($rows) > $limit; if ($more) { array_pop($rows); }
        return AdminApi::response(['data' => array_map($map, $rows), 'next_offset' => $more ? $offset + $limit : null]);
    }
    private function name(string $tenant, ?string $user): ?string
    {
        return $user === null ? null : ($this->db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$tenant, $user])['display_name'] ?? null);
    }
    private static function optId(?string $v): ?string { return $v === null ? null : Util::id($v); }
    private static function optTime(?string $v): ?string { return $v === null ? null : Util::time($v); }
    private function expectedDto(array $r): array
    {
        return ['id' => Util::id($r['id']), 'user_id' => Util::id($r['user_id']), 'user_name' => $r['display_name'], 'asset_code' => $r['asset_code'], 'serial_number' => $r['serial_number'],
            'status' => $r['status'], 'source' => $r['source'], 'device_id' => self::optId($r['device_id']), 'created_at' => Util::time($r['created_at'])];
    }
    private function requestDto(array $r): array
    {
        return ['id' => Util::id($r['id']), 'status' => $r['status'], 'hostname' => $r['hostname'], 'serial_number' => $r['serial_number'], 'agent_version' => $r['agent_version'],
            'claimed_document' => $r['claimed_document'], 'match_kind' => $r['match_kind'], 'alert' => $r['alert'],
            'user_id' => self::optId($r['user_id']), 'user_name' => $this->name($r['tenant_id'], $r['user_id']),
            'suggested_user_id' => self::optId($r['suggested_user_id']), 'suggested_user_name' => $this->name($r['tenant_id'], $r['suggested_user_id']),
            'asset_code' => $r['asset_code'], 'device_id' => self::optId($r['device_id']), 'created_at' => Util::time($r['created_at']),
            'last_seen_at' => Util::time($r['last_seen_at']), 'decided_at' => self::optTime($r['decided_at'])];
    }
    private function settingsDto(array $s): array
    {
        return ['auto_approve_serial_match' => (bool) $s['auto_approve_serial_match'], 'self_identify' => (bool) $s['self_identify'], 'self_identify_auto_confirm' => (bool) $s['self_identify_auto_confirm']];
    }
    private function keyDto(array $k): array
    {
        return ['id' => Util::id($k['id']), 'hint' => $k['hint'], 'created_at' => Util::time($k['created_at']), 'revoked_at' => self::optTime($k['revoked_at'])];
    }
}
