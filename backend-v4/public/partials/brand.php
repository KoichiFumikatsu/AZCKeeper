<?php
declare(strict_types=1);
// Marca del producto = marca de la empresa duena (AZC Keeper, tenant de plataforma). Se cambia desde Empresas; si la BD
// no responde se usa la marca por defecto para que el login siga funcionando.
$brand = ['name' => 'AZC Keeper', 'logo' => null];
try {
    require_once dirname(__DIR__, 2) . '/config/bootstrap.php';
    $row = (new Keeper\Database())->one('SELECT display_name,logo_url FROM branding WHERE tenant_id=0x00000000000040008000000000000001');
    if ($row) {
        $brand['name'] = $row['display_name'];
        $path = parse_url((string) $row['logo_url'], PHP_URL_PATH);
        // Solo logos subidos desde el panel (mismo origen); los por defecto siguen con los archivos de marca.
        if (is_string($path) && preg_match('#^/uploads/brand/[0-9a-f-]+-[0-9a-f]{16}\.(png|jpg|webp)$#', $path)) { $brand['logo'] = $path; }
    }
} catch (Throwable) { /* marca por defecto */ }
