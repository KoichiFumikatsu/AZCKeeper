# Revisión del panel de sincronización — 2026-11-04

## Alcance y estado

Trabajo sobre `festivos-calendarios`, base `b9b3763`. Cambios limitados a `backend-v4/` y `docs/`. Sin despliegue, conexiones a servidores externos ni `git stash`. Las pruebas levantan y eliminan sus propios procesos locales de MariaDB/PHP y una base aislada.

Los 91 `matched_name`, 219 `unknown_reference` y 212 `orphan` corresponden a la simulación informada por Koichi; no se volvió a consultar producción. Se incorporó un caso sintético de 91 coincidencias para verificar el lote.

## Cambios

| Archivo:línea | Cambio |
| --- | --- |
| `backend-v4/src/DataSources.php:193` | `POST /sync-items/resolve-matched`: recibe entre 1 y 500 IDs únicos, valida empresa, vuelta aplicada, pendiente abierto, `matched_name` y tipo `org_unit`/`schedule`. Reutiliza el resolvedor individual. |
| `backend-v4/src/DataSources.php:210` | Auditoría por elemento con `resolveMatchedSyncItems`, dentro de la misma transacción que las vinculaciones. Un conflicto revierte vínculos, modificaciones, resoluciones y auditorías de todo el lote. |
| `backend-v4/src/AdminApi.php:220` | Despacho de la nueva operación. Usa la transacción administrativa existente de `Application.php:125`. |
| `backend-v4/src/DataSources.php:154` | El DTO expone `entity_type` y `local_name`; el panel puede filtrar el lote y comparar el nombre de la fuente con el actual en Keeper, también en pendientes antiguos. |
| `backend-v4/src/DataSync.php:260` | Coincidencia exacta con `fold` primero. Sin exacta, propone el nombre plegado más cercano con longitud mínima 6 en ambos nombres y distancia Levenshtein de 1 o 2. Empates: orden estable por ID. |
| `backend-v4/src/DataSync.php:317` y `:394` | Unidades del mismo `kind` y horarios sin vínculo con esa fuente: generan `similar_name`. `asked()` evita repetir preguntas abiertas y respeta el rechazo. Aprobar vincula; rechazar permite crear aparte en la siguiente sincronización. |
| `backend-v4/src/DataSync.php:532` y `:567` | Recoge las cédulas normalizadas antes de validar personas y referencias. No genera huérfana si una referencia local `document` o `k3:cc` coincide con una cédula recibida, incluso con `unknown_reference`. |
| `backend-v4/src/DataSync.php:245` y `:577` | Al vincular o volver a procesar una persona ya vinculada, cierra sus huérfanas abiertas del mismo tenant. Quedan `rejected` con fecha: se descarta el retiro. Incluye pendientes de fuentes anteriores y no altera otra empresa. La simulación revierte también este cierre. |
| `backend-v4/src/DataSync.php:768` | Al aprobar nombres, comprueba que exista el destino, siga correspondiendo la fuente propietaria y no haya vínculos incompatibles. Si aplicar el registro genera un error, devuelve conflicto y revierte la operación. |
| `backend-v4/public/assets/k3.js:2829` y `:2938` | Pregunta y botones individuales para nombres parecidos; ambos nombres visibles. Botón «Vincular todas las de mismo nombre (N)» con confirmación de cantidad y resultado. Huérfanas conservan retiro individual con confirmación. |
| `backend-v4/public/assets/k3.js:2834` | Explicaciones en español para referencias pendientes, cédulas ambiguas, correos repetidos y demás incidencias del detalle. Ante error del lote se distingue rechazo, resultado incierto y éxito con fallo al actualizar la lista. |
| `docs/architecture/openapi-v4.yaml:13262` y `:18833` | Endpoint y esquemas. Mismo permiso `integraciones.aprobar`, restricción de plataforma y límite de empresa que la resolución individual. |
| `backend-v4/config/contract.json:20145` | Contrato regenerado con `export-contract.py`: 188 rutas y 322 esquemas. |
| `backend-v4/tests/data-sync.php:595` | Pruebas de lote, rollback, auditoría, rechazo de simulaciones y tipos excluidos, permisos, dos tenants, cédulas conocidas, cierre de huérfanas, aprobar/rechazar/repetir nombres parecidos y lote de 91 elementos. |
| `backend-v4/tests/data-sync-panel.cjs:1` | Prueba DOM de los botones y sus mensajes usando el código real de `sourcesPage()`, con API y componentes auxiliares simulados. |

## Operación desde el panel

1. Revisar una simulación y aplicar la sincronización real mediante el flujo existente. Los pendientes de simulaciones no se resuelven como datos reales.
2. En **Pendientes de aprobación**, confirmar el lote de nombres iguales. Los nombres parecidos se deciden uno por uno comparando las dos columnas.
3. Pulsar **Sincronizar ahora** para que se creen los registros rechazados como equivalentes y se procesen las personas cuyas firmas, sedes u horarios ya se confirmaron. Repetir si aparecen dependencias de otro nivel.
4. Los retiros de personas huérfanas siguen siendo individuales. Al vincular una persona, desaparece su propuesta de retiro antigua.

## Verificación

