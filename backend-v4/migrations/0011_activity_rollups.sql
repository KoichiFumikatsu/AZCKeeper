ALTER TABLE episodes ADD COLUMN call_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  ADD CONSTRAINT episode_calls_active CHECK (call_seconds <= active_seconds),
  ADD KEY episode_rollup_day (tenant_id,event_date,user_assignment_id,started_at);
ALTER TABLE episode_daily ADD COLUMN call_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE day_summary ADD COLUMN call_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN first_activity DATETIME(6) NULL,
  ADD COLUMN last_activity DATETIME(6) NULL,
  ADD COLUMN late_seconds INT UNSIGNED NULL;
ALTER TABLE focus_daily ADD COLUMN context_switches BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN deep_work_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN distraction_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE rollup_days ADD KEY rollup_pending (dirty,day,tenant_id);
