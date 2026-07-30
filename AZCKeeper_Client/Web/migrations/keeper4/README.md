# Migraciones de Keeper 4

Base de datos nueva, desde cero. Keeper 3 queda intacto en su propia base como historial de solo lectura.
Entorno de desarrollo: `devkeep.azclegal.com` + `pipezafra_keepdev`.

## Orden de aplicación

1. `00_drop_legacy.sql` — **solo en DEV**. Vacía el esquema de Keeper 3.
2. `01_identidad.sql` — personas, equipos, organización, multi-tenant
3. `02_actividad.sql` — episodios (particionados), rollup diario, resumen con cobertura
4. `03_operacion.sql` — políticas, estado de módulos, seguridad
5. `04_panel.sql` — cuentas admin, roles, ajustes, auditoría con actor
6. `05_licenciamiento.sql` — catálogo de módulos, tiers, `keeper_firmas.tier_id`
7. `06_comandos_y_datos_sensibles.sql` — cola de comandos, screenshots, ubicación
8. `07_seed.sql` — catálogo de módulos, tiers, política global, roles
9. `08_tier_overrides.sql` — interruptor global de enforcement + override por firma

El orden importa: `05` hace `ALTER` sobre `keeper_firmas` (creada en `01`) y referencia `keeper_tier`;
`07` puebla `keeper_module`/`keeper_tier` antes de que se puedan asignar. Cada archivo asume que los
anteriores ya corrieron.

## Las cuatro capas que gobiernan un módulo

| Capa | Pregunta | Tabla |
|---|---|---|
| Catálogo | ¿Existe? | `keeper_module` |
| Tier | ¿La firma tiene derecho? (comercial) | `keeper_tier` + `keeper_tier_module` → `keeper_firmas.tier_id` |
| Política | ¿Está encendido? (operativo) | `keeper_policy_assignments` |
| Estado | ¿Corre de verdad? (observado) | `keeper_device_module_state` |

El backend recorta la política efectiva contra el tier de la firma antes de enviarla al cliente.

## Qué cambia respecto al esquema 3

| Cambio | Motivo |
|---|---|
| `keeper_external_ref` | Identidad interna separada del id externo. Sin esto el multi-tenant es incorrecto desde el segundo cliente |
| FK en las 5 dimensiones de `keeper_user_assignments` | La regla "asignaciones = identidad interna" pasa a ser verificable por el motor, no solo un comentario |
| `keeper_episode` particionada | Purga por `DROP PARTITION` en vez de `DELETE` masivo |
| `keeper_episode_daily` | Los agregados dejan de escanear la tabla completa |
| `keeper_day_summary` sin `GREATEST` | Era un trinquete que volvió imborrable el doble seed |
| Banderas `*_tracked` + `focus_score` NULLABLE | "No medido" no puede parecer "medido con buen resultado" |
| `keeper_device_module_state` | Eco del estado real, no de la política recibida |
| `keeper_audit_log.admin_id` + `event_category` | La bitácora ya responde quién, no solo a quién; `data_access` audita consultas sensibles |
| `keeper_module` / `keeper_tier` / `keeper_tier_module` | Licenciamiento por tiers (venta del servicio) |
| `keeper_device_command` | Canal servidor→equipo: apagado remoto, diagnóstico de red bajo demanda |
| `keeper_screenshot` / `keeper_location` | Features nuevas; blob de captura en object storage, no en BD |
| `keeper_source.key_version` | Permite rotar `APP_KEY` sin perder lo cifrado |
| Sin `app_name` ni `call_app_hint` en episodios | Duplicado y derivable, en la tabla más grande |

## Tablas del esquema 3 que NO se migran

`keeper_module_catalog` (reemplazada por `keeper_module`, viva), `keeper_device_locks`, `keeper_events`,
`keeper_daily_metrics`, `keeper_handshake_log`. Ningún código vivo las leía.

## Mantenimiento de particiones

`keeper_episode` está particionada por mes hasta 2027-01 más `pmax`. Antes de que se agote hay que añadir
particiones nuevas y, según la ventana de retención acordada, eliminar las más antiguas con
`ALTER TABLE keeper_episode DROP PARTITION pAAAA_MM`.

## Pendientes de confirmación (ver specs)

- Tiers reales y su reparto de módulos (aquí va una propuesta: básico/pro/enterprise).
- Dónde vive el object storage de las capturas (candidato: Nextcloud en `cloud.azclegal.com`).
- Retención del detalle de episodios (recomendado: 6 meses).
- Enmascarado de `window_title` por rol.
