<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Config;
use Keeper\Services\Metrics;
use Keeper\Services\DualJobDetector;
use Keeper\Repos\FocusRepo;
use Keeper\Repos\DiagnosticRepo;

/**
 * Recalculo de metricas de foco/productividad de un dia para toda la flota.
 * Se ejecuta por cron con una llave (CRON_API_KEY), no por sesion de usuario.
 * Recalcula keeper_focus_daily desde keeper_day_summary + keeper_episode.
 */
class ProductivityCron
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        $body = Http::readJson();

        $key = $body['apiKey'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? null);
        $expected = Config::get('CRON_API_KEY', '');
        if (!$expected || !is_string($key) || !hash_equals($expected, $key)) {
            Http::json(401, ['ok' => false, 'error' => 'Invalid cron key']);
        }

        $day = $body['day'] ?? gmdate('Y-m-d');
        $dt = \DateTime::createFromFormat('Y-m-d', $day);
        if (!$dt || $dt->format('Y-m-d') !== $day) Http::json(400, ['ok' => false, 'error' => 'Invalid day']);

        // Usuarios con actividad ese dia.
        $st = $pdo->prepare("SELECT DISTINCT user_id FROM keeper_day_summary WHERE day_date = :d");
        $st->execute([':d' => $day]);
        $userIds = array_map(fn($r) => (int)$r['user_id'], $st->fetchAll());

        $processed = 0; $withFocus = 0; $noCoverage = 0; $dualJobAlerts = 0;
        foreach ($userIds as $uid) {
            $m = Metrics::computeDay($pdo, $uid, $day);
            FocusRepo::upsert($pdo, $m);
            if (DualJobDetector::detectDay($pdo, $uid, $day)) $dualJobAlerts++;
            $processed++;
            if ($m['focus_score'] === null) $noCoverage++; else $withFocus++;
        }

        // Mantenimiento del diagnostico en vivo: purga snapshots > retencion y flags vencidos.
        // Es telemetria efimera; que falle no debe tumbar el recalculo de productividad.
        $diagPurged = 0;
        try { $diagPurged = DiagnosticRepo::purge($pdo); } catch (\Throwable $e) { error_log('diag purge: ' . $e->getMessage()); }

        Http::json(200, [
            'ok' => true, 'day' => $day,
            'processed' => $processed, 'withFocus' => $withFocus, 'noCoverage' => $noCoverage,
            'dualJobAlerts' => $dualJobAlerts,
            'diagnosticsPurged' => $diagPurged,
        ]);
    }
}
