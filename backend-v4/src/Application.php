<?php
declare(strict_types=1);
namespace Keeper;

final class Application
{
    private Validator $validator;
    private RateLimiter $limiter;
    public function run(): void
    {
        $id = Util::uuid();
        $this->limiter = new RateLimiter();
        try {
            Config::key();
            $this->validator = new Validator();
            $r = new Request($this->validator); $id = $r->requestId;
            $db = new Database(); $auth = new Auth($db, new Signature(), $this->limiter);
            if ($r->route === '/oauth/token') { $response = $this->ok($auth->external()->issue($r)); }
            elseif (str_starts_with($r->route, '/ext/v1/')) {
                $c = $auth->external()->context($r);
                $response = $this->limiter->externalQueries($c, function () use ($db,$c,$r): array {
                    $db->run('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                    return $db->transaction(fn () => $this->ok((new ExternalApi($db, $c, $r, $this->validator))->dispatch(), 200, ['Cache-Control'=>'private, max-age=30', 'Vary'=>'Authorization, X-API-Key']));
                });
            }
            elseif (str_starts_with($r->route, '/auth/')) { $response = $auth->admin()->handle($r); }
            elseif (!str_starts_with($r->route, '/client/')) {
                $context = null;
                $response = $db->transaction(function () use ($db, $auth, $r, &$context): array {
                    $context = $auth->admin()->context($r);
                    $admin = new AdminApi($db, $context, $r, $this->validator);
                    $handler = fn () => $admin->dispatch();
                    $idempotent=in_array('#/components/parameters/Idempotency',array_column($r->operation['parameters']??[],'$ref'),true);
                    $result=$idempotent ? (new Idempotency($db))->execute($context, $r, $handler) : $handler();
                    if ($r->method!=='GET') { AdminApi::validatePending($db,$context['tenant_id']); }
                    return $result;
                });
                AdminApi::compilePending($db, $context['tenant_id']);
            }
            elseif ($r->route === '/client/auth/challenges') { $response = $this->ok($auth->challenge($r)); }
            elseif ($r->route === '/client/login') { $response = $this->ok($auth->login($r)); }
            else {
                // Commit proof consumption before business handling, including failed requests and replays.
                $c = $db->transaction(fn () => $auth->authenticate($r));
                $this->limiter->device($c);
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
            $this->send($response['status'], $response['json'], $id, $response['headers'] ?? []);
        } catch (OAuthError $e) {
            $this->send($e->status, Util::json(['error'=>$e->errorCode]), $id, ['Cache-Control'=>'no-store', 'Pragma'=>'no-cache'] + ($e->status === 401 ? ['WWW-Authenticate'=>'Basic realm="keeper-oauth"'] : []));
        } catch (ApiError $e) {
            if (explode('?',$_SERVER['REQUEST_URI']??'')[0]==='/v1/oauth/token' && in_array($e->status,[400,401],true)) {
                $this->send($e->status,Util::json(['error'=>$e->status===401?'invalid_client':'invalid_request']),$id,$e->status===401?['WWW-Authenticate'=>'Basic realm="keeper-oauth"']:[]);
                return;
            }
            $this->send($e->status, Util::json(['type' => 'about:blank', 'title' => $e->errorCode, 'status' => $e->status, 'code' => $e->errorCode, 'request_id' => $id] + ($e->detail===null?[]:['detail'=>$e->detail])), $id, $e->headers, true);
        } catch (\Throwable $e) {
            $status = $e instanceof \PDOException ? 503 : 500;
            // Exception messages may contain secrets; log only class, correlation ID and numeric driver metadata.
            error_log('keeper request=' . $id . ' failure=' . get_class($e) . ($e instanceof \PDOException ? ' sqlstate='.$e->getCode().' driver='.(int)($e->errorInfo[1]??0) : ''));
            $code = $status === 503 ? 'temporarily_unavailable' : 'internal_error';
            $this->send($status, Util::json(['type' => 'about:blank', 'title' => $code, 'status' => $status, 'code' => $code, 'request_id' => $id]), $id, $status === 503 ? ['Retry-After' => '5'] : [], true);
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
                return $this->ok(['acks' => $acks, 'received_at' => Util::now()]);
            case '/client/logs':
                Ingest::unique($b->entries);
                return $this->ok(['acks' => array_map(fn ($e) => $i->partial($e, fn () => $i->log($e)), $b->entries), 'received_at' => Util::now()]);
            case '/client/security/report': return $this->ok($i->security($b));
            case '/client/commands/{id}/result': return $this->ok($i->command($r->resourceId, $b));
            case '/client/check-ins': return $this->ok($i->checkIn($b), 201);
            case '/client/sync':
                Ingest::unique($b->episodes ?? []); Ingest::unique($b->logs ?? []);
                Ingest::unique(array_map(fn ($x) => $x->result, $b->command_results ?? []));
                [$version, $policy] = $resources->policy();
                $response = ['protocol_version' => 1, 'server_time' => Util::now(), 'next_sync_after_seconds' => 120, 'policy_version' => $version, 'policy' => $version > ($b->policy_version ?? 0) ? $policy : null, 'release' => null, 'commands' => [], 'episode_acks' => [], 'log_acks' => [], 'security_ack' => null, 'activity_ack' => null, 'command_acks' => []];
                foreach ($b->episodes ?? [] as $e) { $response['episode_acks'][] = $i->partial($e, fn () => $i->episode($e)); }
                foreach ($b->logs ?? [] as $e) { $response['log_acks'][] = $i->partial($e, fn () => $i->log($e)); }
                if (isset($b->security)) { $response['security_ack'] = $i->partial($b->security, fn () => $i->security($b->security)); }
                if (isset($b->activity)) { $response['activity_ack'] = $i->partial($b->activity, fn () => $i->activity($b->activity), 'snapshot_id'); }
                foreach ($b->command_results ?? [] as $entry) { $response['command_acks'][] = $i->partial($entry->result, fn () => $i->command($entry->command_id, $entry->result)); }
                $response['commands'] = $resources->commands()['data'];
                $release = $resources->release();
                $response['release'] = $release !== null && $release['id'] !== $b->release_id ? $release : null;
                if ($c['session_remaining'] < 600) {
                    $response['token'] = $auth->issue($c);
                    (new Audit($db))->record($c, $r, 'device.session_renewed', 'device', $c['device_id'], ['session']);
                }
                $json = Util::json($response);
                if (strlen($json) > 262144 && $response['policy'] !== null) { $response['policy'] = null; $json = Util::json($response); }
                $db->run('UPDATE device_sync_state SET last_sequence=GREATEST(last_sequence,?),updated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND enrollment_id=?', [$b->sequence, $c['tenant_id'], $c['device_id'], $c['enrollment_id']]);
                $db->run('UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$c['tenant_id'], $c['device_id']]);
                return ['status' => 200, 'json' => $json, 'headers' => []];
        }
        throw new ApiError(404, 'resource_not_found');
    }
    private function send(int $status, string $json, string $id, array $headers, bool $problem = false): void
    {
        http_response_code($status);
        header('X-Request-ID: ' . $id);
        header('Content-Type: ' . ($problem ? 'application/problem+json' : 'application/json'));
        header('Cache-Control: no-store');
        if ($status === 401) { header('WWW-Authenticate: Bearer realm="keeper-agent"'); }
        foreach ($headers + $this->limiter->headers as $key => $value) { foreach (is_array($value)?$value:[$value] as $line) { header($key . ': ' . $line,$key!=='Set-Cookie'); } }
        if ($status !== 304 && $status !== 204) { echo $json; }
    }
}
