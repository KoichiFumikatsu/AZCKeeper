-- Grupos empresariales y cuentas de grupo (spec de integraciones §8.5).
-- Una cuenta de grupo es una cuenta de empresa (la persona vive en su empresa "casa") que además tiene roles
-- en las otras empresas de su grupo. No es superadmin: nunca ve empresas fuera del grupo.
-- Los grupos son de la plataforma (tenant_id fijo = plataforma): toda tabla lleva tenant_id (migrations/verify.php).
CREATE TABLE IF NOT EXISTS company_groups (
  tenant_id BINARY(16) NOT NULL DEFAULT 0x00000000000040008000000000000001,
  id BINARY(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  version BIGINT UNSIGNED NOT NULL DEFAULT 1,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (tenant_id, id),
  UNIQUE KEY company_groups_id (id),
  CONSTRAINT company_groups_platform CHECK (tenant_id = 0x00000000000040008000000000000001),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS company_group_members (
  group_tenant_id BINARY(16) NOT NULL DEFAULT 0x00000000000040008000000000000001,
  group_id BINARY(16) NOT NULL,
  tenant_id BINARY(16) NOT NULL,
  PRIMARY KEY (group_id, tenant_id),
  UNIQUE KEY company_group_members_tenant (tenant_id),
  FOREIGN KEY (group_tenant_id, group_id) REFERENCES company_groups (tenant_id, id),
  FOREIGN KEY (tenant_id) REFERENCES tenants (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS admin_account_roles (
  admin_tenant_id BINARY(16) NOT NULL,
  admin_id BINARY(16) NOT NULL,
  tenant_id BINARY(16) NOT NULL,
  role_id BINARY(16) NOT NULL,
  PRIMARY KEY (admin_tenant_id, admin_id, tenant_id, role_id),
  KEY admin_account_roles_tenant (tenant_id, role_id),
  FOREIGN KEY (admin_tenant_id, admin_id) REFERENCES admin_accounts (tenant_id, id),
  FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- group_tenant_id siempre es la plataforma cuando hay grupo (las FK de negocio incluyen tenant: verify.php).
ALTER TABLE admin_accounts ADD COLUMN group_tenant_id BINARY(16) NULL AFTER user_id, ADD COLUMN group_id BINARY(16) NULL AFTER group_tenant_id,
  ADD CONSTRAINT admin_accounts_group FOREIGN KEY (group_tenant_id, group_id) REFERENCES company_groups (tenant_id, id),
  ADD CONSTRAINT admin_accounts_group_pair CHECK ((group_id IS NULL) = (group_tenant_id IS NULL));

-- Una cuenta de grupo actua en las otras empresas de su grupo: su principal en esa empresa apunta a la cuenta de su
-- empresa casa. La restriccion anterior (cuenta de la misma empresa o de plataforma) pasa a AdminAuth::context, que solo
-- admite empresas del grupo donde la cuenta tiene roles. El nombre de la restriccion lo pone el motor (MySQL/MariaDB).
SET @principals_check = (SELECT c.constraint_name FROM information_schema.table_constraints c
  JOIN information_schema.check_constraints k ON k.constraint_schema = c.constraint_schema AND k.constraint_name = c.constraint_name
  WHERE c.table_schema = DATABASE() AND c.table_name = 'principals' AND c.constraint_type = 'CHECK'
    AND k.check_clause LIKE '%admin_tenant_id%' AND k.check_clause NOT LIKE '%kind%' LIMIT 1);
SET @principals_sql = IF(@principals_check IS NULL, 'DO 0', CONCAT('ALTER TABLE principals DROP CONSTRAINT `', @principals_check, '`'));
PREPARE principals_stmt FROM @principals_sql;
EXECUTE principals_stmt;
DEALLOCATE PREPARE principals_stmt;
