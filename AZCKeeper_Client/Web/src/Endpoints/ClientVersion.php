<?php
namespace Keeper\Endpoints;

use Keeper\Config;
use Keeper\Db;
use Keeper\Http;
use Keeper\Repos\ReleaseRepo;
use Keeper\Repos\SessionRepo;

class ClientVersion
{
    /** Nombre del lock de MySQL que serializa el tope de descargas simultáneas. */
    private const GATE_LOCK = 'k3_update_gate';

    /** Segundos máximos esperando el lock; si no se obtiene, se pide al equipo que espere. */
    private const GATE_LOCK_TIMEOUT = 3;

    public static function handle(): void
    {
        // Check if client wants beta versions (from query param or JSON body)
        $allowBeta = false;

        if (isset($_GET['allowBeta'])) {
            $allowBeta = filter_var($_GET['allowBeta'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $body = file_get_contents('php://input');
            if ($body) {
                $data = json_decode($body, true);
                $allowBeta = $data['allowBetaVersions'] ?? false;
            }
        }

        // Get latest release from database
        $release = ReleaseRepo::getLatestRelease($allowBeta);

        if (!$release) {
            Http::json(500, [
                'ok' => false,
                'error' => 'No releases available'
            ]);
            return;
        }

        // Tope de descargas simultáneas: si no hay cupo, el equipo recibe una respuesta "al día"
        // y vuelve a preguntar en su siguiente chequeo. Http::json hace exit, por eso se decide
        // aquí fuera (dentro del try/finally del tope el exit se saltaría el RELEASE_LOCK).
        $deferred = self::updateGate($release);
        if ($deferred !== null) {
            Http::json(200, $deferred);
            return;
        }

        Http::json(200, [
            'ok' => true,
            'latestVersion' => $release['version'],
            'downloadUrl' => $release['download_url'],
            'fileSize' => (int)$release['file_size'],
            'releaseNotes' => $release['release_notes'] ?? '',
            'forceUpdate' => (bool)$release['force_update'],
            'minimumVersion' => $release['minimum_version'] ?? '3.0.0.0',
            'releaseDate' => $release['release_date'] ?? date('Y-m-d')
        ]);
    }

    /**
     * Tope de descargas simultáneas del paquete de actualización.
     *
     * El cliente 3.0.3.2 descarga con HttpClient.Timeout = 5 min: con muchos equipos bajando a la
     * vez la descarga pasa de 300 s, se corta y reintenta en bucle. Aquí se limita cuántos equipos
     * distintos reciben la oferta real dentro de una ventana (UPDATE_GATE_MAX / UPDATE_GATE_WINDOW_SECONDS);
     * las ofertas se registran en keeper_audit_log con event_type='update_offered'.
     *
     * Devuelve null cuando se debe responder la oferta normal (al día, admitido, tope desactivado
     * o cualquier fallo: fail-open) y el payload "espera" cuando no hay cupo.
     */
    private static function updateGate(array $release): ?array
    {
        $max = (int)Config::get('UPDATE_GATE_MAX', '5');
        if ($max <= 0) {
            return null; // tope desactivado: comportamiento original
        }
        $window = max(1, (int)Config::get('UPDATE_GATE_WINDOW_SECONDS', '300'));

        $pdo = null;
        $locked = false;
        try {
            $pdo = Db::pdo();
            $device = self::identifyDevice($pdo);
            $clientVersion = $device['client_version'];

            // Equipo ya al día (o por delante): respuesta de siempre, no consume cupo
            if ($clientVersion !== null && version_compare($clientVersion, (string)$release['version'], '>=')) {
                return null;
            }

            $got = $pdo->query("SELECT GET_LOCK('" . self::GATE_LOCK . "', " . self::GATE_LOCK_TIMEOUT . ")")->fetchColumn();
            if ($got === null || $got === false) {
                throw new \RuntimeException('GET_LOCK devolvió NULL');
            }
            if ((int)$got !== 1) {
                return self::deferredPayload($release, $clientVersion); // lock ocupado: que espere
            }
            $locked = true;

            $deviceId = $device['device_id'];
            $since = "NOW() - INTERVAL {$window} SECOND";

            // Equipo ya admitido dentro de la ventana: vuelve a recibir la oferta sin duplicar fila
            if ($deviceId !== null) {
                $st = $pdo->prepare("
                    SELECT 1 FROM keeper_audit_log
                    WHERE event_type = 'update_offered' AND device_id = :d AND created_at >= {$since}
                    LIMIT 1
                ");
                $st->execute([':d' => $deviceId]);
                if ($st->fetchColumn()) {
                    return null;
                }
            }

            // Ofertas vigentes: equipos distintos + cada oferta sin equipo identificado
            $count = (int)$pdo->query("
                SELECT COUNT(DISTINCT device_id) + COALESCE(SUM(device_id IS NULL), 0)
                FROM keeper_audit_log
                WHERE event_type = 'update_offered' AND created_at >= {$since}
            ")->fetchColumn();

            if ($count >= $max) {
                return self::deferredPayload($release, $clientVersion);
            }

            $st = $pdo->prepare("
                INSERT INTO keeper_audit_log (user_id, device_id, event_type, message, meta_json, created_at)
                VALUES (:u, :d, 'update_offered', :m, :meta, NOW())
            ");
            $st->execute([
                ':u' => $device['user_id'],
                ':d' => $deviceId,
                ':m' => (string)$release['version'],
                ':meta' => json_encode([
                    'version' => (string)$release['version'],
                    'client_version' => $clientVersion,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            return null;
        } catch (\Throwable $e) {
            // Fail-open: un fallo del tope nunca tumba el endpoint, se responde como siempre
            error_log("ClientVersion: tope de actualización desactivado por error — " . $e->getMessage());
            return null;
        } finally {
            if ($locked && $pdo !== null) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('" . self::GATE_LOCK . "')");
                } catch (\Throwable $e) {
                    error_log("ClientVersion: no se pudo liberar el lock del tope — " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Identifica el equipo por el Bearer (si lo trae). Sin token o con token inválido el equipo
     * queda como desconocido: el endpoint es público y no responde 401.
     *
     * @return array{user_id: ?int, device_id: ?int, client_version: ?string}
     */
    private static function identifyDevice(\PDO $pdo): array
    {
        $device = ['user_id' => null, 'device_id' => null, 'client_version' => null];

        $token = Http::bearerToken();
        if ($token === null) {
            return $device;
        }
        $session = SessionRepo::validateBearer($pdo, $token);
        if (!$session) {
            return $device;
        }

        $device['user_id'] = isset($session['user_id']) ? (int)$session['user_id'] : null;
        if (empty($session['device_id'])) {
            return $device;
        }
        $device['device_id'] = (int)$session['device_id'];

        $st = $pdo->prepare("SELECT client_version FROM keeper_devices WHERE id = :id LIMIT 1");
        $st->execute([':id' => $device['device_id']]);
        $version = trim((string)$st->fetchColumn());
        // Solo versiones X.Y[.Z[.W]]: el cliente hace new Version(latestVersion) con lo que devolvamos
        if (preg_match('/^\d+(\.\d+){1,3}$/', $version)) {
            $device['client_version'] = $version;
        }

        return $device;
    }

    /**
     * Respuesta "espera": mismo formato que la oferta, pero el cliente se ve al día
     * (latestVersion = su versión o 0.0.0.0, minimumVersion 0.0.0.0) y no descarga nada.
     */
    private static function deferredPayload(array $release, ?string $clientVersion): array
    {
        return [
            'ok' => true,
            'latestVersion' => $clientVersion ?? '0.0.0.0',
            'downloadUrl' => '',
            'fileSize' => 0,
            'releaseNotes' => '',
            'forceUpdate' => false,
            'minimumVersion' => '0.0.0.0',
            'releaseDate' => $release['release_date'] ?? date('Y-m-d'),
            'deferred' => true,
        ];
    }
}
