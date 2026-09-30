-- Cuentas del panel y permisos legibles (2026-09-30).
-- Cuentas: contraseña temporal que la persona debe cambiar en su primer ingreso; nombre visible para las cuentas de
-- plataforma (no tienen persona); ultimo ingreso para ver quien usa el panel.
-- Permisos: frases en español para quien no es tecnico. Solo se reemplaza la etiqueta si sigue siendo el codigo, para no
-- pisar una etiqueta que un administrador haya editado.

UPDATE permissions SET label = CASE code
  WHEN 'actividad.ver' THEN 'Ver la actividad de las personas (tiempo activo, apps usadas)'
  WHEN 'actividad.titulos_ver' THEN 'Ver los títulos de las ventanas abiertas'
  WHEN 'auditoria.ver' THEN 'Ver el registro de auditoría (quién hizo qué)'
  WHEN 'cobertura.ver' THEN 'Ver qué personas tienen Keeper instalado'
  WHEN 'cobertura.editar' THEN 'Marcar personas exentas o con notas de instalación'
  WHEN 'cumplimiento.ver' THEN 'Ver alertas de doble empleo y apps sospechosas'
  WHEN 'cumplimiento.editar' THEN 'Revisar alertas y editar el catálogo de apps sospechosas'
  WHEN 'empresas.ver' THEN 'Ver los datos de la empresa'
  WHEN 'empresas.editar' THEN 'Editar los datos y la marca de la empresa'
  WHEN 'empresas.gestionar' THEN 'Crear y administrar empresas (plataforma)'
  WHEN 'empresas.rbac_habilitar' THEN 'Permitir que una empresa gestione sus propios roles (plataforma)'
  WHEN 'equipos.ver' THEN 'Ver los equipos y su estado'
  WHEN 'equipos.editar' THEN 'Editar equipos y cambiarles el nombre'
  WHEN 'equipos.asignar' THEN 'Asignar o reasignar un equipo a una persona'
  WHEN 'equipos.enrolar' THEN 'Dar de alta equipos nuevos'
  WHEN 'equipos.transferir' THEN 'Transferir un equipo a otra empresa (plataforma)'
  WHEN 'equipos.bloquear' THEN 'Bloquear la pantalla de un equipo'
  WHEN 'equipos.desbloquear' THEN 'Desbloquear un equipo'
  WHEN 'equipos.reiniciar' THEN 'Reiniciar un equipo a distancia'
  WHEN 'equipos.apagar' THEN 'Apagar un equipo a distancia'
  WHEN 'equipos.borrar' THEN 'Borrar los datos de un equipo (borrado remoto)'
  WHEN 'festivos.ver' THEN 'Ver los festivos'
  WHEN 'festivos.editar' THEN 'Crear y editar festivos'
  WHEN 'hardening.gestionar' THEN 'Endurecer o revertir las cuentas de Windows de un equipo'
  WHEN 'horarios.ver' THEN 'Ver los horarios de trabajo'
  WHEN 'horarios.editar' THEN 'Crear y editar horarios de trabajo'
  WHEN 'integraciones.gestionar' THEN 'Conectar otros sistemas (claves de API y webhooks)'
  WHEN 'migracion.ver' THEN 'Ver el avance de la migración desde K3'
  WHEN 'migracion.gestionar' THEN 'Autorizar la migración de equipos desde K3'
  WHEN 'migracion.recuperar' THEN 'Recuperar un equipo con la migración interrumpida'
  WHEN 'notificaciones.ver' THEN 'Ver las notificaciones'
  WHEN 'notificaciones.gestionar' THEN 'Configurar las notificaciones'
  WHEN 'operacion.ajustes_ver' THEN 'Ver los ajustes del panel'
  WHEN 'operacion.ajustes_editar' THEN 'Cambiar los ajustes del panel'
  WHEN 'operacion.logs' THEN 'Ver los registros técnicos de los equipos'
  WHEN 'operacion.salud' THEN 'Ver la salud del servidor'
  WHEN 'organizacion.ver' THEN 'Ver sedes, áreas y cargos'
  WHEN 'organizacion.editar' THEN 'Crear y editar sedes, áreas y cargos'
  WHEN 'permisos.gestionar' THEN 'Editar el catálogo de permisos (plataforma)'
  WHEN 'presencia.ver' THEN 'Ver la hora de llegada y la puntualidad'
  WHEN 'presencia.ubicacion_ver' THEN 'Ver la ubicación de los registros de asistencia'
  WHEN 'reglas.ver' THEN 'Ver las reglas (bloqueos de web, USB e instalaciones)'
  WHEN 'reglas.editar' THEN 'Crear y editar reglas'
  WHEN 'reglas.aplicar' THEN 'Forzar que un equipo aplique sus reglas ahora'
  WHEN 'releases.ver' THEN 'Ver las versiones del agente'
  WHEN 'releases.desplegar' THEN 'Desplegar una versión del agente a los equipos'
  WHEN 'releases.publicar' THEN 'Publicar versiones nuevas del agente (plataforma)'
  WHEN 'reportes.ver' THEN 'Ver los reportes de productividad'
  WHEN 'roles.ver' THEN 'Ver los roles y sus permisos'
  WHEN 'roles.gestionar' THEN 'Crear roles y dar permisos a otras cuentas'
  WHEN 'tiers.ver' THEN 'Ver los planes (tiers)'
  WHEN 'tiers.gestionar' THEN 'Crear y editar planes, y asignarlos a personas'
  WHEN 'usuarios.ver' THEN 'Ver las personas de la empresa'
  WHEN 'usuarios.crear' THEN 'Crear personas'
  WHEN 'usuarios.editar' THEN 'Editar personas y sus cuentas de acceso al panel'
  ELSE label END,
  version = version + 1
WHERE label = code;

-- Al final: los ALTER no son idempotentes; si algo anterior falla, reintentar el archivo es seguro.
ALTER TABLE admin_accounts
  ADD COLUMN display_name VARCHAR(160) NULL AFTER email,
  ADD COLUMN must_change_password BOOLEAN NOT NULL DEFAULT FALSE AFTER password_hash,
  ADD COLUMN last_login_at DATETIME(6) NULL AFTER created_at;
