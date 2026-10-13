ALTER TABLE tenants ADD punctuality_margin_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
  ADD default_schedule_id BINARY(16) NULL,
  ADD CONSTRAINT tenant_default_schedule FOREIGN KEY (tenant_id,default_schedule_id) REFERENCES schedules(tenant_id,id),
  ADD CHECK (punctuality_margin_minutes <= 180);
ALTER TABLE users ADD employment_status ENUM('contratado','retirado','licencia','vacaciones','incapacidad') NOT NULL DEFAULT 'contratado',
  ADD portal_login_enabled BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE users SET employment_status='retirado' WHERE status='inactive';
ALTER TABLE productivity_cron_status ADD last_error VARCHAR(1000) NULL;
CREATE TABLE punctuality_margins (
  tenant_id BINARY(16) NOT NULL, from_day DATE NOT NULL, minutes SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id,from_day), FOREIGN KEY (tenant_id) REFERENCES tenants(tenant_id)
) ENGINE=InnoDB;
INSERT INTO punctuality_margins SELECT tenant_id,UTC_DATE()-INTERVAL 29 DAY,15 FROM tenants;
UPDATE tenants t SET default_schedule_id=(
  SELECT s.id FROM schedules s WHERE s.tenant_id=t.tenant_id AND s.placeholder=FALSE AND s.timezone='America/Bogota'
    AND (SELECT COUNT(*) FROM schedule_days d WHERE d.tenant_id=s.tenant_id AND d.schedule_id=s.id)=5
    AND (SELECT COUNT(*) FROM schedule_days d WHERE d.tenant_id=s.tenant_id AND d.schedule_id=s.id
      AND d.weekday BETWEEN 1 AND 5 AND COALESCE(d.start_local,s.start_local)='08:00:00'
      AND COALESCE(d.end_local,s.end_local)=IF(d.weekday=5,'17:00:00','17:30:00'))=5
  ORDER BY s.id LIMIT 1
);
INSERT INTO rollup_days (tenant_id,day)
SELECT t.tenant_id,UTC_DATE()-INTERVAL (tens.n*10+ones.n) DAY FROM tenants t
CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2) tens
CROSS JOIN (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9) ones
WHERE TRUE
ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL;
INSERT INTO admin_policy_recompiles (tenant_id) SELECT tenant_id FROM tenants WHERE default_schedule_id IS NOT NULL
ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6);
