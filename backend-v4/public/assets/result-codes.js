// Texto llano de los códigos técnicos que devuelven el agente y la API (result_code de las órdenes, error_code de los
// controles y del registro, estado del endurecimiento). Lo usan la ficha del equipo (app.js), Equipos (fleet.js),
// Resumen de protección (protection.js), Logs de los equipos (k3.js) y las acciones remotas (device-actions.js).
// El código original queda en el atributo title para soporte. Hermano de log-components.js (nombres de las partes).
export const RESULT_CODES = {
  // Órdenes remotas (client-v4 CommandExecutor).
  completed: 'Completado', pending_restart: 'Se aplica al reiniciar', expired: 'Venció antes de ejecutarse', cancelled: 'Se canceló (el equipo se dio de baja o se reasignó)',
  interrupted: 'Se interrumpió (el equipo se apagó o reinició)', unsupported_command: 'El agente no ejecuta esta orden',
  device_actions_dry_run: 'El agente está en modo de prueba: no ejecuta acciones', invalid_command_destination: 'Rechazada por el agente (destino no válido)',
  command_inbox_full: 'El agente tiene demasiadas órdenes pendientes', invalid_computer_name: 'Nombre no válido', lock_store_unavailable: 'El agente no pudo guardar el bloqueo',
  notice_deadline_missed: 'No se ejecutó: el equipo estaba apagado o suspendido cuando terminó el aviso', invalid_notice: 'Tiempo de aviso no válido',
  cancelled_by_admin: 'IT canceló la orden antes de que se ejecutara',
  // Excepciones de Windows que el agente informa por su nombre.
  Win32Exception: 'Windows rechazó el cambio', UnauthorizedAccessException: 'Windows negó el permiso', IOException: 'Error de lectura o escritura en el equipo',
  InvalidOperationException: 'El agente no pudo completar la acción', TimeoutException: 'Se agotó el tiempo de espera', NotSupportedException: 'No se puede en este equipo',
  // Endurecimiento de cuentas (bootstrapper Modo B y estado guardado por el servidor).
  none: 'Sin endurecer', hardened: 'Endurecido', unhardened: 'Revertido', running: 'En curso', failed: 'Falló',
  waiting_panel: 'Esperando la orden desde el panel', panel_wait: 'Esperando la orden desde el panel', recovery_required: 'Requiere revertir',
  rollback_failed: 'La reversión no terminó', rollback_incomplete: 'La reversión quedó incompleta', elevation_required: 'Keeper no tuvo permisos de administrador',
  invalid_admin_name: 'Nombre de la cuenta de IT no válido', invalid_hardening_state: 'El estado guardado del endurecimiento no es válido',
  invalid_hardening_config: 'La configuración de endurecimiento no es válida', invalid_hardening_response: 'Respuesta no válida del programa de endurecimiento',
  hardening_runner_missing: 'Falta el programa de endurecimiento en el equipo', pending_logoff: 'Falta cerrar la sesión para aplicarlo',
  no_operational_admin: 'No quedaría ninguna cuenta administradora utilizable', no_target_accounts: 'No hay cuentas que endurecer',
  protected_account: 'Cuenta protegida: no se modifica', managed_admin_name_conflict: 'Ya existe otra cuenta con el nombre de la cuenta de IT',
  unowned_admin_name_collision: 'Ya existe otra cuenta con el nombre de la cuenta de IT', builtin_admin_changed: 'La cuenta Administrador de Windows cambió',
  demotion_not_applied: 'No se pudo quitar el permiso de administrador', promotion_not_applied: 'No se pudo devolver el permiso de administrador',
  network_deny_not_applied: 'No se pudo bloquear el inicio de sesión por red', binary_missing: 'Falta un archivo de Keeper', binary_modified: 'Un archivo de Keeper fue modificado',
  // Controles del agente (bloqueos, Smart App Control, actualizaciones).
  dry_run: 'Modo de prueba: no aplica', unsupported_rule: 'Regla no soportada en este equipo', invalid_configuration: 'Configuración no válida',
  invalid_configuration_cleanup_dry_run: 'Configuración no válida (modo de prueba)', unsupported_cleanup_dry_run: 'Regla no soportada (modo de prueba)',
  audit_not_blocking: 'Solo registra, no bloquea (modo auditoría)', driver_blocked: 'Windows bloqueó el controlador', appidsvc_missing: 'Falta un servicio de Windows (Identidad de aplicación)',
  applocker_apply_failed: 'No se pudo aplicar AppLocker', applocker_process_timeout: 'AppLocker tardó demasiado', applocker_process_start_failed: 'No arrancó AppLocker',
  conflicting_rules: 'Hay reglas en conflicto', smart_app_control_disable_failed: 'No se pudo apagar Smart App Control', smart_app_control_key_missing: 'Windows no informa Smart App Control',
  smart_app_control_disabled: 'Smart App Control apagado', degraded: 'Con fallas', failed_launch: 'No arrancó', apply_stalled: 'Se quedó sin aplicar',
  awaiting_package: 'Esperando el paquete', verified_pending_install: 'Verificado, pendiente de instalar', no_interactive_session: 'Sin sesión abierta',
};
// Códigos con un número al final: paso del endurecimiento, sesiones cerradas, salida del programa, error de Windows.
const PATTERNS = [
  [/^failed_step_(\d+)(?:_(\w+))?$/, m => `Falló en el paso ${m[1]}`],
  [/^(\w+?)_step_(\d+)$/, m => RESULT_CODES[m[1]] ? `${RESULT_CODES[m[1]]} (paso ${m[2]})` : null],
  [/^hardened_logoff_(\d+)$/, m => `Endurecido; se cerró la sesión de ${m[1]} ${m[1] === '1' ? 'cuenta' : 'cuentas'}`],
  [/^exit_(\d+)$/, m => `El programa de endurecimiento terminó con error (código ${m[1]})`],
  [/^win32_(\d+)$/, m => `Error de Windows ${m[1]}`],
];
/** Texto llano del código, o null si no se conoce (quien llama muestra entonces el código tal cual). */
export function codeText(code) {
  if (code === null || code === undefined || code === '') return null;
  const key = String(code);
  if (Object.hasOwn(RESULT_CODES, key)) return RESULT_CODES[key];
  for (const [re, text] of PATTERNS) { const m = key.match(re); if (m) { const t = text(m); if (t) return t; } }
  return null;
}
export const plainCode = code => codeText(code) ?? code;
/** Elemento con el texto llano y el código en title (solo si se tradujo). */
export function codeNode(code, tag = 'span', fallback = '—') {
  const n = document.createElement(tag);
  const text = codeText(code);
  n.textContent = text ?? (code || fallback);
  if (text) n.title = code;
  return n;
}
// Los motivos que arma la API llevan el código entre paréntesis: «Endurecimiento pendiente (waiting_panel)».
const IN_TEXT = /\(([A-Za-z][A-Za-z0-9_]*)\)/g;
export const plainReason = text => String(text ?? '').replace(IN_TEXT, (all, code) => { const t = codeText(code); return t ? `(${t})` : all; });
export const reasonCodes = text => [...String(text ?? '').matchAll(IN_TEXT)].map(m => m[1]).filter(c => codeText(c) !== null);
