<?php
declare(strict_types=1);
namespace Keeper;

final class Application
{
    private Validator $validator;
    private RateLimiter $limiter;
    /** Cabeceras CORS de /portal/v1 (solo origenes autorizados); vacio en el resto. */
    private array $cors = [];
    private bool $portal = false;
    public function run(): void
    {
        $id = Util::uuid();
        $this->limiter = new RateLimiter();
        try {
            Config::key();
            $db = null;
            // Portal: CORS antes que nada (tambien los errores lo llevan) y la consulta previa del navegador sin autenticar.
            if (str_starts_with(explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0], Portal::PREFIX . '/')) {
                $this->portal = true; $db = new Database();
                $this->cors = Portal::cors($db, $_SERVER['HTTP_ORIGIN'] ?? '');
                if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { $this->send(204, '', $id, ['Cache-Control' => 'no-store']); return; }
            }
            $this->validator = new Validator();
            $r = new Request($this->validator); $id = $r->requestId;
            $db ??= new Database(); $auth = new Auth($db, new Signature(), $this->limiter);
            if ($r->route === '/oauth/token') { $response = $this->ok($auth->external()->issue($r)); }
            elseif (str_starts_with($r->route, '/ext/v1/')) {
                $c = $auth->external()->context($r);
                $response = $this->limiter->externalQueries($c, function () use ($db,$c,$r): array {
                    // Escrituras externas: equipos esperados (expected-devices:write) y entradas por puerta (check-ins:write).
                    if ($r->method === 'POST') { return $db->transaction(fn () => $this->ok((new ExternalApi($db, $c, $r, $this->validator))->dispatch(), 200, ['Cache-Control'=>'no-store'])); }
                    // Metricas por periodo (ExternalMetrics): sus tablas temporales se crean antes; dentro solo se llenan.
                    if (isset($_GET['from'])) { ProductivityFormula::prepare($db); }
                    $db->run('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                    return $db->transaction(fn () => $this->ok((new ExternalApi($db, $c, $r, $this->validator))->dispatch(), 200, ['Cache-Control'=>'private, max-age=30', 'Vary'=>'Authorization, X-API-Key']));
                });
            }
            elseif ($r->portal) {
                // Misma API, otra puerta: sesion propia del Portal o la operacion del panel con el contexto del token.
                $portal = new Portal($db, $this->limiter);
                $response = str_starts_with($r->route, Portal::PREFIX . '/')
                    ? $this->afterRollback($db, fn () => $db->transaction(fn () => $portal->session($r)))
                    : $this->admin($db, $r, fn () => $portal->context($r), $id);
            }
            elseif (str_starts_with($r->route, '/auth/')) { $response = $auth->admin()->handle($r); }
            elseif (!str_starts_with($r->route, '/client/')) { $response = $this->admin($db, $r, fn () => $auth->admin()->context($r), $id); }
            // Modo mudado (Relocations): challenge y login de un equipo ya mudado responden 409 device_relocated sin tocar nada.
            elseif ($r->route === '/client/auth/challenges') { (new Relocations($db))->enforceBootstrap($r); $response = $this->ok($auth->challenge($r)); }
            elseif ($r->route === '/client/login') { (new Relocations($db))->enforceBootstrap($r); $response = $this->ok($auth->login($r)); }
            elseif ($r->route === '/client/enrollment-requests') { $response = $this->ok((new DeviceIntake($db))->client($r, new Signature(), $this->limiter), 200, ['Cache-Control' => 'no-store']); }
            else {
                // Commit proof consumption before business handling, including failed requests and replays.
                $c = $db->transaction(fn () => $auth->authenticate($r));
                $this->limiter->device($c);
                // Modo mudado: 409 antes de cualquier ingesta; el agente conserva su cola y la reenvia al destino.
                (new Relocations($db))->enforce($c['tenant_id'], $c['device_id'], $c['agent_version']);
                // Un device recién enrolado aún no tiene política compilada; el primer sync la
                // provisiona on-demand (recompile abre su propia transacción) en vez de responder 503.
                if ($r->route === '/client/sync' &&
                    !$db->one('SELECT 1 present FROM effective_policies WHERE tenant_id=? AND device_id=? LIMIT 1', [$c['tenant_id'], $c['device_id']])) {
                    (new PolicyCompiler($db))->recompile(Util::id($c['device_id']), Util::id($c['tenant_id']));
                }
                $response = $db->transaction(function () use ($db, $auth, $r): array {
                    $c = $auth->authenticate($r, false);
                    $handler = fn () => $this->dispatch($db, $auth, $c, $r);
                    return $r->method === 'POST' ? (new Idempotency($db))->execute($c, $r, $handler) : $handler();
                });
            }
            if (Config::get('DEBUG') === '1') {
                $schema = $r->operation['responses'][(string) $response['status']]['content']['application/json']['schema'] ?? null;
                if ($schema !== null) {
                    try { $this->validator->check($schema, json_decode($response['json'])); }
                    catch (ApiError) { throw new \RuntimeException('Invalid response contract'); }
                }
            }
            $this->send($response['status'], $response['json'], $id, $response['headers'] ?? [], false, $response['stream'] ?? null, $response['undelivered'] ?? null);
        } catch (OAuthError $e) {
            $this->send($e->status, Util::json(['error'=>$e->errorCode]), $id, ['Cache-Control'=>'no-store', 'Pragma'=>'no-cache'] + ($e->status === 401 ? ['WWW-Authenticate'=>'Basic realm="keeper-oauth"'] : []));
        } catch (ApiError $e) {
            // Un 422 del agente bloquea su cola entera (reintenta el mismo envio): registrar campo y regla, nunca valores.
            // Desde 4.0.25 el agente lee Problem.errors[0] (pointer + regla) para apartar solo ese elemento y enviar el resto.
            // Va a todas las versiones: ningun agente lee el cuerpo de un 422 (solo el de un 409 device_relocated), y el
            // cuerpo se valida antes de autenticar, cuando aun no se sabe la version del agente.
            $errors = $e->errors;
            if ($e->status === 401 && str_starts_with(explode('?', $_SERVER['REQUEST_URI'] ?? '')[0], '/v1/client/')) {
                error_log('keeper client_unauthorized request=' . $id . ' route=' . explode('?', $_SERVER['REQUEST_URI'] ?? '')[0] . ' code=' . $e->errorCode
                    . (Signature::$lastReason !== null ? ' motivo=' . Signature::$lastReason : ''));
            }
            if ($e->status === 422 && str_starts_with(explode('?', $_SERVER['REQUEST_URI'] ?? '')[0], '/v1/client/')) {
                $where = $e->errorCode === 'validation_failed' ? ($e->bodyFailure ?? []) : [];
                error_log('keeper client_rejected request=' . $id . ' route=' . explode('?', $_SERVER['REQUEST_URI'] ?? '')[0] . ' code=' . $e->errorCode
                    . (isset($where['pointer']) ? ' pointer=' . $where['pointer'] . ' rule=' . $where['rule'] : ''));
                if (isset($where['pointer']) && count($errors) < 50) {
                    $rule = mb_substr($where['rule'], 0, 80, 'UTF-8');
                    $errors[] = ['pointer' => mb_substr($where['pointer'], 0, 160, 'UTF-8'), 'code' => $rule,
                        'message' => mb_substr('El campo no cumple la regla ' . $rule . ' del contrato (el valor no se incluye).', 0, 200, 'UTF-8')];
                }
            }
            if (explode('?',$_SERVER['REQUEST_URI']??'')[0]==='/v1/oauth/token' && in_array($e->status,[400,401],true)) {
                $this->send($e->status,Util::json(['error'=>$e->status===401?'invalid_client':'invalid_request']),$id,$e->status===401?['WWW-Authenticate'=>'Basic realm="keeper-oauth"']:[]);
                return;
            }
            $this->send($e->status, Util::json(['type' => 'about:blank', 'title' => $e->errorCode, 'status' => $e->status, 'code' => $e->errorCode, 'request_id' => $id] + ($e->detail===null?[]:['detail'=>$e->detail]) + ($errors===[]?[]:['errors'=>$errors]) + $e->extra), $id, $e->headers, true);
        } catch (\Throwable $e) {
            $status = $e instanceof \PDOException ? 503 : 500;
            // Exception messages may contain secrets; log only class, correlation ID and numeric driver metadata.
            error_log('keeper request=' . $id . ' failure=' . get_class($e) . ($e instanceof \PDOException ? ' sqlstate='.$e->getCode().' driver='.(int)($e->errorInfo[1]??0) : ''));
            $code = $status === 503 ? 'temporarily_unavailable' : 'internal_error';
            $this->send($status, Util::json(['type' => 'about:blank', 'title' => $code, 'status' => $status, 'code' => $code, 'request_id' => $id]), $id, $status === 503 ? ['Retry-After' => '5'] : [], true);
        }
    }
    // Revocacion tras commit de lo que no se entrego; nunca relanza (la respuesta ya pudo salir). Sin secretos en el log.
    private function undelivered(callable $fn, string $id): void
    {
        try { $fn(); error_log('keeper request=' . $id . ' installer_undelivered key_revoked'); }
        catch (\Throwable $e) { error_log('keeper request=' . $id . ' installer_undelivered revoke_failed=' . get_class($e)); }
    }
    /** Operacion del panel (AdminApi) con el contexto de la puerta que corresponda (cookie del panel o token del Portal). */
    private function admin(Database $db, Request $r, callable $authenticate, string $id): array
    {
        $context = null; $admin = null;
        $response = $this->afterRollback($db, function () use ($db, $r, $authenticate, &$context, &$admin): array {
            return $db->transaction(function () use ($db, $r, $authenticate, &$context, &$admin): array {
                $context = $authenticate();
                $admin = new AdminApi($db, $context, $r, $this->validator);
                $handler = fn () => $admin->dispatch();
                $idempotent=in_array('#/components/parameters/Idempotency',array_column($r->operation['parameters']??[],'$ref'),true);
                $result=$idempotent ? (new Idempotency($db))->execute($context, $r, $handler) : $handler();
                if ($r->method!=='GET') { AdminApi::validatePending($db,$context['tenant_id'],$admin->recompileScope()); }
                return $result;
            });
        });
        try { if (!in_array($r->operation['operationId'],['getSupervisorOverview','getSupervisorPersonSummary'],true)) { AdminApi::compilePending($db, $context['tenant_id'], $admin?->recompileScope()); } }
        catch (\Throwable $e) { if (isset($response['stream'])) { fclose($response['stream']['handle']); } if (isset($response['undelivered'])) { $this->undelivered($response['undelivered'], $id); } throw $e; }
        return $response;
    }
    // Lo que una denegacion debe dejar guardado aunque la peticion haga rollback (auditoria, revocaciones). Nunca tapa el error.
    private function afterRollback(Database $db, callable $fn): array
    {
        try { return $fn(); }
        catch (ApiError $e) {
            if ($e->afterRollback !== null) {
                try { $db->transaction($e->afterRollback); }
                catch (\Throwable $x) { error_log('keeper after_rollback_failed=' . get_class($x)); }
            }
            throw $e;
        }
    }
    private function ok(mixed $body, int $status = 200, array $headers = []): array { return ['status' => $status, 'json' => Util::json($body), 'headers' => $headers]; }
    private function dispatch(Database $db, Auth $auth, array $c, Request $r): array
    {
        $i = new Ingest($db, $c, $r); $resources = new Resources($db, $c, $this->validator); $b = $r->body;
        switch ($r->route) {
            case '/client/hardening': return $this->ok((new Hardening($db, $c, $r))->clientSettings());
            case '/client/hardening/report': return $this->ok((new Hardening($db, $c, $r))->report());
            case '/client/policy':
                [$version, $policy] = $resources->policy();
                $etag = '"' . $version . '"';
                $matches = array_map('trim', explode(',', $r->header('if-none-match')));
                $unchanged = in_array($etag, $matches, true) || in_array('W/' . $etag, $matches, true) || in_array('*', $matches, true);
                return $this->ok($unchanged ? null : $policy, $unchanged ? 304 : 200, ['ETag' => $etag, 'Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization']);
            case '/client/commands':
                $limit = $_GET['limit'] ?? '50';
                if (!ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100) { throw new ApiError(422, 'validation_failed'); }
                return $this->ok($resources->commands((int) $limit, $_GET['cursor'] ?? null));
            case '/client/releases/{id}':
                $release = $resources->release($r->resourceId);
                if (!$release) { throw new ApiError(404, 'resource_not_found'); }
                return $this->ok($release);
            case '/client/episodes:batch':
                Ingest::unique($b->episodes);
                $acks = array_map(fn ($e) => $i->partial($e, fn () => $i->episode($e)), $b->episodes);
                $i->flushDays();
                return $this->ok(['acks' => $acks, 'received_at' => Util::now()]);
            case '/client/logs':
                Ingest::unique($b->entries);
                return $this->ok(['acks' => array_map(fn ($e) => $i->partial($e, fn () => $i->log($e)), $b->entries), 'received_at' => Util::now()]);
            case '/client/security/report': return $this->ok($i->security($b));
            case '/client/commands/{id}/result': return $this->ok($i->command($r->resourceId, $b));
            case '/client/check-ins': return $this->ok($i->checkIn($b), 201);
            case '/client/sync':
                Ingest::unique($b->episodes ?? []); Ingest::unique($b->logs ?? []); Ingest::unique($b->locations ?? []);
                Ingest::unique(array_map(fn ($x) => $x->result, $b->command_results ?? []));
                [$version, $policy] = $resources->policy();
                $response = ['protocol_version' => 1, 'server_time' => Util::now(), 'next_sync_after_seconds' => 120, 'policy_version' => $version, 'policy' => $version > ($b->policy_version ?? 0) ? $policy : null, 'release' => null, 'commands' => [], 'episode_acks' => [], 'log_acks' => [], 'security_ack' => null, 'activity_ack' => null, 'command_acks' => []];
                if (isset($b->locations)) {
                    $locations=new DeviceLocations($db);
                    $response['location_acks']=array_map(fn($p)=>$i->partial($p,fn()=>$locations->ingest($c,$r,$p)),$b->locations);
                }
                foreach ($b->episodes ?? [] as $e) { $response['episode_acks'][] = $i->partial($e, fn () => $i->episode($e)); }
                foreach ($b->logs ?? [] as $e) { $response['log_acks'][] = $i->partial($e, fn () => $i->log($e)); }
                if (isset($b->security)) { $response['security_ack'] = $i->partial($b->security, fn () => $i->security($b->security)); }
                if (isset($b->activity)) { $response['activity_ack'] = $i->partial($b->activity, fn () => $i->activity($b->activity), 'snapshot_id'); }
                foreach ($b->command_results ?? [] as $entry) { $response['command_acks'][] = $i->partial($entry->result, fn () => $i->command($entry->command_id, $entry->result)); }
                $response['commands'] = $resources->commands()['data'];
                if (version_compare($c['agent_version'], '4.0.17', '>=')) {
                    $response['unlock_pin'] = (new UnlockPin($db, $c, $r))->clientSettings();
                }
                // Desde 4.0.19 (los anteriores rechazan campos desconocidos): el agente apaga Smart App Control si el cliente lo pidio.
                if (version_compare($c['agent_version'], '4.0.19', '>=')) {
                    $response['windows_security'] = (new WindowsSecurity($db, $c, $r))->clientSettings();
                }
                // Apagado programado: solo agentes >= 4.0.30 (los anteriores rechazan campos desconocidos y dejarian de sincronizar).
                // Un agente viejo que enviara power_schedule_runs se ignora: ni se guardan ni se confirman.
                if (version_compare($c['agent_version'], PowerSchedules::MIN_AGENT, '>=')) {
                    $power = new PowerSchedules($db, $c, null, $r);
                    if (!empty($b->power_schedule_runs)) { $response['power_schedule_acks'] = $power->ingestRuns($c['device_id'], $b->power_schedule_runs); }
                    $response['power_schedules'] = $power->clientSchedules($c['device_id']);
                }
                // Ubicación: ventanas de toma (horario ± 30 min, 48 h) y caso perdido/robado; sin el campo el agente no toma nada.
                if (version_compare($c['agent_version'], PolicyCompiler::LOCATION_MIN_AGENT, '>=')
                    && ($location = (new DeviceLocations($db))->directive($c['tenant_id'], $c['device_id'])) !== null) { $response['location'] = $location; }
                // Desde 4.0.24: mudanza de servidor firmada que aplica a este equipo (el agente la verifica y decide).
                if (($relocation = (new Relocations($db))->deliver($c)) !== null) { $response['relocation'] = $relocation; }
                // Una release no verificable (p. ej. release-keys.json desalineado) se RETIENE, no tumba el sync:
                // si lanzara aqui, toda la flota del tenant perderia politica, comandos e ingesta hasta corregirla.
                // GET /client/releases/{id} sigue respondiendo 503 para diagnostico.
                try { $release = $resources->release(); }
                catch (ApiError) { $release = null; error_log('keeper: release_withheld (manifest no verificable) tenant=' . Util::id($c['tenant_id'])); }
                $response['release'] = $release !== null && $release['id'] !== $b->release_id ? $release : null;
                if ($c['session_remaining'] < 600) {
                    $response['token'] = $auth->issue($c);
                    (new Audit($db))->record($c, $r, 'device.session_renewed', 'device', $c['device_id'], ['session']);
                }
                $json = Util::json($response);
                if (strlen($json) > 262144 && $response['policy'] !== null) { $response['policy'] = null; $json = Util::json($response); }
                $db->run('UPDATE device_sync_state SET last_sequence=GREATEST(last_sequence,?),updated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND enrollment_id=?', [$b->sequence, $c['tenant_id'], $c['device_id'], $c['enrollment_id']]);
                // Inventario declarado (primer sync de cada arranque o cuando cambia). No toca devices.version (ETag
                // de edicion del panel). La arquitectura va a specs: la usa la oferta de releases.
                if (isset($b->inventory)) {
                    $inv = $b->inventory;
                    // hostname: tras un renombre + reinicio el agente reutiliza su token y no pasa por /client/login.
                    // legacy_k3 (K3 residual, ya validado por el contrato): objeto JSON {running,autostart,installed}; sin el campo queda null.
                    $legacyK3 = isset($inv->legacy_k3) ? json_encode(['running' => $inv->legacy_k3->running, 'autostart' => $inv->legacy_k3->autostart, 'installed' => $inv->legacy_k3->installed]) : null;
                    $db->run('UPDATE devices SET os_edition=?,os_build=?,cpu=?,ram_bytes=?,disk_bytes=?,hostname=COALESCE(?,hostname),specs=JSON_SET(COALESCE(specs,JSON_OBJECT()),\'$.architecture\',?,\'$.serial_number\',?,\'$.smart_app_control\',?,\'$.legacy_k3\',JSON_EXTRACT(?,\'$\')) WHERE tenant_id=? AND id=?',
                        [$inv->os_edition, $inv->os_build ?? null, $inv->cpu, $inv->ram_bytes, $inv->disk_bytes ?? null, $inv->hostname ?? null, $inv->architecture, $inv->serial_number ?? null, $inv->smart_app_control ?? null, $legacyK3, $c['tenant_id'], $c['device_id']]);
                }
                // policy_version del request = la que el agente tiene APLICADA. Se guarda solo si existe en el servidor
                // (1..version compilada) y sin tocar devices.version, que es el ETag de edicion del panel.
                $applied = isset($b->policy_version) && $b->policy_version > 0 && $b->policy_version <= $version ? (int) $b->policy_version : null;
                $db->run('UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6),policy_version=COALESCE(?,policy_version) WHERE tenant_id=? AND id=?', [$applied, $c['tenant_id'], $c['device_id']]);
                // Dias a recalcular: lo ultimo del envio, para tener tomada la fila de rollup_days el menor tiempo posible.
                $i->flushDays();
                return ['status' => 200, 'json' => $json, 'headers' => []];
        }
        throw new ApiError(404, 'resource_not_found');
    }
    // $stream: descarga binaria (instalador) ['handle', 'prefix', 'tail']: los primeros 'prefix' bytes del archivo abierto y
    // luego 'tail', sin cargarlo en memoria ni pasar por disco; la salida empieza enseguida (Timeout de Apache por inactividad).
    // $undelivered: se llama si no llega completo (la peticion ya hizo commit).
    private function send(int $status, string $json, string $id, array $headers, bool $problem = false, ?array $stream = null, ?callable $undelivered = null): void
    {
        if ($stream !== null) {
            $h = $stream['handle']; $left = $stream['prefix']; $sent = false;
            try {
                ignore_user_abort(true); // si el cliente corta, seguir hasta poder revocar la clave
                http_response_code($status); header('X-Request-ID: ' . $id); header('Content-Length: ' . ($left + strlen($stream['tail'])));
                foreach ($headers + $this->limiter->headers + $this->cors as $key => $value) { foreach (is_array($value) ? $value : [$value] as $line) { header($key . ': ' . $line, $key !== 'Set-Cookie'); } }
                while (ob_get_level() > 0) { ob_end_clean(); }
                if (fseek($h, 0) === 0) {
                    while ($left > 0 && !connection_aborted()) {
                        $chunk = fread($h, min($left, 1048576));
                        if ($chunk === false || $chunk === '') { break; }
                        echo $chunk; flush(); $left -= strlen($chunk);
                    }
                    if ($left === 0 && !connection_aborted()) { echo $stream['tail']; flush(); $sent = !connection_aborted(); }
                }
            } finally {
                fclose($h);
                if (!$sent && $undelivered !== null) { $this->undelivered($undelivered, $id); }
            }
            return;
        }
        http_response_code($status);
        header('X-Request-ID: ' . $id);
        header('Content-Type: ' . ($problem ? 'application/problem+json' : 'application/json'));
        header('Cache-Control: no-store');
        if ($status === 401) { header('WWW-Authenticate: Bearer realm="' . ($this->portal ? 'keeper-portal' : 'keeper-agent') . '"'); }
        foreach ($headers + $this->limiter->headers + $this->cors as $key => $value) { foreach (is_array($value)?$value:[$value] as $line) { header($key . ': ' . $line,$key!=='Set-Cookie'); } }
        if ($status !== 304 && $status !== 204) { echo $json; }
    }
}
