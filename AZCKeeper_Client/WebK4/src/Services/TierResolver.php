<?php
namespace Keeper\Services;

use PDO;

/**
 * Resuelve los modulos que una firma tiene DERECHO a usar, segun su tier y sus
 * overrides granulares. Es el techo comercial: el backend recorta la politica
 * efectiva contra esto antes de enviarla al cliente.
 *
 * Con el interruptor global 'tier_enforcement_enabled' = '0', devuelve todos los
 * modulos activos (modo interno AZC, sin gating).
 */
class TierResolver
{
    /** @return string[] codigos de modulo permitidos */
    public static function effectiveModules(PDO $pdo, ?int $firmaId): array
    {
        if (!self::enforcementEnabled($pdo)) {
            return self::allActiveModules($pdo);
        }
        if (!$firmaId) {
            // Firma sin tier = ningun modulo (fail-closed comercial).
            return [];
        }

        // (modulos del tier MENOS revocados por override) MAS concedidos por override.
        $sql = "
          SELECT tm.module_code
          FROM keeper_firmas f
          JOIN keeper_tier_module tm ON tm.tier_id = f.tier_id
          WHERE f.id = :fid
            AND NOT EXISTS (
              SELECT 1 FROM keeper_firma_module_override o
              WHERE o.firma_id = f.id AND o.module_code = tm.module_code AND o.enabled = 0
            )
          UNION
          SELECT o.module_code
          FROM keeper_firma_module_override o
          WHERE o.firma_id = :fid2 AND o.enabled = 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':fid' => $firmaId, ':fid2' => $firmaId]);
        return array_map(fn($r) => $r['module_code'], $st->fetchAll());
    }

    /** ¿La firma del usuario tiene derecho al modulo indicado? Para gate server-side. */
    public static function userAllows(PDO $pdo, int $userId, string $moduleCode): bool
    {
        $st = $pdo->prepare("SELECT firma_id FROM keeper_user_assignments WHERE user_id = :u LIMIT 1");
        $st->execute([':u' => $userId]);
        $firmaId = $st->fetchColumn();
        $allowed = self::effectiveModules($pdo, $firmaId ? (int)$firmaId : null);
        return in_array($moduleCode, $allowed, true);
    }

    private static function enforcementEnabled(PDO $pdo): bool
    {
        $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'tier_enforcement_enabled' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        // Ausente => enforcement encendido por defecto (fail-safe: no regalar modulos).
        return $v === false ? true : ($v === '1');
    }

    private static function allActiveModules(PDO $pdo): array
    {
        $st = $pdo->query("SELECT code FROM keeper_module WHERE is_active = 1");
        return array_map(fn($r) => $r['code'], $st->fetchAll());
    }
}
