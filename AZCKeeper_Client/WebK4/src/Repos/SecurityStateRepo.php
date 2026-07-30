<?php
namespace Keeper\Repos;

use PDO;

/**
 * Estado de seguridad reportado por el agente Keeper (presencia del agente y
 * estado de los controles del SO: AV, firewall, disco cifrado, etc). Un solo
 * registro por dispositivo (UNIQUE device_id): cada reporte reemplaza el
 * ultimo estado conocido. controls_hash permite saber si algo cambio respecto
 * al reporte anterior sin comparar el JSON completo en el cliente.
 *
 * Ademas persiste el estado de cumplimiento del AGENTE ELEVADO (proceso SYSTEM
 * separado): si esta elevado y si su auto-test le permite escribir HKLM. Esas
 * columnas son de primera clase para que el panel distinga con un WHERE los
 * tres estados (no instalado / instalado-sin-privilegio / aplicando) sin
 * parsear JSON. Es el requisito anti-fallo-silencioso: un agente que corre
 * pero no puede hacer cumplir tiene que verse ROJO, no confundirse con "ok".
 */
class SecurityStateRepo {

  /**
   * @param array      $controls estructura ya saneada por el endpoint
   * @param array|null $agent    reporte del agente elevado ya saneado, o null si el
   *                             cliente no adjunto ninguno (agente ausente/no reporta).
   *                             Claves: elevated(bool), canEnforce(bool),
   *                             selfTestError(?string), agentVersion(?string),
   *                             reportedAt(?string 'Y-m-d H:i:s' UTC),
   *                             applied(array), failed(array).
   * @return bool true si el estado (controles + agente) cambio respecto al ultimo reporte
   * @throws \JsonException si algo no es UTF-8 valido (JSON_THROW_ON_ERROR)
   */
  public static function upsert(PDO $pdo, int $userId, int $deviceId, bool $agentPresent, array $controls, ?array $agent = null): bool {
    $json = json_encode($controls, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $hash = hash('sha256', $json);

    // El hash de cambio cubre controles + estado del agente, para que el panel
    // "cambio algo?" se dispare tambien cuando el agente pierde/gana privilegio.
    $agentSig = $agent === null ? '' : json_encode([
      $agent['elevated'] ?? null,
      $agent['canEnforce'] ?? null,
      $agent['selfTestError'] ?? null,
      $agent['agentVersion'] ?? null,
      $agent['applied'] ?? [],
      $agent['failed'] ?? [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $fullHash = hash('sha256', $json . '|' . $agentSig);

    $st = $pdo->prepare("SELECT controls_hash FROM keeper_security_state WHERE device_id = :d LIMIT 1");
    $st->execute([':d' => $deviceId]);
    $prev = $st->fetchColumn();

    $changed = ($prev === false) || ($prev !== $fullHash);

    // Campos del agente: NULL cuando no llega reporte (distinto de 0 = reporto que no puede).
    $agElevated   = $agent === null ? null : ((($agent['elevated']   ?? false)) ? 1 : 0);
    $agCanEnforce = $agent === null ? null : ((($agent['canEnforce'] ?? false)) ? 1 : 0);
    $agError      = $agent['selfTestError'] ?? null;
    $agVersion    = $agent['agentVersion'] ?? null;
    $agReportedAt = $agent['reportedAt'] ?? null;
    $agApplied    = $agent === null ? null : json_encode(array_values($agent['applied'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $agFailed     = $agent === null ? null : json_encode(array_values($agent['failed']  ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $up = $pdo->prepare("
      INSERT INTO keeper_security_state
        (user_id, device_id, reported_at, agent_present, controls_json, controls_hash,
         agent_elevated, agent_can_enforce, agent_self_test_error, agent_version,
         agent_report_at, agent_applied_json, agent_failed_json)
      VALUES (:u, :d, NOW(), :ap, :cj, :ch,
         :ael, :ace, :aer, :aver, :arat, :aap, :afa)
      ON DUPLICATE KEY UPDATE
        user_id               = VALUES(user_id),
        reported_at           = NOW(),
        agent_present         = VALUES(agent_present),
        controls_json         = VALUES(controls_json),
        controls_hash         = VALUES(controls_hash),
        agent_elevated        = VALUES(agent_elevated),
        agent_can_enforce     = VALUES(agent_can_enforce),
        agent_self_test_error = VALUES(agent_self_test_error),
        agent_version         = VALUES(agent_version),
        agent_report_at       = VALUES(agent_report_at),
        agent_applied_json    = VALUES(agent_applied_json),
        agent_failed_json     = VALUES(agent_failed_json)
    ");
    $up->execute([
      ':u'    => $userId,
      ':d'    => $deviceId,
      ':ap'   => $agentPresent ? 1 : 0,
      ':cj'   => $json,
      ':ch'   => $fullHash,
      ':ael'  => $agElevated,
      ':ace'  => $agCanEnforce,
      ':aer'  => $agError,
      ':aver' => $agVersion,
      ':arat' => $agReportedAt,
      ':aap'  => $agApplied,
      ':afa'  => $agFailed,
    ]);

    return $changed;
  }
}
