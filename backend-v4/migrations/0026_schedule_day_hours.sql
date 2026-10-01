-- Horarios distintos por dia (sabados, medio tiempo). Una fila de schedule_days con start/end propios manda
-- sobre la franja del horario ese dia, incluido su almuerzo (NULL = ese dia no tiene almuerzo). Sin franja
-- propia, el dia usa start/end y almuerzo del horario.
ALTER TABLE schedule_days
  ADD COLUMN start_local TIME NULL,
  ADD COLUMN end_local TIME NULL,
  ADD COLUMN lunch_start_local TIME NULL,
  ADD COLUMN lunch_end_local TIME NULL,
  ADD CONSTRAINT schedule_days_hours_pair CHECK ((start_local IS NULL) = (end_local IS NULL)),
  ADD CONSTRAINT schedule_days_lunch_pair CHECK ((lunch_start_local IS NULL) = (lunch_end_local IS NULL)),
  ADD CONSTRAINT schedule_days_lunch_order CHECK (lunch_start_local IS NULL OR lunch_start_local < lunch_end_local),
  ADD CONSTRAINT schedule_days_lunch_needs_hours CHECK (lunch_start_local IS NULL OR start_local IS NOT NULL);
