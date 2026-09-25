ALTER TABLE focus_daily
  ADD COLUMN first_activity_time TIME NULL,
  ADD COLUMN scheduled_start TIME NULL,
  ADD COLUMN punctuality_minutes SMALLINT NULL COMMENT 'K3: positive early, negative late; NULL on non-working days',
  ADD COLUMN productivity_pct DECIMAL(5,2) NULL,
  ADD COLUMN constancy_pct DECIMAL(5,2) NULL,
  ADD COLUMN deep_work_sessions INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN longest_focus_streak_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  ADD CONSTRAINT focus_productivity_range CHECK (productivity_pct IS NULL OR productivity_pct BETWEEN 0 AND 100),
  ADD CONSTRAINT focus_constancy_range CHECK (constancy_pct IS NULL OR constancy_pct BETWEEN 0 AND 100);
ALTER TABLE episode_daily ADD COLUMN first_activity DATETIME(6) NULL, ADD COLUMN last_activity DATETIME(6) NULL;
