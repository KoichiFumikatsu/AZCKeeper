<?php
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
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
 * Queries ejecutadas por request (m�nimo posible):
 *   1. SELECT keeper_sessions          (auth)
 *   2. SELECT keeper_devices           (device lookup + last_seen UPDATE en 1 op)
 *   3. SELECT keeper_policy_assignments (global cacheada en memoria)
 *   4. SELECT keeper_policy_assignments (user + device en 1 UNION)
 *   5. SELECT keeper_work_schedules    (user + global en 1 UNION)
 *   6. SELECT keeper_activity_day      (estado del d�a para panel)
 *   Total: 6 queries, de las cuales #3 usa cache en memoria.
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

    // 5. Horario laboral (1 UNION)
    $workSchedule = PolicyRepo::getWorkSchedule($pdo, $userId);

    // 6. Estado del dispositivo para panel admin (activo/ausente/inactivo)
    //    Se calcula con last_event_at de keeper_activity_day del d�a actual.
    //    El panel solo lee este campo del handshake � sin query propia.
    $deviceStatus = self::computeDeviceStatus($pdo, $userId, $deviceId);

    Http::json(200, [
      'ok'            => true,
      'serverTimeUtc' => Http::nowUtcIso(),
      'policyApplied' => [
        'scope'    => $appliedScope,
        'policyId' => $appliedId,
        'version'  => $appliedVersion,
      ],
      'effectiveConfig' => $effective,
      'workSchedule'    => $workSchedule,
      // Estado calculado en servidor � el panel admin lo lee desde keeper_devices
      // (guardado en la columna que a�adiremos: device_status)
      'deviceStatus'    => $deviceStatus,
    ]);
  }

  /**
   * Calcula el estado del dispositivo basado en actividad del d�a.
   * Resultado: 'active' | 'away' | 'inactive'
   *
   * L�gica:
   *   active   ? last_event_at hace < 2 min (usuario trabajando ahora)
   *   away     ? app conectada pero sin actividad reciente (idle o pausa)
   *   inactive ? no hay registro de actividad hoy
   *
   * Este valor se guarda en keeper_devices.device_status para que
   * realtime-status.php solo haga 1 SELECT en vez de m�ltiples queries.
   */
  private static function computeDeviceStatus(
    \PDO $pdo, int $userId, int $deviceId
  ): string {
    $st = $pdo->prepare("
      SELECT last_event_at, active_seconds, idle_seconds, call_seconds,
             work_hours_active_seconds, lunch_active_seconds, after_hours_active_seconds
      FROM keeper_activity_day
      WHERE user_id = :u AND device_id = :d AND day_date = CURDATE()
      LIMIT 1
    ");
    $st->execute([':u' => $userId, ':d' => $deviceId]);
    $row = $st->fetch();

    if (!$row || !$row['last_event_at'] || (int)$row['active_seconds'] === 0) {
      $status = 'inactive';
      $summary = null;
    } else {
      $secondsSince = time() - strtotime($row['last_event_at']);
      $status = $secondsSince < 120 ? 'active' : 'away';
      $summary = json_encode([
        'active_seconds'      => (int)$row['active_seconds'],
        'idle_seconds'        => (int)$row['idle_seconds'],
        'call_seconds'        => (int)$row['call_seconds'],
        'work_active_seconds' => (int)$row['work_hours_active_seconds'],
        'lunch_active_seconds'=> (int)$row['lunch_active_seconds'],
        'after_active_seconds'=> (int)$row['after_hours_active_seconds'],
        'last_event_at'       => $row['last_event_at'],
      ]);
    }

    // Guardar estado + resumen del d�a en keeper_devices para lectura del panel
    try {
      $pdo->prepare("
        UPDATE keeper_devices
        SET device_status = :s, day_summary_json = :j
        WHERE id = :id
      ")->execute([':s' => $status, ':j' => $summary, ':id' => $deviceId]);
    } catch (\PDOException $e) {
      // Columnas device_status/day_summary_json no existen aún — ignorar
      // hasta que se ejecute la migración add_device_status_columns.sql
      error_log("[KEEPER] computeDeviceStatus UPDATE ignorado: " . $e->getMessage());
    }

    return $status;
  }
}
