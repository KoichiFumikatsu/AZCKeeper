-- ============================================================
-- MIGRACIÓN: add_schedule_days.sql
-- Agrega soporte de días específicos a keeper_work_schedules.
-- applicable_days: lista CSV de días (0=Dom,1=Lun,...6=Sáb)
-- NULL o vacío = todos los días.
-- Aplicar en: pipezafra_soporte_db
-- ============================================================

ALTER TABLE `keeper_work_schedules`
  ADD COLUMN `applicable_days` VARCHAR(20) DEFAULT '1,2,3,4,5'
  COMMENT 'Días aplicables CSV (0=Dom,1=Lun..6=Sáb), NULL=todos'
  AFTER `lunch_end_time`;
