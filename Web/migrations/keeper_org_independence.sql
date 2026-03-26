-- ====================================================================
-- keeper_org_independence.sql
-- Tablas organizacionales propias del ecosistema Keeper.
-- Reemplaza dependencias de tablas legacy (firm, areas, cargos, sedes).
--
-- Estrategia: crear keeper_* con legacy_*_id para mapeo.
--   Si hay BD legacy → seedear preservando IDs.
--   Si no → las tablas quedan vacías para llenado manual o CSV.
--
-- IMPORTANTE: Ejecutar ANTES de las migraciones de sociedad_id.
--             Reemplaza add_sociedades.sql.
-- Fecha: 2026-03-07
-- ====================================================================

-- ════════════════════════════════════════════
-- PASO 1: Crear tablas keeper_* organizacionales
-- ════════════════════════════════════════════

-- 1a) Sociedades (nueva entidad, sin equivalente legacy)
CREATE TABLE IF NOT EXISTS `keeper_sociedades` (
  `id`          bigint       NOT NULL AUTO_INCREMENT,
  `nombre`      varchar(255) NOT NULL,
  `nit`         varchar(50)  DEFAULT NULL COMMENT 'NIT o identificación fiscal',
  `descripcion` varchar(500) DEFAULT NULL,
  `activa`      tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_sociedad_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sociedades/organizaciones a las que pertenecen los empleados';

-- 1b) Firmas (clientes — equivale a legacy `firm`)
CREATE TABLE IF NOT EXISTS `keeper_firmas` (
  `id`             bigint       NOT NULL AUTO_INCREMENT,
  `nombre`         varchar(255) NOT NULL,
  `manager`        varchar(255) DEFAULT NULL,
  `mail_manager`   varchar(255) DEFAULT NULL,
  `descripcion`    varchar(500) DEFAULT NULL,
  `activa`         tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_firm_id` int          DEFAULT NULL COMMENT 'Mapeo a firm.id legacy',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_firma_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_firma_legacy` (`legacy_firm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Firmas/clientes (ej. bufetes de EE.UU.)';

-- 1c) Áreas (equivale a legacy `areas`)
CREATE TABLE IF NOT EXISTS `keeper_areas` (
  `id`              bigint       NOT NULL AUTO_INCREMENT,
  `nombre`          varchar(255) NOT NULL,
  `descripcion`     varchar(500) DEFAULT NULL,
  `padre_id`        bigint       DEFAULT NULL COMMENT 'Área padre (jerarquía)',
  `activa`          tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_area_id`  int          DEFAULT NULL COMMENT 'Mapeo a areas.id legacy',
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_area_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_area_legacy` (`legacy_area_id`),
  KEY `ix_keeper_area_padre` (`padre_id`),
  CONSTRAINT `fk_keeper_area_padre` FOREIGN KEY (`padre_id`) REFERENCES `keeper_areas`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Áreas organizacionales con jerarquía padre/hijo';

-- 1d) Cargos (equivale a legacy `cargos`)
CREATE TABLE IF NOT EXISTS `keeper_cargos` (
  `id`                bigint       NOT NULL AUTO_INCREMENT,
  `nombre`            varchar(255) NOT NULL,
  `descripcion`       varchar(500) DEFAULT NULL,
  `nivel_jerarquico`  int          NOT NULL DEFAULT 0 COMMENT '1=Director, 2=Coordinador, 3=Operativo, etc. Mayor número = menor rango',
  `activo`            tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_cargo_id`   int          DEFAULT NULL COMMENT 'Mapeo a cargos.id legacy',
  `created_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_cargo_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_cargo_legacy` (`legacy_cargo_id`),
  KEY `ix_keeper_cargo_nivel` (`nivel_jerarquico`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Cargos con nivel jerárquico para visibilidad';

-- 1e) Sedes (equivale a legacy `sedes`)
CREATE TABLE IF NOT EXISTS `keeper_sedes` (
  `id`              bigint       NOT NULL AUTO_INCREMENT,
  `nombre`          varchar(255) NOT NULL,
  `codigo`          varchar(50)  DEFAULT NULL,
  `descripcion`     varchar(500) DEFAULT NULL,
  `activa`          tinyint(1)   NOT NULL DEFAULT 1,
  `legacy_sede_id`  int          DEFAULT NULL COMMENT 'Mapeo a sedes.id legacy',
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_keeper_sede_nombre` (`nombre`),
  UNIQUE KEY `uq_keeper_sede_legacy` (`legacy_sede_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Sedes/ubicaciones físicas';


-- ════════════════════════════════════════════
-- PASO 2: Seed desde legacy (OPCIONAL)
--   Preserva IDs para no romper FKs existentes.
--   Si no hay tablas legacy, este paso se salta sin error.
-- ════════════════════════════════════════════

-- 2a) Firmas ← firm
INSERT IGNORE INTO `keeper_firmas` (`id`, `nombre`, `manager`, `mail_manager`, `legacy_firm_id`, `created_at`, `updated_at`)
SELECT `id`, `name`, `manager`, `mail_manager`, `id`, COALESCE(`created_at`, NOW()), COALESCE(`updated_at`, NOW())
FROM `firm`;

-- 2b) Áreas ← areas (padre_id se seedea como legacy_area_id, después se mapea)
INSERT IGNORE INTO `keeper_areas` (`id`, `nombre`, `descripcion`, `activa`, `legacy_area_id`, `created_at`, `updated_at`)
SELECT `id`, `nombre`, `descripcion`, 1, `id`, COALESCE(`created_at`, NOW()), COALESCE(`updated_at`, NOW())
FROM `areas`;

-- Mapear padre_id: pasar del ID legacy al mismo ID en keeper_areas (coinciden porque preservamos)
UPDATE `keeper_areas` ka
  INNER JOIN `areas` a ON a.id = ka.legacy_area_id
SET ka.padre_id = a.padre_id
WHERE a.padre_id IS NOT NULL;

-- 2c) Cargos ← cargos  (nivel_jerarquico queda en 0, se configura después)
INSERT IGNORE INTO `keeper_cargos` (`id`, `nombre`, `legacy_cargo_id`, `created_at`)
SELECT `id`, `nombre`, `id`, COALESCE(`created_at`, NOW())
FROM `cargos`;

