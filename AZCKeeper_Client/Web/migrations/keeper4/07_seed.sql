-- Keeper 4 — datos minimos de arranque.

-- 1. Catalogo de modulos. Uno por cada modulo que el producto ofrece.
--    is_sensitive=1 -> su consulta se audita (screenshots, location).
INSERT INTO keeper_module (code, label, category, is_sensitive, sort_order) VALUES
  ('activityTracking',  'Seguimiento de actividad',   'tracking', 0, 10),
  ('windowTracking',    'Seguimiento de ventanas',    'tracking', 0, 20),
  ('callTracking',      'Seguimiento de llamadas',    'tracking', 0, 30),
  ('processView',       'Vista de procesos',          'tracking', 0, 40),
  ('webBlocking',       'Bloqueo de sitios web',      'control',  0, 50),
  ('deviceLock',        'Bloqueo de equipo',          'control',  0, 60),
  ('security',          'Modulo de seguridad',        'security', 0, 70),
  ('networkDiagnostic', 'Diagnostico de red',         'control',  0, 80),
  ('remoteShutdown',    'Apagado remoto',             'control',  0, 90),
  ('screenshots',       'Capturas de pantalla',       'data',     1, 100),
  ('location',          'Ubicacion',                  'data',     1, 110);

-- 2. Tiers. Propuesta inicial (ajustable): basico -> pro -> enterprise.
INSERT INTO keeper_tier (code, label, sort_order) VALUES
  ('basico',     'Basico',     10),
  ('pro',        'Pro',        20),
  ('enterprise', 'Enterprise', 30);

-- 3. Que modulos incluye cada tier. Cada tier incluye lo del anterior mas lo suyo.
--    Los dos modulos sensibles quedan solo en enterprise: el gating comercial
--    coincide con el legal, nadie los tiene por defecto.
INSERT INTO keeper_tier_module (tier_id, module_code)
SELECT t.id, m.code
FROM keeper_tier t
JOIN keeper_module m ON (
     (t.code = 'basico'     AND m.code IN ('activityTracking','windowTracking','callTracking','processView'))
  OR (t.code = 'pro'        AND m.code IN ('activityTracking','windowTracking','callTracking','processView',
                                          'webBlocking','deviceLock','security','networkDiagnostic','remoteShutdown'))
  OR (t.code = 'enterprise' AND m.is_active = 1)
);

-- 4. Politica global. Sin una fila activa con scope='global' el handshake responde
--    500 y el equipo deja de reportar, no solo de recibir politicas.
INSERT INTO keeper_policy_assignments (scope, version, is_active, policy_json) VALUES
('global', 1, 1, JSON_OBJECT(
  'modules', JSON_OBJECT(
    'enableActivityTracking', TRUE,
    'enableWindowTracking',   TRUE,
    'enableCallTracking',     TRUE,
    'enableBlocking',         FALSE,
    'enableUpdateManager',    TRUE,
    'enableDebugWindow',      FALSE
  ),
  'blocking',    JSON_OBJECT('enableDeviceLock', FALSE),
  'webBlocking', JSON_OBJECT('enabled', FALSE, 'syncIntervalSeconds', 300, 'domains', JSON_ARRAY())
));

-- 5. Horario laboral global.
INSERT INTO keeper_work_schedules (user_id, is_active) VALUES (NULL, 1);

-- 6. Roles del panel. El catalogo de modulos vive en UN solo sitio (el codigo);
--    esta tabla guarda solo los permisos por rol.
INSERT INTO keeper_panel_roles (role_code, label, permissions_json, is_system) VALUES
('superadmin', 'Superadministrador', JSON_OBJECT('all', TRUE), 1),
('admin',      'Administrador',      JSON_OBJECT(), 1),
('viewer',     'Consulta',           JSON_OBJECT(), 1);
