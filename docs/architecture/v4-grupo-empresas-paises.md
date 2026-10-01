# v4 — Grupo con empresas internas, países y calendarios de festivos

Decisión de Koichi del 2026-10-01. Migración `0035_group_companies_countries.sql`.

## Modelo

- **Grupo AZC es una sola empresa en Keeper** (un tenant). Dentro tiene **empresas del grupo**: unidades de
  organización de tipo `company`. Son AZC Legal, BilingueLaw, LawyerDesk, Matiz LegalTech y el propio Grupo AZC
  para el personal del holding. Se crean, renombran y desactivan en Organización.
  - Si una empresa se independiza más adelante, se mueve a su propio tenant con una herramienta de migración.
    Esa herramienta todavía no existe.
- **Cada persona tiene su empresa del grupo** (`users.company_id`). El dato también se guarda en el historial
  (`user_assignments.company_id`), así los reportes de un periodo pasado filtran por la empresa que tenía entonces.
- **Las sedes tienen país** (`org_units.country_code`). Solo las sedes lo tienen.
  - Varias empresas comparten sedes: por ejemplo, BilingueLaw y AZC Legal en Tequendama.
  - Quien trabaja desde casa va en una sede remota por país, por ejemplo «Remoto – Honduras».

## Filtros

Inicio, Personas y Reportes, y las APIs `/users`, `/reports/*`, `/tenants/{id}/devices` y
`/ext/v1/*`, aceptan estos filtros:

- `company_id`: empresa del grupo.
- `country`: país de la sede.
- `site_id` y `area_id`.

Sin filtros se ve todo el grupo.

## Festivos

Los festivos se agrupan en **calendarios** (`holiday_calendars`): uno por país, o los que se necesiten.

El calendario que usa cada persona se decide en este orden. `HolidayCalendars::effective` lo resuelve igual en el
servidor y en la pantalla.

1. El calendario asignado a la persona (`users.holiday_calendar_id`). Se asigna en la ficha o en grupo desde
   Festivos → «Asignar a personas», filtrando por empresa, país, sede o área.
2. Si no tiene uno asignado, el calendario activo del país de su sede.
3. Si su sede no tiene país, el calendario por defecto (`is_default`).

Cuando cambia un calendario, el país de una sede o la asignación de una persona, se recalcula la jornada esperada
de los festivos ya pasados de los últimos 400 días.

Los festivos anteriores a 0035 pasaron a calendarios:

- Los que estaban marcados para sociedades pasaron a «Estados Unidos».
- Los que aplicaban a toda la empresa pasaron a «Colombia», que queda como calendario por defecto.

## Carga inicial

```
php tools/seed-group.php <tenant> --companies="AZC Legal,BilingueLaw,LawyerDesk,Matiz LegalTech,Grupo AZC" --countries=CO,US,HN --years=2026,2027
```

El cálculo está en `HolidaySeeds`:

- **CO:** Ley Emiliani y fechas que dependen de la Pascua.
- **US:** el conjunto que observa la oficina. Un festivo que cae en sábado se observa el viernes, y uno en domingo,
  el lunes.
- **HN:** Código de Trabajo, art. 339. Los días de la Semana Morazánica cambian cada año por decreto. Se cargan en su
  fecha nominal, marcados «revisar decreto», y hay que ajustarlos en el panel.

La herramienta es repetible: no duplica empresas ni festivos.
