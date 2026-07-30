<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\CoverageRepo;
use Keeper\Repos\AuditRepo;

/**
 * Cobertura de instalacion. GET lista el estado por usuario; POST fija exento/nota.
 * TODO RBAC admin: auth por sesion como marcador, igual que ProcessView/AdminCommand.
 */
class AdminCoverage
{
    public static function list(): void
    {
        $pdo = Db::pdo();
        self::requireAuth($pdo);

        $rows = CoverageRepo::list($pdo);
        // Resumen por motivo, para el panel.
        $counts = ['covered' => 0, 'never_enrolled' => 0, 'no_report' => 0, 'stale' => 0, 'exempt' => 0];
        foreach ($rows as $r) { $counts[$r['reason']] = ($counts[$r['reason']] ?? 0) + 1; }

        Http::json(200, ['ok' => true, 'summary' => $counts, 'users' => $rows]);
    }

    public static function setNote(): void
    {
        $pdo  = Db::pdo();
        self::requireAuth($pdo);
        $body = Http::readJson();

        $userId = (int)($body['userId'] ?? 0);
        if (!$userId) Http::json(400, ['ok' => false, 'error' => 'Missing userId']);
        $isExempt = !empty($body['isExempt']);
        $note     = isset($body['note']) ? mb_substr((string)$body['note'], 0, 512, 'UTF-8') : null;

        CoverageRepo::setNote($pdo, $userId, $isExempt, $note, null);
        AuditRepo::log($pdo, null, $userId, null, 'admin', 'coverage_note',
            $isExempt ? 'Marcado exento de cobertura' : 'Nota de cobertura actualizada');

        Http::json(200, ['ok' => true, 'userId' => $userId, 'isExempt' => $isExempt]);
    }

    private static function requireAuth($pdo): void
    {
        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        if (!SessionRepo::validateBearer($pdo, $token)) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
    }
}
