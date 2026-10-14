-- Portal (2026-10-14, decisiones del dueno): el superadmin de plataforma tambien entra al Portal (sin persona: elige la
-- empresa con X-Tenant-ID, como en el panel) y bloqueo de 15 min tras 10 contrasenas fallidas seguidas para un correo.
ALTER TABLE portal_tokens MODIFY user_id BINARY(16) NULL;

-- Intentos fallidos de login del Portal por correo (solo el SHA-256 del correo en minusculas), exista o no la cuenta,
-- para no revelar que correos existen. La fila se borra al entrar bien. De la plataforma, como portal_origins.
CREATE TABLE IF NOT EXISTS portal_login_lockouts (
  tenant_id BINARY(16) NOT NULL,
  email_hash BINARY(32) NOT NULL,
  failures SMALLINT UNSIGNED NOT NULL,
  window_started_at DATETIME(6) NOT NULL,
  locked_until DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, email_hash),
  KEY portal_login_lockouts_window (window_started_at),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
