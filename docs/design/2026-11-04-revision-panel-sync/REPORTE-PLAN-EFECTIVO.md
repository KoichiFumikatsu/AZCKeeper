# Lectura del plan efectivo

`GET /users/{id}/effective-plan` (`getUserEffectivePlan`) está disponible en `/portal/v1` y `/v1`. Exige `reportes.ver` y la misma comprobación `AdminAccess::user` que la actividad: incluye la persona propia según su alcance y devuelve 404 fuera de él o del tenant.

La respuesta 200 contiene exactamente `plan_id` (UUID o null), `name` (string o null) y `functions` (string[]). Usa `PersonPlans::effectivePlan` y `PersonPlans::functions`: plan propio activo, alternativa por defecto del cliente y funciones efectivamente contratadas. Sin plan devuelve `{"plan_id":null,"name":null,"functions":[]}`. La lectura no ejecuta recompilaciones pendientes.

## Archivos a desplegar

| Archivo | Cambio |
| --- | --- |
| `backend-v4/src/AdminApi.php` | Despacho, autorización de alcance y respuesta. |
| `backend-v4/src/Application.php` | Excluye esta lectura de la recompilación posterior. |
| `backend-v4/src/Portal.php` | Añade la ruta a la lista permitida. |
| `backend-v4/config/contract.json` | Contrato regenerado desde OpenAPI: 189 rutas y 322 esquemas. |

Documentación y pruebas: `docs/architecture/openapi-v4.yaml`, `backend-v4/tests/portal.php` y este reporte. No requiere migraciones ni archivos de `public`.

## Verificación

```powershell
python backend-v4/tests/export-contract.py
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MariaDbDirectory backend-v4/.validation/mariadb-runtime/mariadb-10.5.29-winx64
```

- Suite completa MariaDB 10.5.29: **6790 aserciones aprobadas**, salida **0**; incluye **112** del bloque nuevo y **148** del portal existente. Además: **64 verificaciones de esquema** y **10 aserciones de transporte** aprobadas.
- Cobertura nueva: **22 casos comparados entre portal y panel**; persona propia, supervisor propio/equipo directo/indirecto/fuera de alcance, cuenta sin permiso, plataforma con `X-Tenant-ID`, aislamiento bidireccional de **2 tenants**, persona inexistente, plan propio/default/inactivo/ausente e intersección de funciones contratadas. Además, autenticación y preservación de políticas y cola pendiente.
- Sintaxis PHP de los 4 archivos PHP modificados y `git diff --check`: aprobadas. Comparación estructural de OpenAPI: 1 ruta añadida, 0 operaciones existentes modificadas.
- Primera ejecución: los 22 casos comparados pasaron; falló una comprobación adicional de autenticación que omitía el encabezado obligatorio `X-Tenant-ID` del panel. Se corrigió la petición de prueba.
- MariaDB utilizó el fallback existente `utf8mb4_unicode_ci`; la regresión de collation pasó. El harness confirmó la eliminación de los procesos y datos temporales al finalizar.

Sin despliegue, commit ni stash. `backend-v4/public`, `/me`, `/users` y las rutas de planes existentes permanecen intactos. El directorio preexistente `.vite/` no se modificó.
