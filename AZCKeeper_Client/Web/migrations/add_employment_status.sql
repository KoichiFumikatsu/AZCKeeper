-- #2 Estado de empleo (empleado/retirado) en keeper_users.
-- Independiente de `status` (acceso Keeper: active/inactive/locked).
-- Se siembra desde employee.role del legacy ('retirado') y es editable desde el panel.
ALTER TABLE keeper_users
  ADD COLUMN employment_status ENUM('active','retired') NOT NULL DEFAULT 'active' AFTER `status`,
  ADD KEY ix_keeper_users_employment (employment_status);
