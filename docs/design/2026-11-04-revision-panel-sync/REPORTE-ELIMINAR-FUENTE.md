# Eliminar fuente descartada

`DELETE /data-sources/{id}` (`deleteDataSource`) responde 204 y exige el mismo permiso y restricción de plataforma que `patchDataSource`. Solo permite fuentes sin dominios, en `simulate` y con `last_success_at` nulo; cualquier incumplimiento responde 409 `invalid_transition`, detalle `source_in_use`.

Dentro de la transacción administrativa existente se borran los elementos de las vueltas, las vueltas, los vínculos y la fuente, filtrando por tenant. Se registra auditoría. Las personas, unidades, horarios y festivos permanecen intactos.

El panel muestra **Eliminar** solo cuando corresponde, confirma el nombre y las consecuencias, bloquea las acciones con **Eliminando…** y explica el rechazo `source_in_use`.

## Archivos cambiados

| Archivo | Cambio |
| --- | --- |
| `docs/architecture/openapi-v4.yaml` | Operación DELETE, autorización y respuestas. |
| `backend-v4/config/contract.json` | Contrato regenerado con `python backend-v4/tests/export-contract.py`: 188 rutas y 322 esquemas. |
| `backend-v4/src/AdminApi.php` | Despacho de `deleteDataSource`. |
| `backend-v4/src/DataSources.php` | Validaciones, borrado de dependencias y auditoría. |
| `backend-v4/public/assets/k3.js` | Botón, confirmación, estado ocupado, mensajes y texto de auditoría. |
| `backend-v4/tests/data-sync.php` | Borrado, tres bloqueos independientes, permisos, CSRF, auditoría y aislamiento entre dos tenants con IDs distintos. |
| `backend-v4/tests/data-sync-panel.cjs` | Visibilidad por condición y permiso; cancelación, conflicto, estado ocupado, éxito y fallo de recarga. |
| `docs/design/2026-11-04-revision-panel-sync/REPORTE-ELIMINAR-FUENTE.md` | Este reporte. |

## Verificación

```powershell
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MariaDbDirectory backend-v4/.validation/mariadb-runtime/mariadb-10.5.29-winx64
node backend-v4/tests/data-sync-panel.cjs
```

- Panel DOM: **32 aserciones aprobadas**.
- Suite MariaDB 10.5.29: **6678 aserciones aprobadas**, salida 0; incluye **34** de eliminación de fuente, **103** de sincronización general y **95** del panel en backend. Además, **64 verificaciones de esquema** y **10 aserciones de transporte** aprobadas.
- El fixture de eliminación reproduce **6 vínculos de unidades**, añade vínculos a persona, horario y festivo y **2 vueltas con sus elementos** por tenant. Comprueba que esos datos locales y el segundo tenant permanecen intactos.
- MariaDB usa el fallback existente `utf8mb4_unicode_ci` porque no admite `utf8mb4_0900_ai_ci`; la comprobación de collation también pasó. La instancia temporal y sus procesos se limpiaron al terminar.
- Sintaxis PHP de los dos archivos de producción y `tests/data-sync.php`, sintaxis de `k3.js` y `git diff --check`: aprobadas.
- La primera ejecución detectó un fixture que intentaba asignar un permiso de plataforma a un rol cliente; el trigger lo rechazó. Se corrigió para comprobar permisos con cuentas válidas y se repitió la suite completa.

## Archivos a desplegar

1. `backend-v4/config/contract.json`
2. `backend-v4/src/AdminApi.php`
3. `backend-v4/src/DataSources.php`
4. `backend-v4/public/assets/k3.js`

No requiere migraciones. Cambios sin commit; no se desplegó ni se accedió a servidores externos. Las pruebas utilizan la instancia local aislada del harness.

Durante la ejecución apareció el commit concurrente `23527fd` (HEAD inicial: `ecc4054`), que cambia únicamente `backend-v4/public/assets/app.js`. No fue creado por esta tarea y no se modificó ese archivo. Los ocho archivos de esta entrega siguen sin commit. La carpeta `.vite/` ya estaba sin seguimiento al iniciar y no se tocó.
