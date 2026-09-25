-- Deterministic, non-overwriting seeds; no passwords, tokens, unsigned releases or legacy data.

SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci;

SET time_zone = '+00:00';

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'empresas.ver', 'empresas.ver', 'empresas', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'empresas.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'empresas.editar', 'empresas.editar', 'empresas', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'empresas.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'empresas.gestionar', 'empresas.gestionar', 'empresas', 'gestionar', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'empresas.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'empresas.rbac_habilitar', 'empresas.rbac_habilitar', 'empresas', 'rbac_habilitar', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'empresas.rbac_habilitar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'usuarios.ver', 'usuarios.ver', 'usuarios', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'usuarios.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'usuarios.crear', 'usuarios.crear', 'usuarios', 'crear', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'usuarios.crear');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'usuarios.editar', 'usuarios.editar', 'usuarios', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'usuarios.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'organizacion.ver', 'organizacion.ver', 'organizacion', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'organizacion.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'organizacion.editar', 'organizacion.editar', 'organizacion', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'organizacion.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'horarios.ver', 'horarios.ver', 'horarios', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'horarios.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'horarios.editar', 'horarios.editar', 'horarios', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'horarios.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.ver', 'equipos.ver', 'equipos', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.editar', 'equipos.editar', 'equipos', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.asignar', 'equipos.asignar', 'equipos', 'asignar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.asignar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.enrolar', 'equipos.enrolar', 'equipos', 'enrolar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.enrolar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.transferir', 'equipos.transferir', 'equipos', 'transferir', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.transferir');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.bloquear', 'equipos.bloquear', 'equipos', 'bloquear', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.bloquear');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.desbloquear', 'equipos.desbloquear', 'equipos', 'desbloquear', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.desbloquear');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.reiniciar', 'equipos.reiniciar', 'equipos', 'reiniciar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.reiniciar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.apagar', 'equipos.apagar', 'equipos', 'apagar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.apagar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'equipos.borrar', 'equipos.borrar', 'equipos', 'borrar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'equipos.borrar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'reglas.aplicar', 'reglas.aplicar', 'reglas', 'aplicar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'reglas.aplicar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'reglas.ver', 'reglas.ver', 'reglas', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'reglas.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'reglas.editar', 'reglas.editar', 'reglas', 'editar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'reglas.editar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'roles.ver', 'roles.ver', 'roles', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'roles.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'roles.gestionar', 'roles.gestionar', 'roles', 'gestionar', 0, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'roles.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'permisos.gestionar', 'permisos.gestionar', 'permisos', 'gestionar', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'permisos.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'reportes.ver', 'reportes.ver', 'reportes', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'reportes.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'actividad.ver', 'actividad.ver', 'actividad', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'actividad.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'actividad.titulos_ver', 'actividad.titulos_ver', 'actividad', 'titulos_ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'actividad.titulos_ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'presencia.ver', 'presencia.ver', 'presencia', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'presencia.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'presencia.ubicacion_ver', 'presencia.ubicacion_ver', 'presencia', 'ubicacion_ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'presencia.ubicacion_ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'tiers.ver', 'tiers.ver', 'tiers', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'tiers.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'tiers.gestionar', 'tiers.gestionar', 'tiers', 'gestionar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'tiers.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'integraciones.gestionar', 'integraciones.gestionar', 'integraciones', 'gestionar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'integraciones.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'notificaciones.ver', 'notificaciones.ver', 'notificaciones', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'notificaciones.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'notificaciones.gestionar', 'notificaciones.gestionar', 'notificaciones', 'gestionar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'notificaciones.gestionar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'auditoria.ver', 'auditoria.ver', 'auditoria', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'auditoria.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'releases.ver', 'releases.ver', 'releases', 'ver', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'releases.ver');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'releases.publicar', 'releases.publicar', 'releases', 'publicar', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'releases.publicar');

INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'releases.desplegar', 'releases.desplegar', 'releases', 'desplegar', 1, 0
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'releases.desplegar');

INSERT INTO scopes (code, description)
SELECT 'dashboard:read', 'Lectura dashboard'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'dashboard:read');

INSERT INTO scopes (code, description)
SELECT 'team:read', 'Lectura team'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'team:read');

INSERT INTO scopes (code, description)
SELECT 'members:read', 'Lectura members'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'members:read');

INSERT INTO scopes (code, description)
SELECT 'devices:read', 'Lectura devices'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'devices:read');

INSERT INTO scopes (code, description)
SELECT 'activity:read', 'Lectura activity'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'activity:read');

