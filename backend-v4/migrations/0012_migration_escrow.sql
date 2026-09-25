CREATE TABLE migration_authorizations (
  tenant_id BINARY(16) NOT NULL,
  enrollment_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  PRIMARY KEY (tenant_id,enrollment_id),
  KEY (tenant_id,device_id),
  FOREIGN KEY (tenant_id,enrollment_id) REFERENCES enrollments (tenant_id,id),
  FOREIGN KEY (tenant_id,device_id) REFERENCES devices (tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE device_escrow (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  id BINARY(16) NOT NULL,
  account_sid VARCHAR(184) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  key_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  envelope VARBINARY(6144) NOT NULL,
  envelope_hash BINARY(32) NOT NULL,
  persisted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  verified_at DATETIME(6) NULL,
  PRIMARY KEY (tenant_id,device_id,revision),
  UNIQUE KEY (tenant_id,id),
  CHECK (revision > 0),
  FOREIGN KEY (tenant_id,device_id) REFERENCES devices (tenant_id,id)
) ENGINE=InnoDB;

CREATE TABLE device_migrations (
  tenant_id BINARY(16) NOT NULL,
  device_id BINARY(16) NOT NULL,
  phase ENUM('instalado','enrolado','escrow_ok','degradado','pendiente_reinicio','completo','excepcion') NOT NULL,
  safe_phase VARCHAR(24) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL,
  escrow_revision BIGINT UNSIGNED NULL,
  report_hash BINARY(32) NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id,device_id),
  FOREIGN KEY (tenant_id,device_id) REFERENCES devices (tenant_id,id),
  FOREIGN KEY (tenant_id,device_id,escrow_revision) REFERENCES device_escrow (tenant_id,device_id,revision)
) ENGINE=InnoDB;

INSERT INTO permissions (code,label,resource,action,delegable,platform_only) VALUES
 ('migracion.ver','Consultar migracion','migration','read',TRUE,FALSE),
 ('migracion.gestionar','Gestionar migracion y escrow','migration','manage',TRUE,FALSE),
 ('migracion.recuperar','Recuperar credencial local con auditoria','migration','recover',FALSE,FALSE);
