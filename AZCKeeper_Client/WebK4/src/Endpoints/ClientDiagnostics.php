<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\DiagnosticRepo;

/**
 * Recibe un snapshot de diagnostico del cliente (modo diagnostico activo). Auth por bearer
 * + el device debe pertenecer a la sesion (mismo candado que el handshake).
 *
 * Guardas:
 *  - Si la persona NO tiene sesion de diagnostico activa -> 409. Asi un cliente que no se
 *    entero de que lo apagaron (o cuya sesion vencio) deja de escribir; no acumula.
 *  - El payload se acota a 64 KB; si excede, se recorta logs[] (lo mas voluminoso) antes de
 *    guardar. La telemetria no debe poder inflar una fila sin limite.
 */
class ClientDiagnostics
{
    private const MAX_PAYLOAD_BYTES = 65536;

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $deviceGuid = $body['deviceId'] ?? ($body['DeviceId'] ?? null);
        if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
        }

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);

        $userId = (int)$sess['user_id'];

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(403, ['ok' => false, 'error' => 'Device not enrolled']);
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        $deviceId = (int)$dev['id'];

        // Sin diagnostico activo -> 409: el cliente debe dejar de subir.
        if (!DiagnosticRepo::activeSession($pdo, $userId)) {
            Http::json(409, ['ok' => false, 'error' => 'diagnostics not enabled']);
        }

        $payload = $body['payload'] ?? null;
        if (!is_array($payload)) Http::json(400, ['ok' => false, 'error' => 'Missing payload']);

        // Recorte si excede el tope: se sacrifica logs[] (lo mas grande) primero.
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
            $payload['logs'] = ['_truncated' => 'payload > 64KB'];
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) > self::MAX_PAYLOAD_BYTES) {
                $json = substr($json, 0, self::MAX_PAYLOAD_BYTES); // ultimo recurso (JSON invalido evitado abajo)
                $json = json_encode(['_truncated' => 'payload > 64KB']);
            }
        }

        $clientTs = self::normalizeTs($body['clientTs'] ?? null);
        DiagnosticRepo::insertSnapshot($pdo, $userId, $deviceId, $clientTs, $json);

        Http::json(200, ['ok' => true]);
    }

    /** Acepta ISO-8601 y lo pasa a 'Y-m-d H:i:s'; cualquier cosa rara -> null. */
    private static function normalizeTs($ts): ?string
    {
        if (!is_string($ts) || $ts === '') return null;
        $t = strtotime($ts);
        return $t !== false ? date('Y-m-d H:i:s', $t) : null;
    }
}