INSERT INTO scopes (code, description)
SELECT 'activity-titles:read', 'Lectura activity-titles'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'activity-titles:read');

INSERT INTO scopes (code, description)
SELECT 'productivity:read', 'Lectura productivity'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'productivity:read');

INSERT INTO scopes (code, description)
SELECT 'presence:read', 'Lectura presence'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'presence:read');

INSERT INTO scopes (code, description)
SELECT 'location:read', 'Lectura location'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'location:read');

INSERT INTO scopes (code, description)
SELECT 'tiers:read', 'Lectura tiers'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'tiers:read');

INSERT INTO scopes (code, description)
SELECT 'organization:read', 'Lectura organization'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'organization:read');

INSERT INTO scopes (code, description)
SELECT 'notifications:read', 'Lectura notifications'
WHERE NOT EXISTS (SELECT 1 FROM scopes WHERE code = 'notifications:read');

INSERT INTO modules (code, name)
SELECT 'devices', 'devices'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'devices');

INSERT INTO modules (code, name)
SELECT 'activity', 'activity'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'activity');

INSERT INTO modules (code, name)
SELECT 'productivity', 'productivity'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'productivity');

INSERT INTO modules (code, name)
SELECT 'presence', 'presence'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'presence');

INSERT INTO modules (code, name)
SELECT 'policies', 'policies'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'policies');

INSERT INTO modules (code, name)
SELECT 'integrations', 'integrations'
WHERE NOT EXISTS (SELECT 1 FROM modules WHERE code = 'integrations');

INSERT INTO tenants (tenant_id, name)
SELECT 0x00000000000040008000000000000001, 'AZCKeeper Plataforma'
WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE tenant_id = 0x00000000000040008000000000000001);

INSERT INTO tenants (tenant_id, name)
SELECT 0x00000000000040008000000000000002, 'Empresa Demo'
WHERE NOT EXISTS (SELECT 1 FROM tenants WHERE tenant_id = 0x00000000000040008000000000000002);

INSERT INTO branding (tenant_id, display_name)
SELECT 0x00000000000040008000000000000001, 'AZCKeeper'
WHERE NOT EXISTS (SELECT 1 FROM branding WHERE tenant_id = 0x00000000000040008000000000000001);

INSERT INTO retention_settings (tenant_id)
SELECT 0x00000000000040008000000000000001
WHERE NOT EXISTS (SELECT 1 FROM retention_settings WHERE tenant_id = 0x00000000000040008000000000000001);

INSERT INTO branding (tenant_id, display_name)
SELECT 0x00000000000040008000000000000002, 'AZCKeeper Demo'
WHERE NOT EXISTS (SELECT 1 FROM branding WHERE tenant_id = 0x00000000000040008000000000000002);

