<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\SecurityStateRepo;

/**
 * Ingesta de estado de seguridad reportado por el agente Keeper: presencia
 * del agente y estado de los controles del SO (AV, firewall, disco cifrado,
 * etc). El payload de controles es libre (clave/valor definidos por el
 * agente), asi que se sanea agresivamente antes de persistir.
 */
class SecurityReport
{
    private const MAX_KEYS      = 100;
    private const MAX_KEY_LEN   = 128;
    private const MAX_VALUE_LEN = 512;

    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $userId = (int)$sess['user_id'];

        $deviceGuid   = $body['deviceId'] ?? null;
        $agentPresent = $body['agentPresent'] ?? null;
        $controls     = $body['controls'] ?? null;
        // Opcional: reporte del agente ELEVADO que el cliente transporta (courier).
        // Ausente => sin agente / no reporta => columnas del agente quedan NULL.
        $agentRaw     = $body['agentEnforcement'] ?? null;

        if (!$deviceGuid) Http::json(400, ['ok' => false, 'error' => 'Missing deviceId']);
        if (!is_bool($agentPresent)) Http::json(400, ['ok' => false, 'error' => 'Missing or invalid agentPresent']);
        if (!is_array($controls)) Http::json(400, ['ok' => false, 'error' => 'Missing or invalid controls']);
        if ($agentRaw !== null && !is_array($agentRaw)) Http::json(400, ['ok' => false, 'error' => 'Invalid agentEnforcement']);

        $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
        if (!$dev) Http::json(404, ['ok' => false, 'error' => 'Device not found']);
        if ((int)$dev['user_id'] !== $userId) Http::json(403, ['ok' => false, 'error' => 'Device does not belong to session user']);
        if (($dev['status'] ?? 'active') !== 'active') Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
        $deviceId = (int)$dev['id'];

        $clean = self::sanitizeControls($controls);
        $agent = $agentRaw === null ? null : self::sanitizeAgent($agentRaw);

        try {
            $changed = SecurityStateRepo::upsert($pdo, $userId, $deviceId, $agentPresent, $clean, $agent);
            Http::json(200, ['ok' => true, 'changed' => $changed]);
        } catch (\JsonException $e) {
            Http::json(400, ['ok' => false, 'error' => 'Invalid controls encoding']);
        } catch (\PDOException $e) {
            error_log('SecurityReport upsert error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database upsert failed']);
        }
    }

    /**
     * Sanea el payload libre de controles antes de persistirlo:
     * - maximo MAX_KEYS claves (el resto se descarta silenciosamente)
     * - claves truncadas a MAX_KEY_LEN
     * - valores permitidos: int, string, null, o array de strings (lista)
     * - strings (sueltos o dentro de la lista) truncados a MAX_VALUE_LEN
     * - arrays asociativos anidados se descartan (solo se aceptan listas planas);
     *   distinguir lista de asociativo con array_values($v) === $v
     * - cualquier otro tipo (bool, float, objeto, array anidado no-lista) se descarta
     */
    private static function sanitizeControls(array $controls): array
    {
        $out = [];
        $count = 0;
        foreach ($controls as $key => $value) {
            if ($count >= self::MAX_KEYS) break;

            $key = mb_substr((string)$key, 0, self::MAX_KEY_LEN, 'UTF-8');
            if ($key === '') continue;

            if ($value === null || is_int($value)) {
                $out[$key] = $value;
            } elseif (is_string($value)) {
                $out[$key] = mb_substr($value, 0, self::MAX_VALUE_LEN, 'UTF-8');
            } elseif (is_array($value)) {
                if (array_values($value) !== $value) continue; // asociativo anidado: descartar
                $list = [];
                foreach ($value as $item) {
                    if (!is_string($item)) continue;
                    $list[] = mb_substr($item, 0, self::MAX_VALUE_LEN, 'UTF-8');
                }
                $out[$key] = $list;
            } else {
                continue;
            }
            $count++;
        }
        return $out;
    }

    private const MAX_AGENT_CONTROLS = 200;

    /**
     * Sanea el reporte del agente elevado antes de persistirlo. El agente corre
     * como SYSTEM y su reporte llega transportado por el cliente, asi que se valida
     * con la misma desconfianza que el resto del payload:
     * - elevated / canEnforce: se fuerzan a bool (cualquier cosa "verdadera" cuenta)
     * - selfTestError / agentVersion: string truncado, o null
     * - reportedAt: se acepta ISO-8601 y se normaliza a 'Y-m-d H:i:s' en UTC; si no
     *   parsea, queda null (el panel lo trata como "sin timestamp" -> sospechoso)
     * - applied: lista de strings (codigos de control) acotada
     * - failed: lista de {code, reason} acotada
     */
    private static function sanitizeAgent(array $a): array
    {
        $str = static fn($v, int $max) => is_string($v) ? mb_substr($v, 0, $max, 'UTF-8') : null;

        $applied = [];
        if (isset($a['applied']) && is_array($a['applied'])) {
            foreach ($a['applied'] as $item) {
                if (count($applied) >= self::MAX_AGENT_CONTROLS) break;
                if (is_string($item) && $item !== '') $applied[] = mb_substr($item, 0, self::MAX_KEY_LEN, 'UTF-8');
            }
        }

        $failed = [];
        if (isset($a['failed']) && is_array($a['failed'])) {
            foreach ($a['failed'] as $item) {
                if (count($failed) >= self::MAX_AGENT_CONTROLS) break;
                if (!is_array($item)) continue;
                $code = $str($item['code'] ?? null, self::MAX_KEY_LEN);
                if ($code === null || $code === '') continue;
                $failed[] = ['code' => $code, 'reason' => $str($item['reason'] ?? null, self::MAX_VALUE_LEN) ?? ''];
            }
        }

        return [
            'elevated'      => (bool)($a['elevated'] ?? false),
            'canEnforce'    => (bool)($a['canEnforce'] ?? false),
            'selfTestError' => $str($a['selfTestError'] ?? null, 255),
            'agentVersion'  => $str($a['agentVersion'] ?? null, 32),
            'reportedAt'    => self::normalizeUtc($a['reportedAt'] ?? null),
            'applied'       => $applied,
            'failed'        => $failed,
        ];
    }

    /** ISO-8601 (u otro parseable) -> 'Y-m-d H:i:s' en UTC; null si no parsea. */
    private static function normalizeUtc($v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        try {
            $dt = new \DateTimeImmutable($v);
            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
