<?php
declare(strict_types=1);
use Keeper\Util;

// 2026-10-19: un sync cortado por timeout se reintenta con la misma Idempotency-Key y recibia el body del primer intento,
// con su `server_time` viejo; el agente aprendia de ahi un desfase de reloj falso (~-120 s). El replay debe llevar la hora
// actual y dejar el resto del body (acks, commands, policy) identico.
function withoutServerTime(string $body): string { $d = json_decode($body, true); unset($d['server_time']); return Util::json($d); }

function idempotentReplayTimeTests(array $sync, array $key, string $bearer): void
{
    $idem = Util::uuid();
    $first = request('POST', '/client/sync', $sync, $key, $bearer, $idem);
    $a = expect($first, 200, 'replay-time: sync original');
    sleep(2);
    $replay = request('POST', '/client/sync', $sync, $key, $bearer, $idem);
    $b = expect($replay, 200, 'replay-time: replay');
    $t0 = strtotime($a['server_time']); $t1 = strtotime($b['server_time']);
    check($t1 >= $t0 + 2, 'replay-time: server_time del replay avanza respecto al original');
    check(abs(time() - $t1) <= 2, 'replay-time: server_time del replay es la hora actual');
    check(withoutServerTime($replay[3]) === withoutServerTime($first[3]), 'replay-time: el resto del body (acks, commands, policy) es identico');
}