INSERT INTO retention_settings (tenant_id)
SELECT 0x00000000000040008000000000000002
WHERE NOT EXISTS (SELECT 1 FROM retention_settings WHERE tenant_id = 0x00000000000040008000000000000002);

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'super_admin', 'Super admin', 'tenant', 1
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'super_admin');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'empresas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'empresas.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'empresas.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'empresas.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'empresas.rbac_habilitar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'empresas.rbac_habilitar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'usuarios.crear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'usuarios.crear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'usuarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'usuarios.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'organizacion.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'organizacion.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'horarios.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.asignar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.asignar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.enrolar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.enrolar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.transferir'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.transferir');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.bloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.bloquear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.desbloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.desbloquear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.reiniciar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.reiniciar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.apagar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.apagar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'equipos.borrar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'equipos.borrar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'reglas.aplicar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'reglas.aplicar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'reglas.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'roles.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'roles.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'permisos.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'permisos.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'actividad.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'actividad.titulos_ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'actividad.titulos_ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'presencia.ubicacion_ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'presencia.ubicacion_ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'tiers.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'tiers.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'integraciones.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'integraciones.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'notificaciones.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'notificaciones.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'notificaciones.gestionar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'auditoria.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'releases.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'releases.publicar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'releases.publicar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'releases.desplegar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'releases.desplegar');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'Super admin', 'super_admin', 1, 'tenant'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000001 AND id = 0x10000000000040008000000000000064);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'empresas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'empresas.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'empresas.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'empresas.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'empresas.rbac_habilitar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'empresas.rbac_habilitar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'usuarios.crear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'usuarios.crear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'usuarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'usuarios.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'organizacion.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'organizacion.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'horarios.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.asignar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.asignar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.enrolar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.enrolar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.transferir'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.transferir');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.bloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.bloquear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.desbloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.desbloquear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.reiniciar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.reiniciar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.apagar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.apagar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'equipos.borrar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'equipos.borrar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'reglas.aplicar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'reglas.aplicar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'reglas.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'roles.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'roles.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'permisos.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'permisos.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'actividad.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'actividad.titulos_ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'actividad.titulos_ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'presencia.ubicacion_ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'presencia.ubicacion_ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'tiers.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'tiers.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'integraciones.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'integraciones.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'notificaciones.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'notificaciones.gestionar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'notificaciones.gestionar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'auditoria.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'releases.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'releases.publicar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'releases.publicar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'releases.desplegar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'releases.desplegar');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'gerencia', 'Gerencia', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'gerencia');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'actividad.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'notificaciones.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'releases.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'auditoria.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'gerencia', 'empresas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'gerencia' AND permission_code = 'empresas.editar');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'Gerencia', 'gerencia', 1, 'tenant'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000065);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'actividad.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'notificaciones.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'releases.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'auditoria.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000065, 'empresas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000065 AND permission_code = 'empresas.editar');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'direccion', 'Dirección', 'area', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'direccion');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'actividad.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'notificaciones.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'releases.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'direccion', 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'direccion' AND permission_code = 'reglas.editar');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'Dirección', 'direccion', 1, 'area'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000066);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'actividad.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'notificaciones.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'releases.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND permission_code = 'reglas.editar');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'coordinacion', 'Coordinación', 'area', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'coordinacion');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'actividad.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'notificaciones.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'releases.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'coordinacion', 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'coordinacion' AND permission_code = 'horarios.editar');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'Coordinación', 'coordinacion', 1, 'area'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000067);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'actividad.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'notificaciones.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'releases.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND permission_code = 'horarios.editar');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'it', 'IT', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'it');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.asignar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.asignar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.enrolar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.enrolar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.bloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.bloquear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.desbloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.desbloquear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.reiniciar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.reiniciar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'equipos.apagar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'equipos.apagar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'reglas.aplicar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'reglas.aplicar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'reglas.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'auditoria.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'releases.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'releases.desplegar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'releases.desplegar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'it', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'it' AND permission_code = 'notificaciones.ver');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'IT', 'it', 1, 'tenant'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000068);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.asignar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.asignar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.enrolar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.enrolar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.bloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.bloquear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.desbloquear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.desbloquear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.reiniciar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.reiniciar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'equipos.apagar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'equipos.apagar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'reglas.aplicar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'reglas.aplicar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'reglas.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'reglas.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'auditoria.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'auditoria.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'releases.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'releases.desplegar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'releases.desplegar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000068, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000068 AND permission_code = 'notificaciones.ver');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'rrhh', 'RRHH', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'rrhh');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'usuarios.crear'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'usuarios.crear');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'usuarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'usuarios.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'organizacion.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'organizacion.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'horarios.editar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'rrhh', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'rrhh' AND permission_code = 'notificaciones.ver');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'RRHH', 'rrhh', 1, 'tenant'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000069);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'usuarios.crear'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'usuarios.crear');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'usuarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'usuarios.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'organizacion.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'organizacion.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'horarios.editar'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'horarios.editar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000069, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000069 AND permission_code = 'notificaciones.ver');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'admin_empresa', 'Admin Empresa', 'tenant', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'admin_empresa');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'empresas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'usuarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'organizacion.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'horarios.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'equipos.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'reglas.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'roles.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'reportes.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'actividad.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'presencia.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'tiers.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'notificaciones.ver');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'admin_empresa', 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'admin_empresa' AND permission_code = 'releases.ver');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'Admin Empresa', 'admin_empresa', 1, 'tenant'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x1000000000004000800000000000006a);

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'empresas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'empresas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'usuarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'usuarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'organizacion.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'organizacion.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'horarios.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'horarios.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'equipos.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'equipos.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'reglas.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'reglas.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'roles.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'roles.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'reportes.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'reportes.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'actividad.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'actividad.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'presencia.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'presencia.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'tiers.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'tiers.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'notificaciones.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'notificaciones.ver');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006a, 'releases.ver'
WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x1000000000004000800000000000006a AND permission_code = 'releases.ver');

INSERT INTO role_templates (seed_key, name, scope_kind, platform_only)
SELECT 'colaborador', 'Colaborador', 'self', 0
WHERE NOT EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'colaborador');

