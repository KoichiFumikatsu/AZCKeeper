-- Agente 4.0.24: mudanza de servidor por orden firmada (devkeep -> keep). Cada fila es un manifiesto JWS ES256 firmado con
-- la clave de RELEASES (tools/sign-relocation.ps1), guardado tal cual; las columnas repiten su payload para filtrar.
-- El servidor de origen (from_base = su ORIGIN) lo entrega en /client/sync a los agentes 4.0.24+ de los tenants incluidos
-- y no excluidos; con enforced_at (modo mudado) les responde 409 device_relocated sin aceptar datos.
-- Al copiar la BD al destino la fila viaja con ella pero alli no aplica: su ORIGIN no es from_base.
-- Solo plataforma: tenant_id es siempre el de plataforma (exigido por verify.php para toda tabla de datos).

CREATE TABLE IF NOT EXISTS relocations (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  sequence BIGINT UNSIGNED NOT NULL,
  from_base VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  to_base VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  tenants JSON NOT NULL,
  exclude_devices JSON NOT NULL,
  not_before DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  key_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  relocation_jws TEXT CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  registered_by BINARY(16) NOT NULL,
  registered_at DATETIME(6) NOT NULL,
  enforced_at DATETIME(6) NULL,
  enforced_by BINARY(16) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY relocations_sequence (tenant_id, sequence),
  KEY relocations_origin (tenant_id, from_base, expires_at),
  CONSTRAINT relocations_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CONSTRAINT relocations_window CHECK (expires_at > not_before)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Permiso de plataforma, no delegable: registrar mudanzas y activar el modo mudado.
INSERT INTO permissions (code, label, resource, action, delegable, platform_only)
SELECT 'servidor.mudar', 'Mudar los equipos a otro servidor (plataforma)', 'servidor', 'mudar', 0, 1
WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE code = 'servidor.mudar');

INSERT INTO role_template_permissions (seed_key, permission_code)
SELECT 'super_admin', 'servidor.mudar'
WHERE EXISTS (SELECT 1 FROM role_templates WHERE seed_key = 'super_admin')
  AND NOT EXISTS (SELECT 1 FROM role_template_permissions WHERE seed_key = 'super_admin' AND permission_code = 'servidor.mudar');

INSERT INTO role_permissions (tenant_id, role_id, permission_code)
SELECT 0x00000000000040008000000000000001, 0x10000000000040008000000000000064, 'servidor.mudar'
WHERE EXISTS (SELECT 1 FROM roles WHERE tenant_id = 0x00000000000040008000000000000001 AND id = 0x10000000000040008000000000000064)
  AND NOT EXISTS (SELECT 1 FROM role_permissions WHERE tenant_id = 0x00000000000040008000000000000001 AND role_id = 0x10000000000040008000000000000064 AND permission_code = 'servidor.mudar');
