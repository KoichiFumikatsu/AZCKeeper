-- Migration: Create keeper_client_releases table
-- Purpose: Manage AZCKeeper client versions and releases
-- Date: 2026-02-05

CREATE TABLE IF NOT EXISTS `keeper_client_releases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `version` varchar(50) NOT NULL COMMENT 'Semantic version (e.g., 3.0.0.1)',
  `download_url` varchar(500) NOT NULL COMMENT 'URL to download ZIP package',
  `file_size` bigint DEFAULT 0 COMMENT 'File size in bytes',
  `release_notes` text COMMENT 'Release notes in markdown',
  `is_beta` tinyint(1) DEFAULT 0 COMMENT 'Is this a beta version?',
  `force_update` tinyint(1) DEFAULT 0 COMMENT 'Force clients to update immediately',
  `minimum_version` varchar(50) DEFAULT NULL COMMENT 'Minimum required version (older = critical)',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Is this release available?',
  `release_date` date DEFAULT NULL COMMENT 'Official release date',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_version` (`version`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_is_beta` (`is_beta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default/current version
INSERT INTO `keeper_client_releases` 
  (`version`, `download_url`, `file_size`, `release_notes`, `is_beta`, `force_update`, `minimum_version`, `is_active`, `release_date`) 
VALUES 
  ('3.0.0.1', 
   'https://github.com/KoichiFumikatsu/AZCKeeper/releases/download/v3.0.0.1/AZCKeeper_v3.0.0.1.zip',
   0,
   'Versión inicial con sistema de actualización automática.\n\n**Características:**\n- Sistema de tracking de actividad\n- Bloqueo remoto de dispositivos\n- Políticas configurables\n- Auto-actualización',
   0,
   0,
   '3.0.0.0',
   1,
   '2026-01-21'
  );
