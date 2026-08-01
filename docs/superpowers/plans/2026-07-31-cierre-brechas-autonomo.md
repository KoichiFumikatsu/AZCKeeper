# Cierre de brechas K3→K4 — ejecución autónoma (Koichi fuera, 2026-07-31)

Koichi pidió arrancar autónomo el cierre de brechas (`2026-07-31-brechas-k3-vs-k4.md`). Estará fuera,
así que: decisiones con criterio + documentadas; construir solo lo **seguro y verificable en DEV**;
NO ejecutar enforcement elevado en esta máquina (rompería mi navegador/HKLM); dejar marcado lo que
necesita su paso (test elevado) o su decisión (transporte de la política al agente).

## Decisión ABIERTA (para Koichi, no la resuelvo solo)
**Transporte de la política de bloqueo web al agente SYSTEM sin manipulación del usuario.**
Opciones: (a) `%ProgramData%\AZCKeeper\policy.json` con ACL SYSTEM+Admins (el cliente per-user NO
podría escribirla → habría que repensar quién la escribe), o (b) el agente jala la política DIRECTO
del servidor (rompe "el agente no toca la red" pero elimina el hueco de manipulación). Hasta decidir,
NO se construye el tramo cliente→agente ni el enforcement.

## Orden autónomo (todo verificable en DEV, sin riesgo de sistema)

### A. Bloqueo web — LADO SERVIDOR (progreso en la brecha #1, decision-free)
- A1. `policies.php`: sección "Bloqueo web" en la política global — enabled, dominios (textarea),
  bloquear descargas, bloquear extensiones. Escribe `policy_json.webBlocking` en el assignment global.
- A2. Handshake: normaliza y lleva `effectiveConfig.webBlocking` (InputValidator::validateDomainArray),
  solo si el tier incluye `webBlocking`. Verificar por curl.
- (El tramo cliente→agente + enforcement queda tras la decisión de transporte.)

### B. Logging cliente→panel (brecha #6) — full, testeable
- B1. Backend: `POST /client/logs` (ClientLogBatch) + `ClientLogRepo` + tabla `keeper_client_log`
  (mig 20) con retención en el cron. Redacta secretos.
- B2. Cliente: LocalLogger drena Warn/Error a /client/logs en cada handshake (cola separada, acotada).
- B3. Panel: página de historial de log del cliente (o pestaña en audit.php).

### C. Tracking de llamadas (brecha #4) — full, testeable
- C1. Cliente: módulo `callTracking` (o extender WindowModule) que acumula segundos en llamada
  (IsInCallNow + CallSeconds) por keywords de proceso/título; alimenta activity-day.CallSeconds.
- C2. Backend: activity-day ya acepta CallSeconds; verificar que se guarda != 0.
- Tests + --once.

### D. WorkSchedule + categorización + resume del día (brecha #5)
- D1. Handshake: devolver `workSchedule` (horario laboral/almuerzo) desde la política.
- D2. Cliente: categorizar activo/idle en trabajo/almuerzo/fuera; ActivityModule.
- D3. `GET /client/activity-day` + cliente retoma el día al arrancar (no perder datos al reiniciar).

### E. Resiliencia (brechas #9/#10/#11)
- E1. `POST /client/re-enroll` (recupera sesión por device_guid) + cliente lo intenta antes de fallar.
- E2. `POST /client/force-handshake` (admin sube versión de política global) + botón en el panel.
- E3. TimeSync: handshake ya manda serverTimeUtc; cliente ajusta un offset (sin tocar el reloj del SO).

## Marcado para el PASO DE KOICHI (no autónomo)
- Enforcement web real: agente como servicio SYSTEM aplicando URLBlocklist en HKLM en un equipo de prueba.
- Decisión de transporte (arriba).
- Anillo 2 (USB/UAC/SRP/CMD), GPS.

Se commitea por tarea; se documenta el avance en el ESTADO CONSOLIDADO al cerrar cada bloque.