INSERT INTO roles (tenant_id, id, name, seed_key, seed_version, scope_kind)
SELECT 0x00000000000040008000000000000002, 0x1000000000004000800000000000006b, 'Colaborador', 'colaborador', 1, 'self'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x1000000000004000800000000000006b);

INSERT INTO org_units (tenant_id, id, kind, name, parent_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000001, 'firm', 'Firma Demo', NULL
WHERE NOT EXISTS (SELECT 1 FROM org_units WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000001);

INSERT INTO org_units (tenant_id, id, kind, name, parent_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000002, 'site', 'Sede Cali', 0x10000000000040008000000000000001
WHERE NOT EXISTS (SELECT 1 FROM org_units WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000002);

INSERT INTO org_units (tenant_id, id, kind, name, parent_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000003, 'area', 'Operaciones', 0x10000000000040008000000000000001
WHERE NOT EXISTS (SELECT 1 FROM org_units WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000003);

INSERT INTO org_units (tenant_id, id, kind, name, parent_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000004, 'position', 'Colaborador', 0x10000000000040008000000000000003
WHERE NOT EXISTS (SELECT 1 FROM org_units WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000004);

INSERT INTO sociedades (tenant_id, id, firm_id, name)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000005, 0x10000000000040008000000000000001, 'Sociedad Demo'
WHERE NOT EXISTS (SELECT 1 FROM sociedades WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000005);

INSERT INTO schedules (tenant_id, id, name, timezone, start_local, end_local)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 'Jornada demo', 'America/Bogota', '08:00:00', '17:00:00'
WHERE NOT EXISTS (SELECT 1 FROM schedules WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000006);

INSERT INTO schedule_days (tenant_id, schedule_id, weekday)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 1
WHERE NOT EXISTS (SELECT 1 FROM schedule_days WHERE tenant_id = 0x00000000000040008000000000000002 AND schedule_id = 0x10000000000040008000000000000006 AND weekday = 1);

INSERT INTO schedule_days (tenant_id, schedule_id, weekday)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 2
WHERE NOT EXISTS (SELECT 1 FROM schedule_days WHERE tenant_id = 0x00000000000040008000000000000002 AND schedule_id = 0x10000000000040008000000000000006 AND weekday = 2);

INSERT INTO schedule_days (tenant_id, schedule_id, weekday)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 3
WHERE NOT EXISTS (SELECT 1 FROM schedule_days WHERE tenant_id = 0x00000000000040008000000000000002 AND schedule_id = 0x10000000000040008000000000000006 AND weekday = 3);

INSERT INTO schedule_days (tenant_id, schedule_id, weekday)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 4
WHERE NOT EXISTS (SELECT 1 FROM schedule_days WHERE tenant_id = 0x00000000000040008000000000000002 AND schedule_id = 0x10000000000040008000000000000006 AND weekday = 4);

INSERT INTO schedule_days (tenant_id, schedule_id, weekday)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000006, 5
WHERE NOT EXISTS (SELECT 1 FROM schedule_days WHERE tenant_id = 0x00000000000040008000000000000002 AND schedule_id = 0x10000000000040008000000000000006 AND weekday = 5);

INSERT INTO role_area_scopes (tenant_id, role_id, area_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000066, 0x10000000000040008000000000000003
WHERE NOT EXISTS (SELECT 1 FROM role_area_scopes WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000066 AND area_id = 0x10000000000040008000000000000003);

INSERT INTO role_area_scopes (tenant_id, role_id, area_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000067, 0x10000000000040008000000000000003
WHERE NOT EXISTS (SELECT 1 FROM role_area_scopes WHERE tenant_id = 0x00000000000040008000000000000002 AND role_id = 0x10000000000040008000000000000067 AND area_id = 0x10000000000040008000000000000003);

INSERT INTO users (tenant_id, id, display_name, firm_id, site_id, area_id, position_id, schedule_id, sociedad_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000007, 'Colaborador Demo', 0x10000000000040008000000000000001, 0x10000000000040008000000000000002, 0x10000000000040008000000000000003, 0x10000000000040008000000000000004, 0x10000000000040008000000000000006, 0x10000000000040008000000000000005
WHERE NOT EXISTS (SELECT 1 FROM users WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000007);

INSERT INTO user_assignments (tenant_id, id, user_id, firm_id, site_id, area_id, position_id, schedule_id, sociedad_id, starts_at)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000008, 0x10000000000040008000000000000007, 0x10000000000040008000000000000001, 0x10000000000040008000000000000002, 0x10000000000040008000000000000003, 0x10000000000040008000000000000004, 0x10000000000040008000000000000006, 0x10000000000040008000000000000005, '2026-01-01 00:00:00'
WHERE NOT EXISTS (SELECT 1 FROM user_assignments WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000008);

INSERT INTO user_roles (tenant_id, user_id, role_id)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000007, 0x1000000000004000800000000000006b
WHERE NOT EXISTS (SELECT 1 FROM user_roles WHERE tenant_id = 0x00000000000040008000000000000002 AND user_id = 0x10000000000040008000000000000007 AND role_id = 0x1000000000004000800000000000006b);

INSERT INTO tier (tenant_id, id, name, badge_label)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000c9, 'Essential', 'LDKeeper Essential'
WHERE NOT EXISTS (SELECT 1 FROM tier WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x100000000000400080000000000000c9);

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000c9, 'devices'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000c9 AND module_code = 'devices');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000c9, 'activity'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000c9 AND module_code = 'activity');

INSERT INTO tier (tenant_id, id, name, badge_label)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000ca, 'Pro', 'LDKeeper Pro'
WHERE NOT EXISTS (SELECT 1 FROM tier WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x100000000000400080000000000000ca);

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000ca, 'devices'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000ca AND module_code = 'devices');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000ca, 'activity'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000ca AND module_code = 'activity');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000ca, 'productivity'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000ca AND module_code = 'productivity');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000ca, 'presence'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000ca AND module_code = 'presence');

INSERT INTO tier (tenant_id, id, name, badge_label)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'Business', 'LDKeeper Business'
WHERE NOT EXISTS (SELECT 1 FROM tier WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x100000000000400080000000000000cb);

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'devices'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'devices');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'activity'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'activity');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'productivity'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'productivity');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'presence'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'presence');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'policies'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'policies');

