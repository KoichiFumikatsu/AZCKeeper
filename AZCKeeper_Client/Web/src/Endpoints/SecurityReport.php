<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\AuthService;
use Keeper\Repos\SecurityStateRepo;

/**
 * SecurityReport — recibe el estado de controles de seguridad observado en el equipo.
 *
 * Payload:
 *   {
 *     "deviceId": "<guid>",
 *     "agentPresent": false,
 *     "controls": { "<clave>": { "present": bool, "value": <int|string|null> }, ... }
 *   }
 *
 * En Fase 0 el cliente solo LEE el registro; no aplica nada. agentPresent viaja
 * en false hasta que exista AZCKeeperAgent (Fase 1).
 */
class SecurityReport
{
    private const MAX_CONTROLS = 100;

    /**
     * Tope de longitud para cada clave y cada valor dentro de "controls". El
     * catalogo real (ver SecurityStateReader del cliente) son 19 controles con
     * forma {present: bool, value: int|string|null}: nombres de clave cortos y
     * valores pequeños (version strings, codigos de estado). MAX_CONTROLS por si
     * solo limita el NUMERO de claves de primer nivel, no su tamaño; un cliente
     * comprometido podria meter strings enormes dentro de esas 100 claves y
     * llenar la columna LONGTEXT. Truncar aca acota el tamaño maximo posible del
     * payload serializado, con el mismo enfoque que ClientLogBatch::sanitize().
     */
    private const MAX_KEY_LEN   = 128;
    private const MAX_VALUE_LEN = 512;

    public static function handle(): void
    {
        $sess   = AuthService::requireSession();
        $userId = (int)$sess['user_id'];

        $data = Http::jsonInput();

        $deviceGuid   = $data['deviceId']     ?? ($data['DeviceId']     ?? null);
        $agentPresent = $data['agentPresent'] ?? ($data['AgentPresent'] ?? false);
        $controls     = $data['controls']     ?? ($data['Controls']     ?? null);

        if (!$deviceGuid) {
            Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        }
        if (!is_array($controls)) {
            Http::json(400, ['ok' => false, 'error' => 'Missing or invalid controls object']);
        }
        if (count($controls) > self::MAX_CONTROLS) {
            Http::json(413, ['ok' => false, 'error' => 'Too many controls (max ' . self::MAX_CONTROLS . ')']);
        }

        $pdo = Db::pdo();

        $st = $pdo->prepare("SELECT id, user_id, status FROM keeper_devices WHERE device_guid = :g LIMIT 1");
        $st->execute([':g' => $deviceGuid]);
        $dev = $st->fetch();

        if (!$dev) {
            Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        }
        if ((int)$dev['user_id'] !== $userId) {
            Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        }
        if (($dev['status'] ?? 'active') !== 'active') {
            Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        }

        $controls = self::sanitizeControls($controls);

        try {
            $changed = SecurityStateRepo::upsert(
                $pdo,
                $userId,
                (int)$dev['id'],
                (bool)$agentPresent,
                $controls
            );
            Http::json(200, ['ok' => true, 'changed' => $changed]);
        } catch (\JsonException $e) {
            // $controls ya fue saneado arriba, asi que esto solo dispara con UTF-8
            // invalido dentro de un valor de string (p.ej. lectura corrupta del
            // registro de Windows). No se llego a hashear ni a persistir nada.
            error_log("SecurityReport JSON encode error (device={$deviceGuid}): " . $e->getMessage());
            Http::json(400, ['ok' => false, 'error' => 'Invalid character encoding in controls payload']);
        } catch (\PDOException $e) {
            error_log("SecurityReport UPSERT error: " . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database write failed']);
        }
    }

    /**
     * Trunca claves y valores anomalamente grandes antes de persistir. Mismo
     * enfoque que ClientLogBatch::sanitize() (truncar en vez de rechazar el
     * batch entero), adaptado a la forma {present, value} en vez de a un
     * mensaje de log libre. Los valores fuera del contrato int|string|null
     * (arrays/objetos anidados, floats) se descartan a null: el cliente legitimo
     * nunca los produce, y persistirlos tal cual es lo que permite inflar la
     * columna LONGTEXT.
     */
    private static function sanitizeControls(array $controls): array
    {
        $clean = [];

        foreach ($controls as $key => $entry) {
            $key = is_string($key) ? mb_substr($key, 0, self::MAX_KEY_LEN, 'UTF-8') : (string)$key;

            if (!is_array($entry)) {
                $clean[$key] = ['present' => false, 'value' => null];
                continue;
            }

            $present = (bool)($entry['present'] ?? false);
            $value   = $entry['value'] ?? null;

            if (is_string($value)) {
                $value = mb_substr($value, 0, self::MAX_VALUE_LEN, 'UTF-8');
            } elseif (!is_int($value) && !is_bool($value) && $value !== null) {
                // float, array, object: fuera de int|string|null -> anomalo, se descarta.
                $value = null;
            }

            $clean[$key] = ['present' => $present, 'value' => $value];
        }

        return $clean;
    }
}
