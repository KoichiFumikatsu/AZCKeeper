# Keeper 4 — Lo que HAY y lo que HACE FALTA (2026-08-04)

Resumen ejecutivo escaneable. Detalle en `2026-07-31-ESTADO-CONSOLIDADO-K4.md` y
`2026-07-31-brechas-k3-vs-k4.md`. Rama `feature/modulo-seguridad` (pusheada). DEV = devkeep.azclegal.com.

---

## ✅ LO QUE HAY (hecho y verificado en DEV)

### Backend + Panel (PHP, WebK4/)
- **API cliente completa:** login (cédula+password), handshake (política global→user→device, recorte por
  tier, diagnóstico, idle, specs, workSchedule, webBlocking), episodios, activity-day (con categorías),
  module-state, comandos + resultado, security/report, screenshots (metadata), location, **diagnostics**,
  **logs (cliente→panel)**, **re-enroll**. Admin: process-view, coverage, commands, enrollment. Cron
  (productividad + purga de diagnóstico y logs).
- **Panel (16 páginas):** dashboard de flota/agente, vista de procesos (+ tarjeta Equipo/specs),
  usuarios (+ presencia + reset password), pendientes, dispositivos, cobertura, doble empleo, tiers,
  **políticas (módulos + bloqueo web + horario laboral)**, auditoría (+ **log del cliente**), releases
  (+ force_update), **diagnóstico en vivo**, **control remoto (tab, por tier)**, roles+cuentas, no-access.
- **Transversal:** RBAC editable (BD), CSRF en todos los POST, `.htaccess` (dominio pelado → panel),
  scope por firma en todo.

### Cliente C# (AZCKeeper.K4/) — 90/90 tests
- Login de primer arranque (entorno+cédula+password, token DPAPI), re-login silencioso, **re-enroll por
  device_guid**. Residente invisible, mutex único, backoff, cola offline, auto-update, self-install.
- Módulos: actividad (**categorizada trabajo/almuerzo/fuera** con horario del servidor), ventanas,
  **llamadas (segundos reales)**, comandos (apagar/reiniciar/logoff/bloquear/diag-red), screenshots
  (metadata; blob stub). LocalLogger (+ **drain Warn/Error al panel**). Diagnóstico en vivo. Specs del
  equipo. **TimeSync** (corrige hora con la del servidor sin tocar el reloj del SO). Courier del agente.

### Releases
- v4.0.0.1..4 beta publicadas. **4.0.0.4 = build completo** (presencia+control+specs). Feed DEV:
  4.0.0.1 activa (instalar) → 4.0.0.4 target del auto-update.

### Brechas K3→K4 ya cerradas (además de lo nuevo)
Logging cliente→panel · tracking de llamadas real · horario+categorización · re-enroll · TimeSync ·
override de política por usuario · **fix P0: la política ahora sí llega al cliente** (antes 0 módulos).

---

## ❌ LO QUE HACE FALTA (pendiente)

### TIER 1 — bloquean la paridad / el valor principal
1. **Bloqueo web ENFORCEMENT (agente elevado).** El lado servidor (panel de dominios + handshake) YA
   está. Falta lo que lo hace real: **agente como servicio Windows SYSTEM + instalador (`sc create`)**,
   el **transporte** de la política al agente, aplicar `URLBlocklist` en HKLM, y **limpiar residuos
   legacy** de K3 en el cliente. → *necesita decisión + tu equipo de prueba (ver abajo).*
2. **Bloqueo de equipo con PIN.** K4 solo hace `LockWorkStation` por comando. Falta el bloqueo coercitivo
   (pantalla completa + hook de teclado + desbloqueo por PIN + endpoint unlock/estado). → *toca sistema.*
3. **Organización + import de usuarios.** No hay `organization.php`/`assignments.php` ni el mecanismo de
   import multi-tenant. Sin esto no hay alta real de estructura ni de usuarios. → *necesita definición.*

### TIER 2 — visibilidad / soporte
4. **Productividad / Focus Score (UI):** el cron calcula, pero no hay página que lo muestre (ranking,
   tendencias). *(Se puede hacer solo.)*
5. **Dashboard de KPIs de productividad:** el `index` es tablero de seguridad, no de productividad. *(Solo.)*

### TIER 3 — resiliencia / datos
6. **D3 — Reanudar el día al reiniciar:** `GET /client/activity-day` + el cliente retoma contadores al
   arrancar (hoy pierde datos si el equipo reinicia a media jornada). *(Se puede hacer solo.)*

### TIER 4 — secundarias
7. `sedes-dashboard`, `server-health`, políticas por dispositivo, episodio único, **object storage real de
   screenshots** (hoy stub → el screenshot on-demand del tab de control está desactivado por esto),
   verificar equivalencia de fórmulas de productividad K3 vs K4. *(Casi todo se puede hacer solo.)*

### Reservado (definición de negocio)
- **Sección legal** (Ley 1581, aviso/consentimiento) — en la gerencia jurídica.
- **GPS/location** y **Anillo 2** (USB/UAC/SRP/CMD del agente) — revisión conjunta.
- **Tiers reales** (qué módulos por tier, precios).

---

## 🙋 LO QUE NECESITO DE TI (bloquea lo grande)

1. **DECISIÓN — transporte del bloqueo web al agente SYSTEM** (sin que el usuario lo manipule):
   **(a)** cliente escribe `ProgramData\policy.json` con ACL, o **(b)** el agente jala la política directo
   del servidor. **Recomiendo (b).** → desbloquea el agente-como-servicio + el enforcement.
2. **TU EQUIPO DE PRUEBA (elevado):** instalar el agente SYSTEM y confirmar el bloqueo real en el
   navegador (HKLM), y probar el bloqueo con PIN. **No lo puedo verificar en mi máquina** (rompería mi
   navegador / HKLM).
3. **REVISIÓN 3 EQUIPOS (ya listo):** instalar 4.0.0.1 → panel Activar+Forzar 4.0.0.4 → ver presencia /
   control / specs en vivo.
4. **DEFINICIÓN — import de usuarios/organización:** ¿cómo entran usuarios y estructura a K4? (BD del
   cliente multi-tenant / carga manual / cuál fuente). Sin esto no diseño bien la pieza #3.

## ▶️ LO QUE PUEDO SEGUIR SOLO (sin bloquearte)
D3 (resume del día) · productivity.php (Focus UI) · dashboard KPIs · sedes-dashboard · server-health ·
preparar el agente-como-servicio hasta donde solo falte tu decisión de transporte.
