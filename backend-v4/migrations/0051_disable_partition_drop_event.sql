-- La retención la aplica config/retention-cron.php por cliente y con interruptor (0050). El evento
-- maintain_episode_partitions (0005) borraba particiones de `episodes` según el mayor raw_days de todos los
-- clientes, sin mirar el interruptor. En prod el programador de eventos está apagado y nunca corrió; se
-- desactiva para que encenderlo no borre datos. Las particiones existen hasta 2028 y p_future (MAXVALUE)
-- recibe lo posterior. Repetible.
-- 0005 lo crea siempre (CREATE EVENT IF NOT EXISTS); ALTER EVENT no se admite en PREPARE.
ALTER EVENT maintain_episode_partitions DISABLE;