-- 2d) Sedes ← sedes
INSERT IGNORE INTO `keeper_sedes` (`id`, `nombre`, `codigo`, `descripcion`, `activa`, `legacy_sede_id`, `created_at`)
SELECT `id`, `nombre`, `codigo`, `descripcion`, COALESCE(`activa`, 1), `id`, COALESCE(`creado_en`, NOW())
FROM `sedes`;


-- ════════════════════════════════════════════
-- PASO 3: Agregar sociedad_id a keeper_user_assignments
-- ════════════════════════════════════════════

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keeper_user_assignments' AND COLUMN_NAME = 'sociedad_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `keeper_user_assignments` ADD COLUMN `sociedad_id` bigint DEFAULT NULL COMMENT ''FK keeper_sociedades.id'' AFTER `sede_id`, ADD KEY `ix_keeper_assignment_sociedad` (`sociedad_id`)',
    'SELECT ''sociedad_id already exists in keeper_user_assignments''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ════════════════════════════════════════════
-- PASO 4: Agregar sociedad_scope_id a keeper_admin_accounts
-- ════════════════════════════════════════════

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'keeper_admin_accounts' AND COLUMN_NAME = 'sociedad_scope_id');
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `keeper_admin_accounts` ADD COLUMN `sociedad_scope_id` bigint DEFAULT NULL COMMENT ''Solo ve empleados de esta sociedad (NULL=todas)'' AFTER `sede_scope_id`, ADD KEY `ix_keeper_admin_sociedad` (`sociedad_scope_id`)',
    'SELECT ''sociedad_scope_id already exists in keeper_admin_accounts''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;


-- ════════════════════════════════════════════
-- PASO 5: Limpiar tabla sociedades legacy si se creó antes
-- ════════════════════════════════════════════

DROP TABLE IF EXISTS `sociedades`;
