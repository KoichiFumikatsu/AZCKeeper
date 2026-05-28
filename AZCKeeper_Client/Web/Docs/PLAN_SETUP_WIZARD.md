# Plan: Keeper Setup Wizard & Onboarding

**Estado:** Planificado (no implementado)  
**Fecha:** 2026-03-07  
**Prioridad:** Futura — implementar cuando el core esté estable

---

## Contexto

Keeper ya es 100% independiente de tablas legacy org. Las 5 tablas `keeper_*` (sociedades, firmas, áreas, cargos, sedes) viven por cuenta propia. El sync legacy→keeper existe pero es opcional.

El objetivo es que cuando se venda a una empresa "Y" nueva, haya un flujo de onboarding completo para poblar TODA la BD — no solo org, sino usuarios, asignaciones, políticas.

---

## Wizard de Primera Vez — "Keeper Setup"

Página que aparece cuando la BD Keeper está vacía (0 admin accounts). Flujo paso a paso:

```
┌─────────────────────────────────────────────────────┐
│              KEEPER — Configuración Inicial          │
│                                                      │
│  Paso 1: Crear cuenta superadmin                     │
│  Paso 2: Estructura organizacional                   │
│  Paso 3: Usuarios (empleados)                        │
│  Paso 4: Asignaciones                                │
│  Paso 5: Políticas iniciales                         │
└─────────────────────────────────────────────────────┘
```

### Paso 1 — Superadmin (ya existe parcialmente)
- Crear el primer admin con email + contraseña
- Esto desbloquea el panel

### Paso 2 — Estructura organizacional
- Elegir fuente:
  - **A) Conectar a BD existente** → configurar host/user/pass/db → `SHOW TABLES` → el admin elige qué tabla tiene firmas, cuál tiene áreas, cargos, sedes → mapea columnas → importar
  - **B) Subir CSV** → descargar plantillas → llenar → subir → preview → confirmar
  - **C) Manual** → "lo haré después desde el panel"

### Paso 3 — Usuarios (empleados)
- Mismas 3 opciones:
  - **A) BD** → elegir tabla de empleados → mapear: cuál columna es nombre, cuál es email, cuál es cédula, cuál es contraseña/hash
  - **B) CSV** → plantilla con: cc, display_name, email, password (o se genera temporal)
  - **C) Manual** → "los creo después" o "se registran solos al instalar el cliente"

### Paso 4 — Asignaciones
- Si vino de BD: auto-mapear (ej: `employee.company` → `firm_id`)
- Si vino de CSV: columnas opcionales de firma/área/cargo en el mismo CSV de usuarios
- Si manual: se asignan desde el panel después

### Paso 5 — Políticas
- Perfil default: horario laboral (8-18 L-V), sin bloqueo
- Opción de clonar un template predefinido

---

## Arquitectura técnica

| Componente | Descripción |
|-----------|-------------|
| `setup.php` | Wizard (solo accesible si 0 admins en BD) |
| `SetupService.php` | Lógica de importación/mapeo |
| `CsvImporter.php` | Parser genérico: valida headers, preview, batch insert |
| `DbConnector.php` | Conecta a BD externa, lista tablas/columnas, extrae datos |
| Detección: `index.php` | Si `keeper_admin_accounts` tiene 0 rows → redirige a `setup.php` |

La conexión a BD externa es para **lectura puntual** — se conecta, extrae, desconecta. Keeper nunca depende de ella después.

---

## Flujo CSV completo

```
Admin descarga plantilla    →   Llena en Excel/Sheets
     ↓                              ↓
keeper_users.csv            nombre,email,cc,password
keeper_firmas.csv           nombre,manager,mail_manager
keeper_areas.csv            nombre,descripcion,area_padre
keeper_cargos.csv           nombre,nivel_jerarquico
keeper_sedes.csv            nombre,codigo,descripcion
     ↓
  Upload → PHP parsea → Preview tabla
     ↓
  Confirmar → INSERT batch → Listo
```

---

## Mapeador visual BD legacy (Fase 3 — más compleja)

```
Tabla legacy       →  Tabla Keeper         Columnas
─────────────────────────────────────────────────────
☑ firm             →  keeper_firmas        name → nombre, manager → manager
☑ areas            →  keeper_areas         nombre → nombre, padre_id → padre_id
☑ cargos           →  keeper_cargos        nombre → nombre
☑ sedes            →  keeper_sedes         nombre → nombre, codigo → codigo
☐ [otra tabla]     →  keeper_sociedades    [mapear columnas]
```

---

## Fases de implementación

| Fase | Qué | Prioridad |
|------|-----|-----------|
| 1 | CSV import/export en Organización (upload + plantilla descargable) | Alta — resuelve el caso "sin legacy" |
| 2 | Detección de tablas legacy (`SHOW TABLES` + `DESCRIBE`) | Media |
| 3 | Mapeador visual legacy → keeper con UI drag/columnas | Baja — es la más compleja |
| 4 | Wizard setup.php completo (pasos 1-5) | Después de fases 1-3 |

---

## Puntos actuales de sync legacy (para referencia)

Solo 2 puntos tocan legacy, ambos son de sincronización intencional:
1. `LegacySyncService.php` — `syncOne()` y `syncAllFromPanel()` leen `employee.*` → escriben en `keeper_user_assignments`
2. `assignments.php` → `reset_override` — restaura datos desde `employee` al quitar override manual

El día que se desconecte legacy, solo hay que deshabilitar esos 2 puntos.
