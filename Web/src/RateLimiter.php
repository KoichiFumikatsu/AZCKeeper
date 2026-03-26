<?php

/**
 * RateLimiter - Control de tasa de peticiones por usuario/endpoint
 * 
 * Implementa rate limiting usando filesystem para trackear peticiones
 * por usuario y endpoint en ventanas de tiempo deslizantes.
 * 
 * Uso:
 * ```php
 * if (!RateLimiter::allow($userId, 'endpoint-name', 30, 60)) {
 *     Http::json(['error' => 'Rate limit exceeded'], 429);
 *     return;
 * }
 * ```
 */
class RateLimiter
{
    /**
     * Verifica si el usuario puede realizar una petición
     * 
     * @param int $userId ID del usuario
     * @param string $endpoint Nombre del endpoint (ej: 'activity-day-get')
     * @param int $maxRequests Número máximo de peticiones permitidas
     * @param int $windowSeconds Ventana de tiempo en segundos
     * @return bool True si la petición está permitida, false si excede el límite
     */
    public static function allow(int $userId, string $endpoint, int $maxRequests, int $windowSeconds): bool
    {
        $key = self::getKey($userId, $endpoint);
        $file = self::getFilePath($key);
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Leer timestamps de peticiones previas
        $timestamps = self::readTimestamps($file);

        // Filtrar timestamps dentro de la ventana actual
        $validTimestamps = array_filter($timestamps, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        });

        // Verificar si se excede el límite
        if (count($validTimestamps) >= $maxRequests) {
            return false;
        }

        // Agregar timestamp actual
        $validTimestamps[] = $now;

        // Guardar timestamps actualizados
        self::writeTimestamps($file, $validTimestamps);

        return true;
    }

    /**
     * Resetea el contador para un usuario/endpoint específico
     * 
     * @param int $userId ID del usuario
     * @param string $endpoint Nombre del endpoint
     */
    public static function reset(int $userId, string $endpoint): void
    {
        $key = self::getKey($userId, $endpoint);
        $file = self::getFilePath($key);
        
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Limpia archivos de rate limiting expirados (>24h)
     * Llamar periódicamente desde cron o mantenimiento
     */
    public static function cleanup(): void
    {
        $dir = self::getStorageDir();
        $now = time();
        $maxAge = 86400; // 24 horas

        if (!is_dir($dir)) {
            return;
        }

        $files = glob($dir . '/ratelimit_*.dat');
        foreach ($files as $file) {
            if (is_file($file) && ($now - filemtime($file)) > $maxAge) {
                @unlink($file);
            }
        }
    }

    /**
     * Genera clave única para usuario/endpoint
     */
    private static function getKey(int $userId, string $endpoint): string
    {
        return "ratelimit_{$userId}_{$endpoint}";
    }

    /**
     * Obtiene ruta del directorio de almacenamiento.
     * Se organiza en subdirectorios por módulo del userId para evitar
     * contención de filesystem con 500+ usuarios (evita tener 500 archivos en un dir).
     */
    private static function getStorageDir(): string
    {
        $base = sys_get_temp_dir() . '/azc_keeper_ratelimit';

        if (!is_dir($base)) {
            @mkdir($base, 0750, true);
        }

        return $base;
    }

    /**
     * Genera ruta completa del archivo de timestamps.
     * Usa subdirectorio por bucket (userId mod 16) para distribuir I/O.
     */
    private static function getFilePath(string $key): string
    {
        $safeName = preg_replace('/[^a-z0-9_-]/', '', strtolower($key));
        // Extraer userId del key para hacer bucket (ratelimit_123_endpoint -> bucket "b7")
        preg_match('/ratelimit_(\d+)_/', $safeName, $m);
        $bucket = isset($m[1]) ? 'b' . ((int)$m[1] % 16) : 'b0';
        $dir = self::getStorageDir() . '/' . $bucket;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir . '/' . $safeName . '.dat';
    }

    /**
     * Lee timestamps desde archivo
     * 
     * @return int[] Array de timestamps Unix
     */
    private static function readTimestamps(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            return [];
        }

        $timestamps = json_decode($content, true);
        return is_array($timestamps) ? $timestamps : [];
    }

    /**
     * Escribe timestamps a archivo
     * 
     * @param int[] $timestamps Array de timestamps Unix
     */
    private static function writeTimestamps(string $file, array $timestamps): void
    {
        $json = json_encode(array_values($timestamps));
        @file_put_contents($file, $json, LOCK_EX);
    }
}
