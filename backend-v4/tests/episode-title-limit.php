<?php
declare(strict_types=1);
use Keeper\{ApiError,Validator};

// ACT-0033 (2026-10-15): el agente acepta titulos de ventana hasta 1024 caracteres (SessionProtocol) y el contrato
// solo 512; un titulo largo rechazaba el sync entero con 422 y el agente reenviaba el mismo paquete para siempre.
// El contrato debe aceptar lo mismo que el agente; el registro del 422 debe decir campo y regla, nunca el valor.
function episodeTitleLimitTests(): void
{
    $validator = new Validator();
    $episode = static fn (string $title) => (object) ['event_id' => '11111111-1111-4111-8111-111111111111', 'started_at' => '2026-10-15T10:00:00Z',
        'ended_at' => '2026-10-15T10:01:00Z', 'process_name' => 'chrome', 'window_title' => $title, 'active_seconds' => 30, 'idle_seconds' => 30];
    $ok = static function (object $e) use ($validator): bool { try { $validator->named('Episode', $e); return true; } catch (ApiError) { return false; } };
    check($ok($episode(str_repeat('á', 1024))), 'titulo de 1024 caracteres (tope del agente) se acepta');
    check(!$ok($episode(str_repeat('a', 1025))), 'titulo de 1025 caracteres se rechaza');
    check(($validator->lastFailure ?? null) === ['pointer' => '/window_title', 'rule' => 'length'], 'el rechazo dice campo y regla');
    check(!str_contains(json_encode($validator->lastFailure), 'aaaa'), 'el rechazo no incluye el valor');
}
