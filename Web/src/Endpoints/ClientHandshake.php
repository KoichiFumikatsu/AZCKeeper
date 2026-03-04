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
    $version         = $body['version']          ?? ($body['Version']         ?? null);
    $deviceGuid      = $body['deviceId']          ?? ($body['DeviceId']        ?? null);
    $deviceName      = $body['deviceName']        ?? ($body['DeviceName']       ?? null);
    $tzOffsetMinutes = isset($body['tzOffsetMinutes']) ? (int)$body['tzOffsetMinutes'] : null;
    $ianaTimezone    = $body['ianaTimezone']       ?? ($body['IanaTimezone']    ?? null);

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

    // Actualizar timezone del dispositivo si el cliente la envía.
    // Estos campos se usan en el panel para convertir UTC → hora local del empleado.
    // try-catch: las columnas tz_offset_minutes/iana_timezone se añaden con
    // tz_full_migration.sql. Si aún no existen en prod, falla silenciosamente.
    if ($tzOffsetMinutes !== null || $ianaTimezone !== null) {
      $fields = [];
      $params = [':id' => $deviceId];
      if ($tzOffsetMinutes !== null) { $fields[] = 'tz_offset_minutes = :tzOff'; $params[':tzOff'] = $tzOffsetMinutes; }
      if ($ianaTimezone !== null)    { $fields[] = 'iana_timezone = :tz';         $params[':tz']    = substr($ianaTimezone, 0, 64); }
      if ($fields) {
        try {
          $pdo->prepare("UPDATE keeper_devices SET " . implode(', ', $fields) . " WHERE id = :id")
              ->execute($params);
        } catch (\PDOException $e) {
          error_log("[KEEPER] ClientHandshake: tz UPDATE omitido (migración pendiente): " . $e->getMessage());
        }
      }
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
/*
    HandshakeRepo::insert(
      $pdo,
      $userId,
      $deviceId,
      $version,
      // guarda request normalizado
      ['version'=>$version,'deviceId'=>$deviceGuid,'deviceName'=>$deviceName],
      $effective
    );
*/
    Http::json(200, [
      'ok' => true,
      'cc' => $pdo->query("SELECT cc FROM keeper_users WHERE id = $userId LIMIT 1")->fetchColumn(),
      'serverTimeUtc' => Http::nowUtcIso(),
      'policyApplied' => ['scope' => $appliedScope, 'policyId' => $appliedId, 'version' => $appliedVersion],
      'effectiveConfig' => $effective
    ]);
  }
}
