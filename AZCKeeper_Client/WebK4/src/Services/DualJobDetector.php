<?php
namespace Keeper\Services;

use PDO;
use Keeper\Repos\DualJobRepo;

/**
 * Detección de doble empleo. Busca, en los episodios del día, uso de aplicaciones
 * marcadas como señal de segundo empleo (keeper_app_classification.is_dual_job_signal):
 * CRM de la competencia, otras herramientas de fichaje, apps de trabajo remoto.
 *
 * Se recalcula desde keeper_episode (que reemplaza a keeper_window_episode del K3).
 * Si encuentra señales crea/actualiza una alerta 'open'; si ya no hay, cierra la abierta.
 */
class DualJobDetector
{
    private const ALERT_TYPE = 'dual_job_app';
    private const MIN_SECONDS = 60; // ignora toques fugaces

    public static function detectDay(PDO $pdo, int $userId, string $day): bool
    {
        $signals = self::signalPatterns($pdo);
        if (!$signals['proc'] && !$signals['kw']) return false;

        $st = $pdo->prepare("
            SELECT device_id, process_name, window_title, duration_seconds
            FROM keeper_episode WHERE user_id = :u AND day_date = :d
        ");
        $st->execute([':u' => $userId, ':d' => $day]);
        $episodes = $st->fetchAll();

        $hits = []; $deviceId = null; $total = 0;
        foreach ($episodes as $ep) {
            $match = self::matchSignal($ep['process_name'], $ep['window_title'], $signals);
            if ($match !== null) {
                $dur = (int)$ep['duration_seconds'];
                $hits[$match] = ($hits[$match] ?? 0) + $dur;
                $total += $dur;
                $deviceId = (int)$ep['device_id'];
            }
        }

        if ($total < self::MIN_SECONDS) {
            DualJobRepo::clearOpen($pdo, $userId, $day, self::ALERT_TYPE);
            return false;
        }

        arsort($hits);
        DualJobRepo::upsertAlert($pdo, $userId, $deviceId, $day, self::ALERT_TYPE, [
            'total_seconds' => $total,
            'apps'          => $hits,
        ]);
        return true;
    }

    private static function signalPatterns(PDO $pdo): array
    {
        $st = $pdo->query("SELECT match_type, LOWER(pattern) AS pattern FROM keeper_app_classification WHERE is_dual_job_signal = 1");
        $proc = []; $kw = [];
        foreach ($st->fetchAll() as $r) {
            if ($r['match_type'] === 'title_keyword') $kw[] = $r['pattern'];
            else $proc[] = $r['pattern'];
        }
        return ['proc' => $proc, 'kw' => $kw];
    }

    private static function matchSignal(?string $proc, ?string $title, array $signals): ?string
    {
        $p = strtolower((string)$proc);
        foreach ($signals['proc'] as $sp) { if ($p === $sp) return $sp; }
        $t = strtolower((string)$title);
        if ($t !== '') foreach ($signals['kw'] as $k) { if (str_contains($t, $k)) return $k; }
        return null;
    }
}
