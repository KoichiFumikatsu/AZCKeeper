CREATE TABLE suspicious_apps (
  tenant_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, app_pattern VARCHAR(190) NOT NULL,
  category ENUM('remote_desktop','foreign_vpn','foreign_workspace','vm') NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '', active BOOLEAN NOT NULL DEFAULT TRUE,
  PRIMARY KEY (tenant_id,id), UNIQUE KEY (tenant_id,app_pattern), FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE suspicious_app_detections (
  tenant_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL,
  user_assignment_id BINARY(16) NOT NULL, device_id BINARY(16) NOT NULL, app_id BINARY(16) NOT NULL,
  day DATE NOT NULL, process_name VARCHAR(160) NOT NULL, active_seconds BIGINT UNSIGNED NOT NULL,
  first_activity DATETIME(6) NOT NULL, last_activity DATETIME(6) NOT NULL,
  PRIMARY KEY (tenant_id,id), UNIQUE KEY (tenant_id,day,user_assignment_id,device_id,app_id,process_name),
  FOREIGN KEY (tenant_id,user_assignment_id,user_id) REFERENCES user_assignments(tenant_id,id,user_id),
  FOREIGN KEY (tenant_id,device_id) REFERENCES devices(tenant_id,id),
  FOREIGN KEY (tenant_id,app_id) REFERENCES suspicious_apps(tenant_id,id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE dual_job_alerts (
  tenant_id BINARY(16) NOT NULL, id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, day DATE NOT NULL,
  alert_type ENUM('after_hours_pattern','foreign_app','remote_desktop','suspicious_idle') NOT NULL,
  severity ENUM('low','medium','high') NOT NULL, evidence JSON NULL,
  reviewed BOOLEAN NOT NULL DEFAULT FALSE, notes TEXT NULL, source ENUM('k3','worker') NOT NULL,
  PRIMARY KEY (tenant_id,id), KEY (tenant_id,day,user_id), FOREIGN KEY (tenant_id,user_id) REFERENCES users(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE install_coverage_notes (
  tenant_id BINARY(16) NOT NULL, user_id BINARY(16) NOT NULL, note_text TEXT NOT NULL,
  is_exempt BOOLEAN NOT NULL DEFAULT FALSE, PRIMARY KEY (tenant_id,user_id),
  FOREIGN KEY (tenant_id,user_id) REFERENCES users(tenant_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE panel_settings (
  tenant_id BINARY(16) NOT NULL, install_coverage_heartbeat_days SMALLINT UNSIGNED NOT NULL DEFAULT 7,
  PRIMARY KEY (tenant_id), CHECK (install_coverage_heartbeat_days BETWEEN 1 AND 90),
  FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE productivity_cron_status (
  tenant_id BINARY(16) NOT NULL, last_run DATETIME(6) NULL, last_success DATETIME(6) NULL,
  status ENUM('running','ok','failed') NOT NULL, PRIMARY KEY (tenant_id),
  FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
INSERT INTO permissions (code,label,resource,action) VALUES
 ('festivos.ver','Consultar festivos','festivos','ver'),('festivos.editar','Administrar festivos','festivos','editar'),
 ('cumplimiento.ver','Consultar señales de cumplimiento','cumplimiento','ver'),('cumplimiento.editar','Administrar catálogo de señales','cumplimiento','editar'),
 ('cobertura.ver','Consultar cobertura','cobertura','ver'),('cobertura.editar','Editar notas de cobertura','cobertura','editar'),
 ('operacion.logs','Consultar logs del cliente','operacion','logs'),('operacion.salud','Consultar salud del servidor','operacion','salud'),
 ('operacion.ajustes_ver','Consultar ajustes','operacion','ajustes_ver'),('operacion.ajustes_editar','Editar ajustes','operacion','ajustes_editar');
