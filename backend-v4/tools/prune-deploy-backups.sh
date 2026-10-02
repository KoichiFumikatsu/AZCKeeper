#!/bin/bash
# Retencion de los respaldos de despliegue (Koichi 2026-10-02): una empresa eliminada no debe seguir viva en copias
# viejas de la base. Borra lo que tenga mas de N dias (por defecto 14) en /home/keeper/deploy-backups.
# El respaldo diario de Hestia se reemplaza cada dia y no necesita esto. Cron diario del usuario keeper (Hestia).
set -euo pipefail
DAYS="${1:-14}"
[[ "$DAYS" =~ ^[0-9]+$ ]] && [ "$DAYS" -ge 3 ] || { echo "dias invalidos (minimo 3): $DAYS" >&2; exit 2; }
DIR=/home/keeper/deploy-backups
[ -d "$DIR" ] || exit 0
COUNT=$(find "$DIR" -mindepth 1 -maxdepth 1 -mtime +"$DAYS" | wc -l)
find "$DIR" -mindepth 1 -maxdepth 1 -mtime +"$DAYS" -exec rm -rf -- {} +
echo "$(date -u +%FT%TZ) borrados $COUNT respaldos de mas de $DAYS dias"