INSERT INTO tier_module (tenant_id, tier_id, module_code)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cb, 'integrations'
WHERE NOT EXISTS (SELECT 1 FROM tier_module WHERE tenant_id = 0x00000000000040008000000000000002 AND tier_id = 0x100000000000400080000000000000cb AND module_code = 'integrations');

INSERT INTO subscriptions (tenant_id, id, user_id, tier_id, starts_at)
SELECT 0x00000000000040008000000000000002, 0x100000000000400080000000000000cc, 0x10000000000040008000000000000007, 0x100000000000400080000000000000c9, '2026-01-01 00:00:00'
WHERE NOT EXISTS (SELECT 1 FROM subscriptions WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x100000000000400080000000000000cc);

INSERT INTO policy_documents (tenant_id, id, name)
SELECT 0x00000000000040008000000000000001, 0x1000000000004000800000000000012d, 'Baseline global vacía'
WHERE NOT EXISTS (SELECT 1 FROM policy_documents WHERE tenant_id = 0x00000000000040008000000000000001 AND id = 0x1000000000004000800000000000012d);

INSERT INTO policy_versions (tenant_id, policy_id, policy_version, document, content_hash)
SELECT 0x00000000000040008000000000000001, 0x1000000000004000800000000000012d, 1, JSON_OBJECT('rules', JSON_ARRAY()), UNHEX(SHA2(CAST(JSON_OBJECT('rules', JSON_ARRAY()) AS CHAR),256))
WHERE NOT EXISTS (SELECT 1 FROM policy_versions WHERE tenant_id = 0x00000000000040008000000000000001 AND policy_id = 0x1000000000004000800000000000012d AND policy_version = 1);

INSERT INTO policy_assignments (tenant_id, id, policy_id, policy_version, scope)
SELECT 0x00000000000040008000000000000001, 0x1000000000004000800000000000012e, 0x1000000000004000800000000000012d, 1, 'global'
WHERE NOT EXISTS (SELECT 1 FROM policy_assignments WHERE tenant_id = 0x00000000000040008000000000000001 AND id = 0x1000000000004000800000000000012e);

INSERT INTO principals (tenant_id, id, kind)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000191, 'system'
WHERE NOT EXISTS (SELECT 1 FROM principals WHERE tenant_id = 0x00000000000040008000000000000001 AND id = 0x10000000000040008000000000000191);

INSERT INTO principals (tenant_id, id, kind)
SELECT 0x00000000000040008000000000000002, 0x10000000000040008000000000000191, 'system'
WHERE NOT EXISTS (SELECT 1 FROM principals WHERE tenant_id = 0x00000000000040008000000000000002 AND id = 0x10000000000040008000000000000191);
