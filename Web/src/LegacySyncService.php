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
 *
 * MULTI-DB: Las consultas a `employee` usan Db::legacyPdo() (o Db::sourceFor())
 * mientras que las consultas a `keeper_*` usan Db::pdo().
 */
class LegacySyncService
{
    /** Minutos entre ejecuciones batch en el panel */
    private const THROTTLE_MINUTES = 5;
    private const SESSION_KEY = '_keeper_last_assignment_sync';

    /**
     * Sincroniza un único keeper_user desde legacy.
     * Usado en ClientLogin.php.
     *
     * @param PDO $keeperPdo Conexión a la BD keeper (keeper_user_assignments)
     */
    public static function syncOne(PDO $keeperPdo, int $keeperUserId, array $assignment): void
    {
        $stmt = $keeperPdo->prepare("SELECT id, firm_id, area_id, cargo_id, sede_id, manual_override FROM keeper_user_assignments WHERE keeper_user_id = :uid LIMIT 1");
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
                $upd = $keeperPdo->prepare("
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
            $ins = $keeperPdo->prepare("
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
     * Batch: sincroniza TODOS los keeper_users que no tienen assignment
     * o cuyas asignaciones cambiaron en legacy.
     *
     * MULTI-DB: Usa Db::legacyPdo() para leer employee, Db::pdo() para keeper_*.
     * Como las tablas están en BDs distintas, no podemos hacer JOINs cruzados.
     * En su lugar, leemos de cada BD por separado y hacemos el match en PHP.
     *
     * Throttle por sesión PHP para no ejecutar en cada request.
     */
    public static function syncAllFromPanel(PDO $keeperPdo): void
    {
        // Throttle: solo cada N minutos
        if (isset($_SESSION[self::SESSION_KEY])) {
            $lastRun = (int)$_SESSION[self::SESSION_KEY];
            if ((time() - $lastRun) < self::THROTTLE_MINUTES * 60) {
                return;
            }
        }

        $_SESSION[self::SESSION_KEY] = time();

        $legacyPdo = Db::legacyPdo();

        // 1) Leer todos los keeper_users con su legacy_employee_id y assignment actual
        $keeperUsers = $keeperPdo->query("
            SELECT
                ku.id AS keeper_user_id,
                ku.legacy_employee_id,
                kua.id AS assignment_id,
                kua.firm_id,
                kua.area_id,
                kua.cargo_id,
                kua.sede_id,
                kua.manual_override
            FROM keeper_users ku
            LEFT JOIN keeper_user_assignments kua ON kua.keeper_user_id = ku.id
            WHERE ku.legacy_employee_id IS NOT NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($keeperUsers)) return;

        // 2) Recolectar legacy_employee_ids para consultar en bulk
        $legacyIds = array_filter(array_column($keeperUsers, 'legacy_employee_id'));
        if (empty($legacyIds)) return;

        // 3) Consultar employees en la BD legacy
        $placeholders = implode(',', array_fill(0, count($legacyIds), '?'));
        $stEmp = $legacyPdo->prepare("
            SELECT id, company, area_id, position_id, sede_id
            FROM employee
            WHERE id IN ({$placeholders})
        ");
        $stEmp->execute(array_values($legacyIds));
        $empMap = [];
        foreach ($stEmp->fetchAll(PDO::FETCH_ASSOC) as $emp) {
            $empMap[(int)$emp['id']] = $emp;
        }

        // 4) Sincronizar: insertar faltantes, actualizar cambios (excepto manual_override)
        $stInsert = $keeperPdo->prepare("
            INSERT INTO keeper_user_assignments (keeper_user_id, firm_id, area_id, cargo_id, sede_id, assigned_at, updated_at)
            VALUES (:uid, :fid, :aid, :cid, :sid, NOW(), NOW())
        ");
        $stUpdate = $keeperPdo->prepare("
            UPDATE keeper_user_assignments
            SET firm_id = :fid, area_id = :aid, cargo_id = :cid, sede_id = :sid, updated_at = NOW()
            WHERE id = :id
        ");

        foreach ($keeperUsers as $ku) {
            $legacyId = (int)$ku['legacy_employee_id'];
            if (!isset($empMap[$legacyId])) continue;

            $emp = $empMap[$legacyId];
            $newFirm  = $emp['company'] ? (int)$emp['company'] : null;
            $newArea  = $emp['area_id'] ? (int)$emp['area_id'] : null;
            $newCargo = $emp['position_id'] ? (int)$emp['position_id'] : null;
            $newSede  = $emp['sede_id'] ? (int)$emp['sede_id'] : null;

            if ($ku['assignment_id'] === null) {
                // Sin assignment → insertar
                $stInsert->execute([
                    'uid' => $ku['keeper_user_id'],
                    'fid' => $newFirm,
                    'aid' => $newArea,
                    'cid' => $newCargo,
                    'sid' => $newSede,
                ]);
            } elseif (empty($ku['manual_override'])) {
                // Con assignment sin override → verificar si cambió
                $changed = (int)($ku['firm_id'] ?? 0) !== (int)($newFirm ?? 0)
                    || (int)($ku['area_id'] ?? 0) !== (int)($newArea ?? 0)
                    || (int)($ku['cargo_id'] ?? 0) !== (int)($newCargo ?? 0)
                    || (int)($ku['sede_id'] ?? 0) !== (int)($newSede ?? 0);

                if ($changed) {
                    $stUpdate->execute([
                        'fid' => $newFirm,
                        'aid' => $newArea,
                        'cid' => $newCargo,
                        'sid' => $newSede,
                        'id'  => $ku['assignment_id'],
                    ]);
                }
            }
            // manual_override = 1 → no tocar
        }
    }
}
