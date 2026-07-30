<?php
namespace Keeper\Endpoints;

use PDO;
use Keeper\Http;
use Keeper\Db;

/**
 * Última versión del cliente disponible. La consulta el updater del cliente (GET, sin
 * auth: es lo mismo que necesita un equipo que aún no puede loguear por estar
 * desactualizado). Lee keeper_client_releases: el release activo más nuevo. Con
 * ?allowBeta=true incluye los marcados beta; si no, solo estables.
 *
 * minimumVersion/forceUpdate quedan como columnas futuras (no existen aún en el esquema);
 * se devuelven null/false para que el cliente los interprete como "sin exigencia".
 */
class ClientVersion
{
    public static function handle(): void
    {
        $pdo = Db::pdo();
        $allowBeta = isset($_GET['allowBeta']) && $_GET['allowBeta'] === 'true';

        $sql = "SELECT version, download_url, size_bytes, is_beta, notes
                FROM keeper_client_releases
                WHERE is_active = 1";
        if (!$allowBeta) $sql .= " AND is_beta = 0";
        $sql .= " ORDER BY created_at DESC LIMIT 1";

        try {
            $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log('ClientVersion query error: ' . $e->getMessage());
            Http::json(500, ['ok' => false, 'error' => 'Database error']);
        }

        if (!$row) {
            // Sin release publicado: respuesta válida que el cliente lee como "al dia".
            Http::json(200, ['ok' => true, 'latestVersion' => null]);
        }

        Http::json(200, [
            'ok'             => true,
            'latestVersion'  => $row['version'],
            'downloadUrl'    => $row['download_url'],
            'sizeBytes'      => $row['size_bytes'] !== null ? (int)$row['size_bytes'] : null,
            'minimumVersion' => null,
            'forceUpdate'    => false,
            'isBeta'         => (bool)$row['is_beta'],
            'releaseNotes'   => $row['notes'],
        ]);
    }
}
