<?php
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
use Keeper\PolicyService;
use Keeper\Repos\PolicyRepo;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\DeviceRepo;
use Keeper\Repos\HandshakeRepo;

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

    // Asegurar device existe y pertenece al user
    $dev = DeviceRepo::findByGuid($pdo, $deviceGuid);
    if (!$dev) {
      $deviceId = DeviceRepo::create($pdo, $userId, $deviceGuid, $deviceName);
    } else {
      $deviceId = (int)$dev['id'];

      // reasignar si necesario
      $st = $pdo->prepare("UPDATE keeper_devices SET user_id=:u WHERE id=:id");
      $st->execute([':u' => $userId, ':id' => $deviceId]);

      DeviceRepo::touch($pdo, $deviceId, $deviceName);
    }

    $global = PolicyRepo::getActiveGlobal($pdo);
    if (!$global) Http::json(500, ['ok' => false, 'error' => 'No active global policy']);

    error_log("ClientHandshake: Global policy from DB: " . $global['policy_json']);
    
    $effective = json_decode($global['policy_json'], true);
    if (!is_array($effective)) $effective = [];

    error_log("ClientHandshake: Effective blocking section: " . json_encode($effective['blocking'] ?? 'NOT SET'));

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

    Http::json(200, [
      'ok' => true,
      'serverTimeUtc' => Http::nowUtcIso(),
      'policyApplied' => ['scope' => $appliedScope, 'policyId' => $appliedId, 'version' => $appliedVersion],
      'effectiveConfig' => $effective
    ]);
  }
}
