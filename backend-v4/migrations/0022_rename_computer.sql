-- Comando remoto para cambiar el nombre de Windows del equipo (p. ej. a su placa de activo, ACT_0015 -> ACT-0015).
-- parameters: parametros del comando (hoy solo rename_computer: computer_name, restart_now).
ALTER TABLE device_command MODIFY COLUMN type
  ENUM('lock','unlock','restart','shutdown','wipe','refresh_policy','harden','unharden','rename_computer') NOT NULL;

ALTER TABLE device_command ADD COLUMN parameters JSON NULL AFTER reason;
