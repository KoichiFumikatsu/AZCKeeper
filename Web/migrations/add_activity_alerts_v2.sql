-- =============================================
-- Migration: Activity Alerts v2
-- Adds: review_result column + app classifications table
-- =============================================

-- 1. Add review_result to dual job alerts
ALTER TABLE keeper_dual_job_alerts
  ADD COLUMN review_result ENUM('productive','unproductive') DEFAULT NULL AFTER is_reviewed;

-- 2. App/window classification table (productive vs unproductive)
CREATE TABLE IF NOT EXISTS keeper_app_classifications (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  app_pattern VARCHAR(255) NOT NULL,
  classification ENUM('productive','unproductive') NOT NULL,
  description VARCHAR(500) DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_pattern (app_pattern)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
