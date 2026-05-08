<?php
namespace Keeper\Repos;

use PDO;

/**
 * PendingEnrollmentRepo — gestiona solicitudes de acceso pendientes.
 *
 * Flujo:
 *   1. ClientLogin detecta que la CC no existe en legacy ni en keeper_users.
 *   2. Llama a create() para guardar la solicitud con el hash del password intentado.
 *   3. Admin revisa en /admin/pending-users.php y llama a approve() o reject().
 *   4. approve() crea el keeper_user con status='active' y el hash guardado.
 */
class PendingEnrollmentRepo
{
    /**
     * Busca una solicitud con status='pending' para la CC dada.
     * Usado para evitar duplicados — solo se guarda 1 solicitud pendiente por CC.
     */
    public static function findPendingByCc(PDO $pdo, string $cc): ?array
    {
        $st = $pdo->prepare("
            SELECT * FROM keeper_enrollment_requests
            WHERE cc = :cc AND status = 'pending'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $st->execute([':cc' => $cc]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Crea una nueva solicitud de acceso.
     *
     * @param array $data cc, password_hash, device_guid, device_name, attempted_ip
     * @return int ID de la solicitud creada
     */
    public static function create(PDO $pdo, array $data): int
    {
        $st = $pdo->prepare("
            INSERT INTO keeper_enrollment_requests
              (cc, password_hash, device_guid, device_name, attempted_ip)
            VALUES (:cc, :ph, :dg, :dn, :ip)
        ");
        $st->execute([
            ':cc' => $data['cc'],
            ':ph' => $data['password_hash'],
            ':dg' => $data['device_guid'] ?? null,
            ':dn' => $data['device_name'] ?? null,
            ':ip' => $data['attempted_ip'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Lista todas las solicitudes pendientes, más recientes primero.
     */
    public static function getPending(PDO $pdo): array
    {
        $st = $pdo->query("
            SELECT * FROM keeper_enrollment_requests
            WHERE status = 'pending'
            ORDER BY created_at DESC
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Lista todas las solicitudes (pendientes, aprobadas, rechazadas).
     */
    public static function getAll(PDO $pdo): array
    {
        $st = $pdo->query("
            SELECT * FROM keeper_enrollment_requests
            ORDER BY created_at DESC
        ");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Obtiene una solicitud por ID.
     */
    public static function getById(PDO $pdo, int $id): ?array
    {
        $st = $pdo->prepare("SELECT * FROM keeper_enrollment_requests WHERE id = :id LIMIT 1");
        $st->execute([':id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Aprueba una solicitud: crea el keeper_user y marca como aprobada.
     *
     * @param int    $id          ID de la solicitud
     * @param int    $adminId     keeper_admin_accounts.id del admin que aprueba
     * @param string $displayName Nombre a mostrar (si vacío usa la CC)
     * @param string $email       Email opcional
     * @param string $notes       Notas del admin
     * @return int   keeper_user_id del usuario creado
     * @throws \Exception Si la solicitud no existe, ya fue procesada, o la CC ya existe en keeper_users
     */
    public static function approve(
        PDO $pdo,
        int $id,
        int $adminId,
        ?string $displayName,
        ?string $email,
        string $notes = ''
    ): int {
        $req = self::getById($pdo, $id);
        if (!$req) {
            throw new \Exception("Solicitud #{$id} no encontrada.");
        }
        if ($req['status'] !== 'pending') {
            throw new \Exception("La solicitud ya fue procesada (status={$req['status']}).");
        }

        // Verificar que la CC no exista ya en keeper_users
        $check = $pdo->prepare("SELECT id FROM keeper_users WHERE cc = :cc LIMIT 1");
        $check->execute([':cc' => $req['cc']]);
        if ($check->fetchColumn()) {
            throw new \Exception("La CC {$req['cc']} ya existe en keeper_users.");
        }

        $name = trim($displayName ?? '') ?: $req['cc'];

        // Crear keeper_user con el hash guardado del intento
        $ins = $pdo->prepare("
            INSERT INTO keeper_users (cc, display_name, email, password_hash, status, created_at, updated_at)
            VALUES (:cc, :dn, :em, :ph, 'active', NOW(), NOW())
        ");
        $ins->execute([
            ':cc' => $req['cc'],
            ':dn' => $name,
            ':em' => $email ?: null,
            ':ph' => $req['password_hash'],
        ]);
        $keeperUserId = (int)$pdo->lastInsertId();

        // Marcar solicitud como aprobada
        $upd = $pdo->prepare("
            UPDATE keeper_enrollment_requests
            SET status = 'approved', reviewed_by = :admin, reviewed_at = NOW(), notes = :notes
            WHERE id = :id
        ");
        $upd->execute([':admin' => $adminId, ':notes' => $notes, ':id' => $id]);

        return $keeperUserId;
    }

    /**
     * Rechaza una solicitud de acceso.
     */
    public static function reject(PDO $pdo, int $id, int $adminId, string $notes = ''): bool
    {
        $req = self::getById($pdo, $id);
        if (!$req || $req['status'] !== 'pending') {
            throw new \Exception("Solicitud #{$id} no válida para rechazar.");
        }

        $st = $pdo->prepare("
            UPDATE keeper_enrollment_requests
            SET status = 'rejected', reviewed_by = :admin, reviewed_at = NOW(), notes = :notes
            WHERE id = :id
        ");
        return $st->execute([':admin' => $adminId, ':notes' => $notes, ':id' => $id]);
    }
}
