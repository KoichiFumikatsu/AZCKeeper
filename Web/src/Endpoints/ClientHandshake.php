<?php
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
use Keeper\PolicyService;
use Keeper\Repos\PolicyRepo;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\HandshakeRepo;
use Keeper\Repos\AuditRepo;

class ClientHandshake {

  public static function handle(): void {
    $pdo = Db::pdo();
    $body = Http::readJson(); // alias OK

    // camelCase (cliente) + fallback PascalCase (robustez)
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

    $sess = SessionRepo::validateBearer($pdo, $token);
    if (!$sess) {
      Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
    }

    $userId = (int)$sess['user_id'];

    // Asegurar device existe y pertence al user
    $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
    if (!$dev) {
      $deviceId = DeviceRepo::create($pdo, $userId, $deviceGuid, $deviceName);
      AuditRepo::log($pdo, $userId, $deviceId, 'device_registered', "Nuevo device registrado: {$deviceGuid}", ['deviceName' => $deviceName]);
    } else {
      $deviceId = (int)$dev['id'];

      // Verificar que el device no esté revocado
      if (($dev['status'] ?? 'active') !== 'active') {
        AuditRepo::log($pdo, $userId, $deviceId, 'handshake_revoked_device', "Handshake denegado: device revocado {$deviceGuid}", null);
        Http::json(403, ['ok' => false, 'error' => 'Device revoked']);
      }

      // reasignar si necesario
      $st = $pdo->prepare("UPDATE keeper_devices SET user_id=:u WHERE id=:id");
      $st->execute([':u' => $userId, ':id' => $deviceId]);

      DeviceRepo::touch($pdo, $deviceId, $deviceName);
    }

    $global = PolicyRepo::getActiveGlobal($pdo);
    if (!$global) Http::json(500, ['ok' => false, 'error' => 'No active global policy']);

    $effective = json_decode($global['policy_json'], true);
    if (!is_array($effective)) $effective = [];

    $appliedScope = 'global';
    $appliedId = (int)$global['id'];
    $appliedVersion = (int)$global['version'];

    $userPol = PolicyRepo::getActiveUser($pdo, $userId);
    if ($userPol) {
      $u = json_decode($userPol['policy_json'], true);
      if (is_array($u)) {
        $effective = PolicyService::deepMerge($effective, $u);
        $appliedScope = 'user';
        $appliedId = (int)$userPol['id'];
        $appliedVersion = (int)$userPol['version'];
      }
    }

    $devPol = PolicyRepo::getActiveDevice($pdo, $deviceId);
    if ($devPol) {
      $d = json_decode($devPol['policy_json'], true);
      if (is_array($d)) {
        $effective = PolicyService::deepMerge($effective, $d);
        $appliedScope = 'device';
        $appliedId = (int)$devPol['id'];
        $appliedVersion = (int)$devPol['version'];
      }
    }

    HandshakeRepo::insert(
      $pdo,
      $userId,
      $deviceId,
      $version,
      // guarda request normalizado
      ['version'=>$version,'deviceId'=>$deviceGuid,'deviceName'=>$deviceName],
      $effective
    );

    // Inyectar horario laboral en la respuesta (consulta keeper_work_schedules)
    // El cliente C# lo aplica en WorkSchedule.cs reemplazando los valores hardcodeados
    $workSchedule = PolicyRepo::getWorkSchedule($pdo, $userId);

    Http::json(200, [
      'ok' => true,
      'serverTimeUtc' => Http::nowUtcIso(),
      'policyApplied' => ['scope' => $appliedScope, 'policyId' => $appliedId, 'version' => $appliedVersion],
      'effectiveConfig' => $effective,
      'workSchedule' => $workSchedule
    ]);
  }
}
