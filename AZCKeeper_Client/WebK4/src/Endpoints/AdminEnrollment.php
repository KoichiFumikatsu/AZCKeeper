<?php
namespace Keeper\Endpoints;

use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\UserRepo;
use Keeper\Repos\AuditRepo;

/**
 * Aprobacion/rechazo de solicitudes de enrolamiento (usuarios en status='pending').
 * Reemplaza el flujo pending-users de Keeper 3, plegado sobre keeper_users + bitacora.
 *
 * TODO RBAC admin: hoy usa sesion como marcador (como ProcessView/AdminCommand).
 * El actor real (admin_id) se ancla cuando exista keeper_admin_sessions.
 */
class AdminEnrollment
{
    public static function handle(): void
    {
        $pdo  = Db::pdo();
        \Keeper\AdminAuth::require();
        $body = Http::readJson();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        if (!SessionRepo::validateBearer($pdo, $token)) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);

        $userId   = (int)($body['userId'] ?? 0);
        $decision = $body['decision'] ?? null; // 'approve' | 'reject'
        if (!$userId) Http::json(400, ['ok' => false, 'error' => 'Missing userId']);
        if (!in_array($decision, ['approve', 'reject'], true)) Http::json(400, ['ok' => false, 'error' => 'Invalid decision']);

        $newStatus = $decision === 'approve' ? 'active' : 'inactive';
        $ok = UserRepo::setStatus($pdo, $userId, $newStatus);
        if (!$ok) Http::json(404, ['ok' => false, 'error' => 'User not found']);

        $eventType = $decision === 'approve' ? 'enrollment_approved' : 'enrollment_rejected';
        AuditRepo::log($pdo, null, $userId, null, 'admin', $eventType, "Enrolamiento {$decision}");

        Http::json(200, ['ok' => true, 'userId' => $userId, 'status' => $newStatus]);
    }
}
