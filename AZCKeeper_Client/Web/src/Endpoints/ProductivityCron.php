<?php
namespace Keeper\Endpoints;

use Keeper\Db;
use Keeper\Http;
use Keeper\Config;
use Keeper\Services\ProductivityCalculator;
use Keeper\Services\DualJobDetector;
use PDO;

/**
 * Endpoint de cron nocturno para calcular métricas de productividad.
 * Protegido por API key. Ejecutar diariamente a las 2AM.
 *
 * Uso: POST /api/cron/productivity  (Header: X-Cron-Key: <key>)
 *      GET  /api/cron/productivity?key=<key>&date=YYYY-MM-DD  (manual/debug)
 */
class ProductivityCron {

  private const BATCH_SIZE   = 50;
  private const BATCH_PAUSE  = 100000; // 100ms en microsegundos
  private const USER_TIMEOUT = 10;     // segundos por usuario

  public static function handle(): void {
    $startTime = microtime(true);

    // Auth: API key
    if (!self::validateApiKey()) {
      Http::json(403, ['ok' => false, 'error' => 'Invalid or missing API key']);
      return;
    }

    // Verificar si productividad está habilitada
    $pdo = Db::pdo();
    if (!self::isEnabled($pdo)) {
      Http::json(200, ['ok' => true, 'message' => 'Productivity module disabled', 'processed' => 0]);
      return;
    }

    // Fecha a procesar (default: ayer)
    $dayDate = self::getTargetDate();

    // Obtener usuarios activos con actividad ese día
    $userIds = self::getActiveUserIds($pdo, $dayDate);
    $totalUsers = count($userIds);

    $processed   = 0;
    $errors      = 0;
    $alerts      = 0;
    $errorDetails = [];

    // Procesar en lotes
    $batches = array_chunk($userIds, self::BATCH_SIZE);
    foreach ($batches as $batch) {
      foreach ($batch as $userId) {
        try {
          // Timeout check
          if ((microtime(true) - $startTime) > 300) { // 5 min max total
            $errorDetails[] = "Timeout global alcanzado en usuario $userId";
            break 2;
          }

          $userStart = microtime(true);

          // Calcular productividad
          ProductivityCalculator::calculateDay($pdo, $userId, $dayDate);

          // Detectar doble empleo
          $newAlerts = DualJobDetector::analyze($pdo, $userId);
          $alerts += $newAlerts;

          // Check per-user timeout
          $elapsed = microtime(true) - $userStart;
          if ($elapsed > self::USER_TIMEOUT) {
            $errorDetails[] = "Usuario $userId tomó " . round($elapsed, 2) . "s (timeout=" . self::USER_TIMEOUT . "s)";
          }

          $processed++;
        } catch (\Throwable $e) {
          $errors++;
          $errorDetails[] = "User $userId: " . $e->getMessage();
          error_log("[KEEPER CRON] Error procesando usuario $userId: " . $e->getMessage());
        }
      }
      // Pausa entre lotes
      if (count($batches) > 1) {
        usleep(self::BATCH_PAUSE);
      }
    }

    $duration = round(microtime(true) - $startTime, 2);

    // Log resultado
    error_log("[KEEPER CRON] Productividad $dayDate: $processed/$totalUsers usuarios, $alerts alertas, $errors errores, {$duration}s");

    Http::json(200, [
      'ok'           => true,
      'day_date'     => $dayDate,
      'total_users'  => $totalUsers,
      'processed'    => $processed,
      'alerts'       => $alerts,
      'errors'       => $errors,
      'duration_sec' => $duration,
      'error_details'=> $errors > 0 ? array_slice($errorDetails, 0, 20) : [],
    ]);
  }

  // ================================================================

  private static function validateApiKey(): bool {
    $expected = Config::get('CRON_API_KEY', '');
    if (empty($expected)) return false;

    $provided = $_SERVER['HTTP_X_CRON_KEY']
             ?? $_GET['key']
             ?? '';

    return hash_equals($expected, $provided);
  }

  private static function isEnabled(PDO $pdo): bool {
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'productivity.enabled' LIMIT 1");
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row && strtolower(trim($row['setting_value'], '"')) === 'true';
  }

  private static function getTargetDate(): string {
    $input = $_GET['date'] ?? $_POST['date'] ?? null;
    if ($input && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) {
      $ts = strtotime($input);
      if ($ts && $ts < time()) return $input;
    }
    return date('Y-m-d', strtotime('yesterday'));
  }

  private static function getActiveUserIds(PDO $pdo, string $dayDate): array {
    $st = $pdo->prepare("
      SELECT DISTINCT a.user_id
      FROM keeper_activity_day a
      INNER JOIN keeper_users u ON u.id = a.user_id AND u.status = 'active'
      WHERE a.day_date = :day
        AND a.active_seconds > 0
    ");
    $st->execute([':day' => $dayDate]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
  }
}
