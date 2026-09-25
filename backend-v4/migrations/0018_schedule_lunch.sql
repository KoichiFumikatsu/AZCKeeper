-- El horario de almuerzo nunca llego a v4 en ninguna capa: ni tabla, ni contrato, ni
-- tipo del cliente. Por eso la rama que clasifica la franja de almuerzo en el cliente es
-- inalcanzable y TODO el almuerzo se contabiliza como jornada laboral.
--
-- K3 lo tiene en keeper_work_schedules (lunch_start_time / lunch_end_time) y su cliente lo
-- recibia en cada handshake; el valor por defecto alli era 12:00-13:00.
--
-- Ambos NULL significa "este horario no define almuerzo", que es distinto de un almuerzo
-- de duracion cero: en ese caso el cliente no separa la franja en vez de inventarla.

ALTER TABLE schedules
  ADD COLUMN lunch_start_local TIME NULL COMMENT 'K3: lunch_start_time',
  ADD COLUMN lunch_end_local TIME NULL COMMENT 'K3: lunch_end_time',
  ADD CONSTRAINT schedules_lunch_pair CHECK (
    (lunch_start_local IS NULL) = (lunch_end_local IS NULL)),
  ADD CONSTRAINT schedules_lunch_order CHECK (
    lunch_start_local IS NULL OR lunch_start_local < lunch_end_local);
