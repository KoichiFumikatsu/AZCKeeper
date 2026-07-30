-- Keeper 4 — vaciado del entorno DEV antes de crear el esquema nuevo.
--
-- SOLO PARA DEV (pipezafra_keepdev). NUNCA ejecutar contra pipezafra_keep.
-- Keeper 4 arranca con datos desde cero; Keeper 3 queda intacto en su propia base
-- como historial de solo lectura.
--
-- Las 32 tablas listadas son el inventario real de pipezafra_keepdev al 2026-07-30,
-- obtenido de information_schema, no una lista escrita de memoria.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS
  keeper_activity_day,
  keeper_admin_accounts,
  keeper_admin_sessions,
  keeper_app_classifications,
  keeper_areas,
  keeper_audit_log,
  keeper_cargos,
  keeper_client_log,
  keeper_client_releases,
  keeper_daily_metrics,
  keeper_data_sources,
  keeper_device_locks,
  keeper_devices,
  keeper_dual_job_alerts,
  keeper_events,
  keeper_firmas,
  keeper_focus_daily,
  keeper_handshake_log,
  keeper_install_coverage_notes,
  keeper_module_catalog,
  keeper_panel_roles,
  keeper_panel_settings,
  keeper_policy_assignments,
  keeper_security_state,
  keeper_sedes,
  keeper_sessions,
  keeper_sociedades,
  keeper_suspicious_apps,
  keeper_user_assignments,
  keeper_users,
  keeper_window_episode,
  keeper_work_schedules;

SET FOREIGN_KEY_CHECKS = 1;
