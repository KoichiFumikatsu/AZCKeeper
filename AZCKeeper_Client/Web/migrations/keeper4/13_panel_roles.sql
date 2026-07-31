-- Keeper 4 — RBAC del panel: los permisos pasan a vivir en la tabla.
--
-- keeper_panel_roles se sembro en 07_seed con superadmin/admin/viewer y permissions_json
-- VACIO ({}), asi que la tabla existia pero no gobernaba nada: panelCan() resolvia los
-- permisos en un mapa en codigo (y ni siquiera conocia los roles reales 'it' y 'gerente'
-- que se crearon en la rebanada 2). Aqui se siembran los roles REALES con su catalogo de
-- modulos para que roles.php pueda editarlos sin tocar codigo ni redesplegar.
--
-- Forma de permissions_json:
--   {"all": true}                          -> todo, incluidos los modulos que se agreguen despues
--   {"modules": ["process-view","coverage"]} -> lista explicita
-- 'all' se reserva a superadmin: es el rol que nunca puede quedarse fuera de un modulo nuevo.
--
-- is_system: SOLO superadmin. En el seed original 'admin' y 'viewer' tambien lo eran, lo que
-- habria dejado la matriz de roles.php inmutable y la rebanada sin sentido. El candado que
-- importa es que nadie pueda recortar a superadmin (y quedarse sin quien administre).

INSERT INTO keeper_panel_roles (role_code, label, permissions_json, is_system) VALUES
  ('superadmin', 'Superadministrador', JSON_OBJECT('all', TRUE), 1),

  -- TI / Soporte: opera la flota. Ve todo salvo la administracion de roles y cuentas.
  ('it', 'TI / Soporte', JSON_OBJECT('modules', JSON_ARRAY(
      'dashboard','process-view','users','pending','devices',
      'tiers','policies','coverage','dual-job','audit','releases')), 0),

  -- 'admin' venia del seed inicial. Se conserva (puede haber cuentas con ese rol) con el
  -- mismo alcance que TI, en vez de dejarlo con {} y que caiga al respaldo en codigo.
  ('admin', 'Administrador', JSON_OBJECT('modules', JSON_ARRAY(
      'dashboard','process-view','users','pending','devices',
      'tiers','policies','coverage','dual-job','audit','releases')), 0),

  -- Gerente: productividad de SU firma. NO ve flota/seguridad (decision de Koichi, rebanada 2).
  ('gerente', 'Gerente', JSON_OBJECT('modules', JSON_ARRAY(
      'process-view','coverage','dual-job','users')), 0),

  ('viewer', 'Consulta', JSON_OBJECT('modules', JSON_ARRAY(
      'process-view','coverage')), 0)
ON DUPLICATE KEY UPDATE
  label            = VALUES(label),
  permissions_json = VALUES(permissions_json),
  is_system        = VALUES(is_system);
