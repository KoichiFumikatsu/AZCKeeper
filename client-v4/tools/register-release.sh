#!/usr/bin/env bash
# Registra una release firmada en devkeep y la despliega al tenant indicado.
# Uso (Git Bash, desde client-v4):  bash tools/register-release.sh artifacts/release-4.0.1.json
# Pide email y contrasena del admin de plataforma por consola; la contrasena no se muestra ni queda en disco.
set -euo pipefail
RELEASE_JSON=${1:?uso: register-release.sh <release.json> [tenant_id]}
TENANT=${2:-00000000-0000-4000-8000-000000000002}      # Grupo AZC
PLATFORM=00000000-0000-4000-8000-000000000001
BASE=${KEEPER_BASE:-https://devkeep.azclegal.com}
API=$BASE/v1
ORIGIN="Origin: $BASE"
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT

json() { php -r '$j=json_decode(stream_get_contents(STDIN)); $p=explode(".",$argv[1]); foreach($p as $k){ $j=$j->$k ?? null; } echo is_scalar($j)?$j:"";' "$1"; }
uuid() { php -r '$b=random_bytes(16); $b[6]=chr(ord($b[6])&0x0f|0x40); $b[8]=chr(ord($b[8])&0x3f|0x80); echo vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($b),4));'; }

read -rp "Email admin plataforma [admin@devkeep.azclegal.com]: " EMAIL; EMAIL=${EMAIL:-admin@devkeep.azclegal.com}
read -rsp "Contrasena: " PASSWORD; echo

CSRF=$(curl -sf -c "$JAR" -b "$JAR" "$API/auth/csrf" | json csrf_token)
BODY=$(EMAIL="$EMAIL" PASSWORD="$PASSWORD" php -r 'echo json_encode(["email"=>getenv("EMAIL"),"password"=>getenv("PASSWORD")]);')
unset PASSWORD
LOGIN=$(curl -s -c "$JAR" -b "$JAR" -X POST "$API/auth/login" -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "$ORIGIN" --data-binary @- <<<"$BODY")
unset BODY
CSRF=$(printf '%s' "$LOGIN" | json csrf_token)
[ -n "$CSRF" ] || { echo "login fallido: $LOGIN"; exit 1; }
echo "login OK"

RELEASE_ID=$(json id < "$RELEASE_JSON")
echo "== registrar release $RELEASE_ID (tenant plataforma)"
curl -s -w "\nHTTP %{http_code}\n" -c "$JAR" -b "$JAR" -X POST "$API/releases" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "$ORIGIN" \
  -H "X-Tenant-ID: $PLATFORM" -H "Idempotency-Key: $(uuid)" --data-binary @"$RELEASE_JSON" | tail -c 400

DEPLOY="{\"release_id\":\"$RELEASE_ID\",\"ring\":\"stable\",\"percentage\":100,\"enabled\":true}"
for T in "$PLATFORM" "$TENANT"; do
  echo "== desplegar al tenant $T"
  curl -s -w "\nHTTP %{http_code}\n" -c "$JAR" -b "$JAR" -X POST "$API/release-deployments" \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -H "$ORIGIN" \
    -H "X-Tenant-ID: $T" -H "Idempotency-Key: $(uuid)" -d "$DEPLOY" | tail -c 400
done
curl -s -o /dev/null -c "$JAR" -b "$JAR" -X POST "$API/auth/logout" -H "X-CSRF-Token: $CSRF" -H "$ORIGIN" || true
echo "listo. Esperado: 201 en el registro y en los despliegues."
