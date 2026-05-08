<?php
namespace Keeper;

class Http
{
    /**
     * Alias retrocompatible: algunos endpoints llaman readJson().
     * Mantiene compatibilidad sin renombrar todo el código.
     */
    public static function readJson(): array
    {
        return self::jsonInput();
    }

    /**
     * Lee el body JSON (Content-Type: application/json) y retorna array asociativo.
     * Si el body está vacío o inválido, retorna [].
     */
    public static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') return [];

        $data = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }

    public static function bearerToken(): ?string
    {
        // 1) Intenta Authorization estándar
        $h = $_SERVER['HTTP_AUTHORIZATION'] 
             ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] 
             ?? null;
        
        // 2) Fallback: Apache con apache_request_headers()
        if (!$h && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $h = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }
        
        // 3) Si Authorization no llegó, usar header custom
        if (!$h && isset($_SERVER['HTTP_X_AUTH_TOKEN'])) {
            return $_SERVER['HTTP_X_AUTH_TOKEN'];
        }
        
        if (!$h) return null;
     
        // Parse Bearer si vino como Authorization
        if (preg_match('/Bearer\s+(.+)/i', $h, $m)) {
            $t = trim($m[1]);
            return $t !== '' ? $t : null;
        }
        
        return null;
    }

    public static function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function sha256Hex(string $s): string
    {
        return hash('sha256', $s);
    }

    public static function nowUtcIso(): string
    {
        $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        return $dt->format('Y-m-d\TH:i:s\Z');
    }
}
