<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';

use Keeper\AdminAuth;
use Keeper\Database;
use Keeper\Util;

$defaultEmail = 'admin@devkeep.azclegal.com';
$defaultPassword = 'DevKeeper4!2026';
$emailEnv = getenv('SEED_ADMIN_EMAIL');
$passwordEnv = getenv('SEED_ADMIN_PASSWORD');
$email = $emailEnv === false ? $defaultEmail : trim($emailEnv);
$password = $passwordEnv === false ? $defaultPassword : $passwordEnv;

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    fwrite(STDERR, "SEED_ADMIN_EMAIL debe ser un correo válido de hasta 254 caracteres.\n");
    exit(1);
}
if ($password === '' || strlen($password) > 72 || str_contains($password, "\0")) {
    fwrite(STDERR, "SEED_ADMIN_PASSWORD debe tener entre 1 y 72 bytes y no contener NUL.\n");
    exit(1);
}

try {
    $db = new Database();
    $tenant = Util::bin(AdminAuth::PLATFORM);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $action = $db->transaction(function () use ($db, $tenant, $email, $hash): string {
        $platform = $db->one('SELECT status FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
        if (!$platform || $platform['status'] !== 'active') {
            throw new RuntimeException('El tenant de plataforma debe existir y estar activo; ejecuta primero las migraciones.');
        }
        $accounts = $db->run('SELECT tenant_id,id FROM admin_accounts WHERE email=? FOR UPDATE', [$email])->fetchAll();
        foreach ($accounts as $account) {
            if ($account['tenant_id'] !== $tenant) {
                throw new RuntimeException('El correo ya pertenece a otro tenant; usa otro SEED_ADMIN_EMAIL para evitar un login ambiguo.');
            }
        }
        if ($accounts) {
            $db->run('UPDATE admin_accounts SET password_hash=?,is_platform_admin=TRUE,user_id=NULL,active=TRUE,auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$hash, $tenant, $accounts[0]['id']]);
            return 'actualizada';
        }
        $db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash,is_platform_admin,active,auth_version) VALUES (?,?,NULL,?,?,TRUE,TRUE,1)', [$tenant, Util::bin(Util::uuid()), $email, $hash]);
        return 'creada';
    });
    fwrite(STDOUT, "Cuenta platform admin {$action}: {$email}\nTenant: " . AdminAuth::PLATFORM . "\n");
    fwrite(STDOUT, 'Email: ' . ($emailEnv === false ? 'default dev' : 'SEED_ADMIN_EMAIL') . "\n");
    fwrite(STDOUT, $passwordEnv === false ? "Password default dev: {$defaultPassword}\n" : "Password: SEED_ADMIN_PASSWORD (no se muestra)\n");
} catch (PDOException $e) {
    fwrite(STDERR, "No se pudo sembrar la cuenta; revisa la configuración de Database y las migraciones.\n");
    exit(1);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
