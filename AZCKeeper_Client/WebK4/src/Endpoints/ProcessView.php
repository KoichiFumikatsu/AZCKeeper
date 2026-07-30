<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;
use Keeper\Repos\SessionRepo;
use Keeper\Repos\ProcessViewRepo;

/**
 * Vista de procesos: que hace cada persona. Resumen del periodo + detalle paginado.
 *
 * NOTA DE ALCANCE: este endpoint usa autenticacion por sesion como marcador
 * temporal. El RBAC de admin (keeper_admin_sessions + permiso de vista + permiso
 * de titulo completo) llega con la fase del panel. Hoy: window_title se enmascara
 * SIEMPRE (truncado) salvo que la peticion traiga un flag full_titles reservado a
 * futuro superadmin; y el acceso se audita en keeper_audit_log (data_access).
 */
class ProcessView
{
    private const PAGE_SIZE = 100;
    private const MAX_RANGE_DAYS = 92;

    public static function handle(): void
    {
        $pdo = Db::pdo();

        $token = Http::bearerToken();
        if (!$token) Http::json(401, ['ok' => false, 'error' => 'Missing token']);
        $sess = SessionRepo::validateBearer($pdo, $token);
        if (!$sess) Http::json(401, ['ok' => false, 'error' => 'Invalid token']);
        $requesterId = (int)$sess['user_id'];

        $targetUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        $from = $_GET['from'] ?? gmdate('Y-m-d', strtotime('-7 days'));
        $to   = $_GET['to']   ?? gmdate('Y-m-d');
        $page = max(1, (int)($_GET['page'] ?? 1));

        if (!$targetUser) Http::json(400, ['ok' => false, 'error' => 'Missing user_id']);
        if (!self::validDate($from) || !self::validDate($to)) Http::json(400, ['ok' => false, 'error' => 'Invalid date range']);
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MAX_RANGE_DAYS) {
            Http::json(400, ['ok' => false, 'error' => 'Range too wide (max ' . self::MAX_RANGE_DAYS . ' days)']);
        }

        $offset = ($page - 1) * self::PAGE_SIZE;
        $summary = ProcessViewRepo::summary($pdo, $targetUser, $from, $to);
        $total   = ProcessViewRepo::detailCount($pdo, $targetUser, $from, $to);
        $detail  = ProcessViewRepo::detail($pdo, $targetUser, $from, $to, self::PAGE_SIZE, $offset);

        // Enmascarado de window_title (secreto profesional). Por ahora siempre.
        foreach ($detail as &$row) {
            if ($row['window_title'] !== null) {
                $row['window_title'] = mb_substr($row['window_title'], 0, 40, 'UTF-8');
            }
        }
        unset($row);

        self::audit($pdo, $requesterId, $targetUser, $from, $to);

        Http::json(200, [
            'ok'      => true,
            'user_id' => $targetUser,
            'range'   => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'detail'  => $detail,
            'page'    => ['number' => $page, 'size' => self::PAGE_SIZE, 'total' => $total],
        ]);
    }

    private static function validDate(string $d): bool
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $d);
        return $dt && $dt->format('Y-m-d') === $d;
    }

    private static function audit(PDO $pdo, int $requesterId, int $targetUser, string $from, string $to): void
    {
        try {
            $st = $pdo->prepare("
              INSERT INTO keeper_audit_log (admin_id, user_id, event_category, event_type, message, meta_json, ip, created_at)
              VALUES (NULL, :subject, 'data_access', 'process_view', :msg, :meta, :ip, NOW())
            ");
            $st->execute([
                ':subject' => $targetUser,
                ':msg'     => "Consulta de vista de procesos (solicitante user_id={$requesterId})",
                ':meta'    => json_encode(['requester_user_id' => $requesterId, 'from' => $from, 'to' => $to]),
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (\PDOException $e) {
            error_log('ProcessView audit error: ' . $e->getMessage());
        }
    }
}
