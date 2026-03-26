<?php
namespace Keeper;

use PDO;

/**
 * Sincroniza datos de employee (legacy) → keeper_user_assignments.
 *
 * Se ejecuta:
 *  - En ClientLogin.php → por usuario individual al hacer login
 *  - En admin_auth.php  → batch para todos los keeper_users sin assignment
 *
 * Throttle: en el panel, solo corre 1 vez cada 5 minutos (por sesión PHP).
 *
 * IMPORTANTE: los registros con manual_override = 1 NO se sobreescriben.
 * Cuando un admin edita una asignación desde el panel, se marca como override
 * y Keeper pasa a ser la fuente de verdad para ese usuario.
 */
class LegacySyncService
{
    /** Minutos entre ejecuciones batch en el panel */
    private const THROTTLE_MINUTES = 5;
    private const SESSION_KEY = '_keeper_last_assignment_sync';

    /**
     * Sincroniza un único keeper_user desde legacy.
     * Usado en ClientLogin.php.
     */
    public static function syncOne(PDO $pdo, int $keeperUserId, array $assignment): void
    {
        $stmt = $pdo->prepare("SELECT id, firm_id, area_id, cargo_id, sede_id, manual_override FROM keeper_user_assignments WHERE keeper_user_id = :uid LIMIT 1");
        $stmt->execute(['uid' => $keeperUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $firmId  = $assignment['firm_id'];
        $areaId  = $assignment['area_id'];
        $cargoId = $assignment['cargo_id'];
        $sedeId  = $assignment['sede_id'] ?? null;

        if ($row) {
            // Si fue editado desde el panel, Keeper es la fuente de verdad → no tocar
            if (!empty($row['manual_override'])) {
                return;
            }

            if ((int)($row['firm_id'] ?? 0) !== (int)($firmId ?? 0)
                || (int)($row['area_id'] ?? 0) !== (int)($areaId ?? 0)
                || (int)($row['cargo_id'] ?? 0) !== (int)($cargoId ?? 0)
                || (int)($row['sede_id'] ?? 0) !== (int)($sedeId ?? 0)) {
                $upd = $pdo->prepare("
                    UPDATE keeper_user_assignments
                    SET firm_id = :fid, area_id = :aid, cargo_id = :cid, sede_id = :sid, updated_at = NOW()
                    WHERE id = :id
                ");
                $upd->execute([
                    'fid' => $firmId,
                    'aid' => $areaId,
                    'cid' => $cargoId,
                    'sid' => $sedeId,
                    'id'  => $row['id'],
                ]);
            }
        } else {
            $ins = $pdo->prepare("
                INSERT INTO keeper_user_assignments (keeper_user_id, firm_id, area_id, cargo_id, sede_id, assigned_at, updated_at)
                VALUES (:uid, :fid, :aid, :cid, :sid, NOW(), NOW())
            ");
            $ins->execute([
                'uid' => $keeperUserId,
                'fid' => $firmId,
                'aid' => $areaId,
                'cid' => $cargoId,
                'sid' => $sedeId,
            ]);
        }
    }

    /**
     * Batch: sincroniza TODOS los keeper_users que no tienen assignment.
     * Además actualiza los que ya tienen si cambiaron en legacy.
     * Throttle por sesión PHP para no ejecutar en cada request.
     */
    public static function syncAllFromPanel(PDO $pdo): void
    {
        // Throttle: solo cada N minutos
        if (isset($_SESSION[self::SESSION_KEY])) {
            $lastRun = (int)$_SESSION[self::SESSION_KEY];
            if ((time() - $lastRun) < self::THROTTLE_MINUTES * 60) {
                return;
            }
        }

        $_SESSION[self::SESSION_KEY] = time();

        // 1) Insertar assignments faltantes
        $pdo->exec("
            INSERT INTO keeper_user_assignments (keeper_user_id, firm_id, area_id, cargo_id, sede_id, assigned_at, updated_at)
            SELECT
                ku.id,
                e.company,
                e.area_id,
                e.position_id,
                e.sede_id,
                NOW(),
                NOW()
            FROM keeper_users ku
            JOIN employee e ON e.id = ku.legacy_employee_id
            WHERE NOT EXISTS (
                SELECT 1 FROM keeper_user_assignments kua
                WHERE kua.keeper_user_id = ku.id
            )
        ");

        // 2) Actualizar los que cambiaron en legacy (solo si NO tienen manual_override)
        $pdo->exec("
            UPDATE keeper_user_assignments kua
            JOIN keeper_users ku ON ku.id = kua.keeper_user_id
            JOIN employee e ON e.id = ku.legacy_employee_id
            SET
                kua.firm_id  = e.company,
                kua.area_id  = e.area_id,
                kua.cargo_id = e.position_id,
                kua.sede_id  = e.sede_id,
                kua.updated_at = NOW()
            WHERE
                kua.manual_override = 0
                AND (
                    COALESCE(kua.firm_id, 0)  != COALESCE(e.company, 0)
                    OR COALESCE(kua.area_id, 0) != COALESCE(e.area_id, 0)
                    OR COALESCE(kua.cargo_id, 0) != COALESCE(e.position_id, 0)
                    OR COALESCE(kua.sede_id, 0) != COALESCE(e.sede_id, 0)
                )
        ");
    }
}
