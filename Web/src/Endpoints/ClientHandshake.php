<?php
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
use Keeper\InputValidator;
use Keeper\PolicyService;
use Keeper\Repos\PolicyRepo;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;

/**
 * ClientHandshake � petici�n central del cliente.
 *
 * Con el handshake cada 60s este endpoint reemplaza:
 *   - Polling de bloqueo (era timer cada 30s separado, ya eliminado)
 *   - Retomar actividad del d�a (GET activity-day, solo al iniciar)
 *   - Configuraci�n efectiva (policies global + user + device)
 *   - Horario laboral (keeper_work_schedules)
 *   - Estado del dispositivo (active/away/inactive) para el panel admin
 *
 * Queries ejecutadas por request (mínimo posible):
 *   1. SELECT keeper_sessions + keeper_users  (auth + display_name en 1 JOIN)
 *   2. SELECT/UPDATE keeper_devices           (device lookup + last_seen throttled)
 *   3. SELECT keeper_policy_assignments       (global — cacheada en memoria 60s)
 *   4. SELECT keeper_policy_assignments       (user + device en 1 UNION)
 *   5. SELECT keeper_work_schedules           (cacheada en memoria 300s por userId)
 *   Total: 5 queries reales (3+4 son 1 si global está cacheada, 5 si schedule cacheado).
 */
class ClientHandshake {

  public static function handle(): void {
    try {
      self::doHandle();
    } catch (\Throwable $e) {
      error_log("[KEEPER FATAL] ClientHandshake: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
      Http::json(500, ['ok' => false, 'error' => 'Internal server error']);
    }
  }

  private static function doHandle(): void {
    $pdo = Db::pdo();
    $body = Http::readJson();

    $version    = $body['version']    ?? ($body['Version']    ?? null);
    $deviceGuid = $body['deviceId']   ?? ($body['DeviceId']   ?? null);
    $deviceName = $body['deviceName'] ?? ($body['DeviceName'] ?? null);

    if (!$deviceGuid || !preg_match('/^[0-9a-fA-F-]{36}$/', $deviceGuid)) {
      Http::json(400, ['ok' => false, 'error' => 'Invalid or missing DeviceId']);
    }

    $token = Http::bearerToken();
    if (!$token) {
      Http::json(401, ['ok' => false, 'error' => 'Missing token']);
    }

    // 1. Auth
    $sess = SessionRepo::validateBearer($pdo, $token);
    if (!$sess) {
      Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
    }
    $userId   = (int)$sess['user_id'];
    $deviceId = (int)($sess['device_id'] ?? 0);

    // 2. Device � buscar, crear si no existe, actualizar last_seen_at
    $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
    if (!$dev) {
      $deviceId = DeviceRepo::create($pdo, $userId, $deviceGuid, $deviceName, $version);
    } else {
      $deviceId = (int)$dev['id'];

      if (($dev['status'] ?? 'active') !== 'active') {
        Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
      }

      // Reasignar user si necesario (edge case)
      if ((int)$dev['user_id'] !== $userId) {
        $pdo->prepare("UPDATE keeper_devices SET user_id=:u WHERE id=:id")
            ->execute([':u' => $userId, ':id' => $deviceId]);
      }

      DeviceRepo::touch($pdo, $deviceId, $deviceName, $version);
    }

    // 3 + 4. Pol�ticas: global (cache) + user/device (1 UNION)
    $policies = PolicyRepo::getAllPolicies($pdo, $userId, $deviceId);

    $global = $policies['global'];
    if (!$global) Http::json(500, ['ok' => false, 'error' => 'No active global policy']);

    $effective      = json_decode($global['policy_json'], true) ?? [];
    $appliedScope   = 'global';
    $appliedId      = (int)$global['id'];
    $appliedVersion = (int)$global['version'];

    if ($policies['user']) {
      $u = json_decode($policies['user']['policy_json'], true);
      if (is_array($u)) {
        $effective      = PolicyService::deepMerge($effective, $u);
        $appliedScope   = 'user';
        $appliedId      = (int)$policies['user']['id'];
        $appliedVersion = (int)$policies['user']['version'];
      }
    }

    if ($policies['device']) {
      $d = json_decode($policies['device']['policy_json'], true);
      if (is_array($d)) {
        $effective      = PolicyService::deepMerge($effective, $d);
        $appliedScope   = 'device';
        $appliedId      = (int)$policies['device']['id'];
        $appliedVersion = (int)$policies['device']['version'];
      }
    }

    if (isset($effective['webBlocking']) && is_array($effective['webBlocking'])) {
      $wb = $effective['webBlocking'];
      $wb['enabled'] = !empty($wb['enabled']);
      $wb['syncIntervalSeconds'] = max(300, (int)($wb['syncIntervalSeconds'] ?? 600));
      $wb['domains'] = InputValidator::validateDomainArray($wb['domains'] ?? []);
      $effective['webBlocking'] = $wb;
    }

    // 5. Horario laboral (1 UNION)
    $workSchedule = PolicyRepo::getWorkSchedule($pdo, $userId);

    // 6. Display name — viene del JOIN en SessionRepo::validateBearer(), sin query extra
    $displayName = $sess['display_name'] ?: null;

    Http::json(200, [
      'ok'            => true,
      'serverTimeUtc' => Http::nowUtcIso(),
      'displayName'   => $displayName,
      'policyApplied' => [
        'scope'    => $appliedScope,
        'policyId' => $appliedId,
        'version'  => $appliedVersion,
      ],
      'effectiveConfig' => $effective,
      'workSchedule'    => $workSchedule,
    ]);
  }
}
