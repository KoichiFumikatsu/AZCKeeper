<?php
// Ejemplo de configuracion de serve.php en modo vivo (README §3). SIN secretos: se copia como
// /home/www/keep.azclegal.com/one-bridge/serve.config.php (permisos 600) y se pone el hash real del token.
// Con el marcador de abajo el endpoint responde 503 not_configured: no arranca con un hash de ejemplo.
return [
    'mode' => 'live',                                                    // 'files' = instantanea de extract.php (snapshot_dir)
    'token_sha256' => ['<sha256 hex del token: 64 caracteres>'],        // caben dos para rotar sin cortes
    'one_config' => '/home/www/one.azclegal.com/includes/config.php',    // se LEE como texto (define('DB_...')), nunca se ejecuta
    // 'db_port' => 3306,                                               // solo si One no usa el puerto por defecto ni define DB_PORT
    'cache_dir' => '/home/www/keep.azclegal.com/one-bridge/cache',       // carpeta 700, fuera de public/: la cache tiene datos personales
    'cache_ttl' => 300,                                                  // segundos que se reutiliza un roster antes de releer One
    'cache_stale_max' => 86400,                                          // si One no responde, se sirve la cache hasta esta edad; despues 503
    'maps_dir' => '/home/www/keep.azclegal.com/one-bridge',              // company-map.json y sites-location.json
    'allowed_ips' => ['185.42.22.239'],                                  // IP de salida de devkeep; [] = sin filtro por IP
    'min_active' => 150,                                                 // menos activos = 503 sanity_failed (o cache vieja)
    'lunch' => true,                                                     // 12:00-13:00 en dias >= 8 h (decision P4)
    'page_size' => 500,
    'allow_http' => false,                                               // solo true en pruebas locales
];
