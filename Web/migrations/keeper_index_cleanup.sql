-- ============================================================
-- keeper_index_cleanup.sql
-- Limpieza de índices duplicados + nuevos índices útiles
-- Solo tablas keeper_*
-- Fecha: 2026-03-07
-- ============================================================

-- ════════════════════════════════════════════
-- PASO 1: ELIMINAR ÍNDICES DUPLICADOS/REDUNDANTES
-- ════════════════════════════════════════════

-- keeper_activity_day: uq_activity_day es duplicado exacto de uk_keeper_activity_unique
-- Ambos: UNIQUE (user_id, device_id, day_date)
-- Origen: SQL1.txt ya los traía ambos desde la creación
ALTER TABLE `keeper_activity_day` DROP INDEX `uq_activity_day`;

-- keeper_devices: idx_devices_guid es duplicado de uk_keeper_devices_guid (UNIQUE)
-- Ambos indexan: device_guid
-- Origen: Agregado manualmente en producción
ALTER TABLE `keeper_devices` DROP INDEX `idx_devices_guid`;

-- keeper_policy_assignments: idx_policy_global cubierto por ix_keeper_policy_scope_active
-- idx_policy_global = (scope, is_active) → es prefijo de ix_keeper_policy_scope_active = (scope, is_active, priority)
-- Origen: Agregado manualmente en producción
ALTER TABLE `keeper_policy_assignments` DROP INDEX `idx_policy_global`;

-- keeper_policy_assignments: idx_policy_user se solapa con ix_keeper_policy_user_active
-- idx_policy_user = (scope, user_id, is_active) vs ix_keeper_policy_user_active = (user_id, is_active, priority)
-- La query real busca por user_id + is_active + priority, el idx_policy_user agrega scope pero la query UNION ya filtra por scope
ALTER TABLE `keeper_policy_assignments` DROP INDEX `idx_policy_user`;

-- keeper_policy_assignments: idx_policy_device se solapa con ix_keeper_policy_device_active
-- Misma lógica que idx_policy_user
ALTER TABLE `keeper_policy_assignments` DROP INDEX `idx_policy_device`;

-- keeper_sessions: idx_sessions_token_expires es innecesario
-- token_hash ya tiene UNIQUE KEY (uk_keeper_sessions_tokenhash) → lookup O(1)
-- Agregar expires_at al compuesto no ayuda porque token_hash ya es único
-- Origen: Agregado manualmente en producción
ALTER TABLE `keeper_sessions` DROP INDEX `idx_sessions_token_expires`;


-- ════════════════════════════════════════════
-- PASO 2: NUEVOS ÍNDICES ÚTILES
-- ════════════════════════════════════════════

-- keeper_users: status se filtra en dashboard y listados
ALTER TABLE `keeper_users`
  ADD KEY `ix_keeper_users_status` (`status`);

-- keeper_admin_accounts: area_scope_id existe como columna FK pero sin índice
ALTER TABLE `keeper_admin_accounts`
  ADD KEY `ix_keeper_admin_area` (`area_scope_id`);

-- keeper_device_locks: locked_by_admin_id es FK sin índice
ALTER TABLE `keeper_device_locks`
  ADD KEY `ix_device_locks_admin` (`locked_by_admin_id`);

-- keeper_work_schedules: consulta global WHERE user_id IS NULL AND is_active=1
ALTER TABLE `keeper_work_schedules`
  ADD KEY `ix_keeper_ws_active` (`is_active`);

-- keeper_client_releases: combinación frecuente WHERE is_active=1 AND is_beta=0 ORDER BY created_at
ALTER TABLE `keeper_client_releases`
  ADD KEY `ix_releases_active_beta` (`is_active`, `is_beta`),
  ADD KEY `ix_releases_created` (`created_at`);

-- keeper_window_episode: agregación por app/proceso en reportes
ALTER TABLE `keeper_window_episode`
  ADD KEY `ix_keeper_we_user_day_process` (`user_id`, `day_date`, `process_name`);