Comando de suite completa solicitado:

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MariaDbDirectory backend-v4/.validation/mariadb-runtime/mariadb-10.5.29-winx64
```

La primera ejecución llegó a los bloques existentes de sincronización y se detuvo por un fixture nuevo que omitía campos organizacionales al crear una cuenta administrativa. Se corrigió para usar la persona completa del fixture y se inició nuevamente la suite completa.

**Segunda ejecución completa: `SMOKE PASS: 6642 assertions`.** Finalizó también la limpieza de procesos y base aislada. Entorno observado: PHP 8.3.30 y MariaDB 10.5.29 en Windows.

| Bloque | Resultado real |
| --- | --- |
| Smoke completo | 6.642 comprobaciones, PASS. |
| Transporte, ejecutado antes del smoke | 10 comprobaciones, PASS. |
| Verificación de migraciones | 64 comprobaciones; fixtures revertidos. Aplicación, repetición y prueba de compatibilidad de collation completadas. |
| Sincronización existente | 102 comprobaciones, PASS. |
| Puente One | 28 comprobaciones, PASS. |
| Frenos de sincronización | 71 comprobaciones, PASS. |
| Calendarios y festivos sincronizados | 226 comprobaciones, PASS. |
| Nuevos casos del panel en `data-sync.php` | 95 comprobaciones, PASS; incluye vincular las 91 unidades en una sola solicitud. |

Los cinco bloques de sincronización están incluidos en las 6.642 comprobaciones del smoke; no se suman de nuevo. Log local completo: `backend-v4/.validation/panel-sync-suite-final.log` (ignorado por Git).

**Particularidad del código de salida:** la herramienta que envuelve PowerShell devolvió `1`, aunque el log terminó con `SMOKE PASS` y `Isolated MySQL processes and datadir removed.`. Registró como `NativeCommandError` el aviso esperado de MariaDB: no dispone de `utf8mb4_0900_ai_ci` y usa `utf8mb4_unicode_ci`. Se reprodujo el mismo código `1` con un comando mínimo que escribe un aviso a stderr y termina normalmente; al capturar su `$LASTEXITCODE`, la salida nativa fue `0`. Por tanto, se informa el resultado de las aserciones y también esa anomalía del envoltorio; no se presenta el código global como `0`. No hubo fallos de aserciones en la segunda ejecución.

| Verificación complementaria | Resultado |
| --- | --- |
| `node backend-v4/tests/data-sync-panel.cjs` | PASS, 17 comprobaciones. |
| `php -l` en `DataSync.php`, `DataSources.php`, `AdminApi.php` y `tests/data-sync.php` | 4 archivos sin errores de sintaxis. |
| `node --check backend-v4/public/assets/k3.js` | Sin errores de sintaxis. |
| `python backend-v4/tests/export-contract.py` | 188 rutas, 322 esquemas. |
| `git diff --check` | Sin errores de espacios. |

## Archivos para un despliegue posterior

Desplegar juntos, siguiendo el procedimiento habitual del proyecto:

- `backend-v4/src/DataSync.php`
- `backend-v4/src/DataSources.php`
- `backend-v4/src/AdminApi.php`
- `backend-v4/config/contract.json`
- `backend-v4/public/assets/k3.js`

Documentación a conservar: `docs/architecture/openapi-v4.yaml` y este reporte. Pruebas a conservar en el repositorio: `backend-v4/tests/data-sync.php` y `backend-v4/tests/data-sync-panel.cjs`; no necesitan publicarse.

**Migración: ninguna.** `sync_items.reason` ya es `VARCHAR(80)`. Las coincidencias parecidas aprobadas usan el método existente `manual`; el motivo preciso sigue siendo `similar_name` en el pendiente. No se amplió ningún ENUM ni se creó `0072`.

## Riesgos y límites

- Un nombre igual puede pertenecer a entidades distintas. La confirmación del lote expresa la decisión del operador; el algoritmo no determina identidad. Si dos elementos intentan vincular la misma entidad, falla todo el lote y hay que decidir esas coincidencias individualmente.
- La similitud es una propuesta textual, no una equivalencia semántica. Se preservan `fold` y `levenshtein` de PHP. En un empate se propone una candidata estable; el operador puede rechazarla y crear aparte.
- Se conserva el límite existente de 500 pendientes visibles. El lote contiene exactamente los IDs y la cantidad que se confirmaron; al resolverlos aparecen los siguientes. El panel informa el límite cuando se alcanza.
- Los pendientes antiguos siguen usando el contenido de su vuelta original. Después de resolverlos se debe sincronizar para leer los valores actuales y resolver referencias dependientes.
- Los falsos retiros anteriores desaparecen al procesar la vinculación, no mediante una limpieza global al desplegar. Mientras la persona siga bloqueada por referencias, su pendiente antiguo puede seguir visible, aunque no se genera otro.
- Los permisos se mantienen: las decisiones de integración requieren una cuenta de plataforma con `integraciones.aprobar`; no se habilitan a roles de clientes.
- No se verificaron los datos reales de One ni producción. La prueba DOM no sustituye una inspección visual en navegador.

## Versionamiento

**Sin commit por permisos.** Se intentó `git add` con los nueve archivos de esta entrega. Git devolvió:

```text
fatal: Unable to create 'C:/Users/FumiWork/Documents/AZCKeeper/.git/worktrees/AZCKeeper-festivos/index.lock': Permission denied
```

El índice del worktree está fuera de la raíz permitida de escritura. No se intentó eludir esa restricción. Los cambios quedan sin preparar y sin commit en `festivos-calendarios`.

Mensaje preparado para el commit cuando se pueda escribir en el índice:

```text
Resolver coincidencias de sincronización desde el panel y evitar falsas huérfanas

Agregar vinculación atómica en lote, aprobación de nombres parecidos y cierre de retiros obsoletos, con pruebas y reporte.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
```

El estado inicial incluía `.vite/` sin seguimiento, que no se modificó, y el YAML marcado como modificado sin diferencias textuales respecto a HEAD; los cambios funcionales del YAML están acotados al contrato descrito.
