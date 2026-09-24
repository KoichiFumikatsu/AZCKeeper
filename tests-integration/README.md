# Integración C# ↔ PHP (AZCKeeper v4)

Desde la raíz, en Windows:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests-integration/run.ps1
```

Requiere .NET SDK 8 o posterior con runtime .NET 8, PHP 8 con `pdo_mysql` y `openssl`, y MySQL 8.0.30+. Usa por defecto MySQL de `C:\laragon\bin\mysql\mysql-8.4.3-winx64`; PHP y dotnet se resuelven desde PATH. Se pueden especificar `-MySqlDirectory` y `-PhpPath`.

El proyecto de consola `client-v4/tests/Keeper.Agent.IntegrationHarness` referencia directamente `Keeper.Agent`. No está en `Keeper.sln` y no participa en el `dotnet test` habitual. No copia ni modifica `SyncClient`, `HttpMessageSigner`, el serializador o el verificador PHP.

## Flujo real

1. Compila en un directorio temporal, con `-maxcpucount:1 -nodeReuse:false`, `MSBUILDDISABLENODEREUSE=1` y compilación compartida desactivada. El límite de heap GC por proceso es 512 MiB; no equivale a un límite de memoria total del proceso.
2. C# genera una clave de dispositivo P-256. La clave privada se carga después desde PKCS#8; nunca se imprime.
3. Inicializa una instancia MySQL propia, ligada a loopback, sin binlog/MySQL X/Performance Schema, con buffer InnoDB de 64 MiB y máximo 12 conexiones. Comprueba `@@datadir` antes de crear la BD o apagar la instancia.
4. Ejecuta **todas** las migraciones mediante `backend-v4/migrations/run.php`, incluidos los seeds. Verifica los hashes de cada migración y los catálogos sembrados.
5. Crea tenant, organización, usuario, asignaciones, dispositivo, principals, enrollment consumido y estado de sync. Registra las coordenadas públicas en `device_keys` y compara el thumbprint calculado por PHP con `HttpMessageSigner.KeyId` de C#. `device_keys.id` es el UUID interno; el `keyid` de la firma es el thumbprint SHA-256 del JWK en base64url.
6. Invoca `backend-v4/config/compile.php` y exporta la política persistida como resultado esperado. El hash del documento JSON de MySQL se verifica con la canonicalización del compilador.
7. Arranca `php -S 127.0.0.1:<puerto> -t backend-v4/public backend-v4/public/index.php`. El agente firma el origen lógico `https://127.0.0.1:<puerto>`. Un `DelegatingHandler` exclusivo del arnés representa el salto interno de un reverse proxy: cambia el esquema del transporte a HTTP, conserva método/ruta/cuerpo/Host/cabeceras de firma y añade `X-Forwarded-Proto: https`. PHP reconstruye `@target-uri` con ese mismo `KEEPER_ORIGIN` HTTPS y confía únicamente en loopback. El handler exige exactamente la IP y el puerto temporal; su transporte interior es `HttpClientHandler` real, sin mocks ni respuestas fabricadas. El agente de producción sigue exigiendo HTTPS.
8. `SyncClient.ExecuteAsync` hace challenge → login firmado → primer sync; una segunda llamada usa el token y la versión almacenada. El observador HTTP lee las peticiones/respuestas del transporte real, sin fabricar respuestas. La caché y el outbox también son implementaciones reales; no se activan módulos de enforcement.
9. El mismo `HttpMessageSigner` firma tres peticiones reales a `episodes:batch`: envío inicial, repetición de la misma clave de idempotencia y repetición del evento con otra clave. Cada petición tiene un nonce nuevo. Un cuarto envío altera un bit de la firma y debe recibir `401 invalid_signature`.
10. PHP consulta MySQL para comprobar una sola fila en `episodes`, una sola en `episode_ingest_keys`, ningún episodio adicional y las dos secuencias de sync persistidas.

El backend siempre procesa las rutas y verifica las firmas con su código de producción. El adaptador de proxy no conoce la clave privada ni recalcula ninguna firma. Este arnés comprueba interoperabilidad de este flujo; no es una suite exhaustiva de conformidad RFC 9421 ni prueba el handshake TLS. El salto HTTP interno permite ejecutarlo en el sandbox de Windows, donde Schannel no puede adquirir credenciales TLS.

## Resultados y diagnóstico

Cada assert imprime `PASS`. Cualquier fallo produce `FAIL` y salida 1; el resultado global solo es `INTEGRATION PASS` (salida 0) después de verificar también la limpieza. Un rechazo HTTP muestra ruta, estado, código de problema PHP y `request_id`, sin imprimir tokens ni claves.

Para demostrar que un rechazo de firma en el login hace fallar todo el arnés:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests-integration/run.ps1 -CorruptLoginSignature
# Esperado: HTTP 401 /client/login, code=invalid_signature, INTEGRATION FAIL, exit 1.
```

Este modo conserva longitud/formato de la firma P1363 y altera un bit después de firmar. No modifica código de producción. Los pasos posteriores al login no se ejecutan cuando falla.

## Ejecución comprobada (2026-09-22)

Windows, PHP 8.3.30, MySQL 8.4.3, agente net8.0; build sin errores ni advertencias.

| Assert | Resultado |
|---|---|
| 19 migraciones aplicadas, hashes y seeds verificados | PASS |
| Tenant, usuario, dispositivo y thumbprint JWK C# = PHP | PASS |
| Política generada por `config/compile.php` y persistida por `PolicyCompiler` | PASS |
| Challenge real usado en firma de login C# aceptada por PHP | PASS |
| Login devuelve token Bearer ligado al tenant y dispositivo | PASS |
| Primer sync entrega exactamente la política compilada y persiste caché firmada | PASS |
| Segundo sync reutiliza token, envía misma versión y recibe `policy=null` | PASS |
| Batch inicial: HTTP 200, ACK `accepted` | PASS |
| Mismo Idempotency-Key: respuesta idéntica byte por byte | PASS |
| Otra Idempotency-Key con mismo evento: ACK `duplicate` | PASS |
| BD: una fila en episodes, una en episode_ingest_keys, ningún episodio extra | PASS |
| BD: secuencia final de SyncClient = 2 | PASS |
| Firma alterada del batch: HTTP 401 `invalid_signature` | PASS |
| Limpieza de ejecución positiva | PASS |
| Inyección `-CorruptLoginSignature`: HTTP 401, arnés FAIL y exit 1, como se esperaba | PASS |
| Limpieza después del fallo inyectado | PASS |

No se encontró desajuste entre las firmas de C# y el verificador PHP en este flujo. No se modificaron implementaciones de producción, K3/K4 ni migraciones. El resultado positivo fue `INTEGRATION PASS` (exit 0); el control negativo produjo `INTEGRATION FAIL` (exit 1). Ambos terminaron con 0 procesos temporales y 0 datadirs.

## Limpieza

Los puertos y `tests-integration/.runs/<uuid>/` son propios de cada ejecución. Build, inicialización, scripts y cliente tienen timeout. El `finally` solicita `SHUTDOWN` a la instancia cuyo datadir coincide, termina los procesos propios, ejecuta `dotnet build-server shutdown`, restaura variables de entorno y elimina datadir, claves, cachés, logs y artefactos de compilación. Antes de borrar valida que la ruta esté dentro de `.runs/`. No termina servidores PHP/MySQL ajenos.

El cierre forzado del proceso PowerShell o de Windows no puede ejecutar `finally`; la garantía comprobada corresponde a salida normal, fallos de asserts y timeouts gestionados.
