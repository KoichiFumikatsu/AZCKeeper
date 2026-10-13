-- Puerta del Portal (/portal/v1): tokens por persona, origenes CORS autorizados y canal en la auditoria (2026-10-13).
-- Tokens opacos: solo se guarda su SHA-256. access (15 min) y refresh (rotan; reutilizar uno ya usado revoca la familia).
-- Validos solo con la misma auth_version de la cuenta del panel: cambiar la contrasena o subir la version los invalida.
CREATE TABLE IF NOT EXISTS portal_tokens (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  family_id BINARY(16) NOT NULL,
  kind ENUM('access','refresh') NOT NULL,
  token_hash BINARY(32) NOT NULL,
  admin_id BINARY(16) NOT NULL,
  user_id BINARY(16) NOT NULL,
  auth_version BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL,
  expires_at DATETIME(6) NOT NULL,
  used_at DATETIME(6) NULL,
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY portal_tokens_hash (token_hash),
  KEY portal_tokens_family (tenant_id, family_id),
  KEY portal_tokens_admin (tenant_id, admin_id),
  KEY portal_tokens_expires (expires_at),
  FOREIGN KEY (tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  FOREIGN KEY (tenant_id, user_id) REFERENCES users (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (expires_at > created_at AND expires_at <= created_at + INTERVAL 8 HOUR)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Origenes del Portal que reciben CORS (exactos, sin comodines). De la plataforma: tenant_id es siempre el de la plataforma.
CREATE TABLE IF NOT EXISTS portal_origins (
  tenant_id BINARY(16) NOT NULL,
  id BINARY(16) NOT NULL,
  origin VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY portal_origins_origin (origin),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id),
  CHECK (tenant_id = 0x00000000000040008000000000000001)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Canal de la accion auditada: portal = via /portal/v1 con token de persona; NULL = panel, agente o sistema (historico).
ALTER TABLE audit_log ADD COLUMN channel ENUM('portal') NULL;
