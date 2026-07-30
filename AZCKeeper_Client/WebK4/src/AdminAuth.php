<?php
namespace Keeper;

/**
 * Gate temporal para los endpoints /admin/*. STOPGAP: exige una llave compartida
 * (ADMIN_API_KEY del .env) en el header X-Admin-Key. Cierra el agujero de que
 * cualquier token de sesion de cliente alcanzara los endpoints admin (encolar
 * apagados a cualquier equipo, auto-aprobar enrolamientos, leer la actividad de
 * cualquiera).
 *
 * El RBAC real (keeper_admin_sessions + permisos por rol) llega con el panel y
 * reemplaza esto.
 */
class AdminAuth
{
    public static function require(): void
    {
        $expected = Config::get('ADMIN_API_KEY', '');
        $given = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
        if (!is_string($expected) || $expected === '' || !is_string($given) || !hash_equals($expected, $given)) {
            Http::json(403, ['ok' => false, 'error' => 'Admin authorization required']);
        }
    }
}
