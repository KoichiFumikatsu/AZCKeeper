-- Agente 4.0.22: solo el teclado/mouse FISICO (Raw Input con dispositivo) cuenta como uso. GetLastInputInfo
-- tambien se refresca con entrada inyectada (jigglers, DWService), y en produccion (2026-10-09) daba 15-22 h
-- seguidas "en uso" de noche con la pantalla de bloqueo delante.
--
-- simulated_seconds: tiempo con entrada pero sin fisica; ya va dentro de idle_seconds.
-- remote_seconds: tiempo activo en sesion RDP (la entrada remota no trae dispositivo); va dentro de active_seconds.
-- NULL = DESCONOCIDO (agente anterior o Raw Input no disponible), nunca cero.

ALTER TABLE activity_snapshots
  ADD COLUMN simulated_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN remote_seconds BIGINT UNSIGNED NULL;

ALTER TABLE day_summary
  ADD COLUMN simulated_seconds BIGINT UNSIGNED NULL,
  ADD COLUMN remote_seconds BIGINT UNSIGNED NULL;
