# AZCKeeper v4 — Arquitectura de información, navegación y flujos

> Spec de usabilidad. Guía el rediseño del panel sobre el diseño elegido `neomorfismo-antislop/`.
> Fecha: 2026-09-16. Decisiones: usuario primario = **admin de empresa cliente (externo)**;
> enfoque = **IA + flujos antes que mockups**.

## 0. Objetivo

Que un **admin de empresa cliente**, que entra pocas veces al mes y no es técnico, pueda hacer sus
tareas **sin perderse y sin romper nada**. Si él no se pierde, IT interno (que sabe más) tampoco.
Regla rectora: **una pantalla = una tarea = una acción primaria**.

---

## 1. Personas y alcance (scope)

Roles semilla (jerarquía de mayor a menor poder). **Ninguno está hardcodeado**: son datos editables
(ver §1.5 RBAC parametrizable). El "nivel a prueba de tontos" describe la exigencia de diseño, no el poder.

| Rol (semilla) | Quién | Alcance de datos por defecto | Uso | A prueba de tontos |
|---|---|---|---|---|
| **Super admin** | Koichi / IT lead AZC | Todas las empresas + parametrización total | Diario | Medio (poder total, claro) |
| **Gerencia** | Gerente general | Su empresa | Ocasional | Alto |
| **Dirección** | Directores de área | Su empresa (áreas dirigidas) | Ocasional | Alto |
| **Coordinación** | Coordinadores | Su área ∩ sede | Frecuente | Alto |
| **IT** | Soporte técnico | Asignado (empresas/sedes) | Diario | Medio (densidad OK) |
| **RRHH** | Recursos humanos | Su empresa (personas) | Ocasional | Alto |
| **Admin Empresa** (PRIMARIO) | Admin del cliente, no técnico | SOLO su empresa (tenant) | Esporádico | **Máximo** |
| **Colaborador** | Empleado dueño del equipo | Solo lo suyo | (Sin login en v4.0) | Máximo |

- **Colaborador:** existe en el modelo desde v4.0 pero **sin acceso al panel todavía**. Se reserva para
  autoservicio futuro: el colaborador entra a **registrar sus actividades** y **modificar sus horarios**.
  Diseñar la estructura de permisos contando con él, aunque el login llegue después.
  **Datos del colaborador (capturar el registro completo desde v4.0):** identidad (nombre, documento,
  correo), **empresa/firma a la que pertenece** (tenant), área, sede, cargo, y horario (editable a futuro
  por él mismo). El modelo se deja **extensible** — se añadirán campos al empezar a desarrollarlo, sin
  romper lo existente. La firma es obligatoria desde ya (ancla el aislamiento por tenant).
- **Primario de diseño = Admin Empresa.** Si él no se pierde, los roles internos (que saben más) tampoco.

**Principio de aislamiento:** el rol nunca amplía el alcance de datos. Un Admin Empresa jamás ve ni toca
otra empresa. (La auditoría K3 marcó que hoy firmas/sedes son filtros, no frontera real — en v4 el tenant
es frontera dura en cada consulta y cada acción.)

---

## 1.5. RBAC parametrizable — regla transversal: **todo debe poder configurarse**

Principio rector de v4: **si algo existe, debe ser parametrizable.** Nada de roles ni permisos cableados
en código; son datos que se editan desde el panel. Así, cuando Gerencia quiera agregar un rol o un permiso
mañana, lo hace sin tocar código ni esperar un release.

- **Roles = datos.** Se pueden **crear roles custom** además de los semilla, renombrar, clonar y desactivar
  (nunca borrar uno con gente asignada sin reasignar primero).
- **Permisos = matriz granular.** Cada capacidad del sistema es un permiso con nombre
  (`equipos.bloquear`, `reglas.editar`, `usuarios.baja`, `reportes.ver`, `roles.gestionar`, …). Un rol es
  un conjunto de permisos + un alcance (empresa / área / sede / propio).
- **Meta-permiso `roles.gestionar`** (crear/editar roles y asignar permisos). Gobernado por **dos gates**
  para evitar que una empresa "haga cosas raras":
  1. **Gate de empresa (capability del tenant), OFF por defecto.** Ninguna empresa puede autogestionar sus
     roles hasta que el **Super admin active** esa capacidad para esa empresa concreta. Sin ella, roles y
     permisos de esa empresa los maneja solo AZC (Super admin / IT).
  2. **Gate de rol, dentro de la empresa habilitada.** Solo si el gate de empresa está activo, alguien de
     esa empresa (p. ej. su Gerencia) a quien se le asigne `roles.gestionar` puede parametrizar los roles
     **de su propia empresa**, nunca de otras ni elevarse por encima de lo que ya tiene.
  El Super admin puede revocar el gate de empresa en cualquier momento (vuelve a control AZC).
- **Barandas de seguridad de la parametrización:**
  - Nadie puede otorgar un permiso que él mismo no posee (no auto-escalada).
  - `roles.gestionar` no se puede auto-asignar; siempre baja del Super admin.
  - Cambios de rol/permiso quedan en **auditoría** (quién, qué, cuándo).
  - Cada permiso trae un **default seguro**; un rol nuevo nace sin permisos peligrosos.
- **Todo lo configurable, no solo roles:** políticas, catálogos (áreas, sedes, cargos, tipos de actividad),
  tiers/módulos, textos de marca, horarios. La UI de administración sigue el mismo patrón para todos: lista
  + "Agregar" + editar, con alcance por empresa.

Esto reemplaza mi pregunta abierta #1: **"¿quién aplica las reglas?" ya no es una decisión fija** — es el
permiso `reglas.editar`, que por defecto tienen Super admin, IT y Admin Empresa, y que el Super admin (o
quien tenga `roles.gestionar`) puede conceder o quitar a cualquier rol. Igual para todo lo demás.

---

## 2. Principios de usabilidad (los "idiot-proof")

1. **Task-first.** Cada pantalla tiene UNA acción primaria (botón grande, claro), lo demás secundario.
2. **Contexto siempre visible.** Barra superior fija: *Empresa ▸ Sección ▸ objeto actual* (breadcrumb).
   Nunca dudar "¿a qué empresa/equipo le estoy tocando esto?".
3. **Lenguaje humano.** "Bloquear este equipo", no "device_lock policy". Ícono + etiqueta, nunca ícono solo.
4. **Flujos guiados para lo crítico/destructivo.** Bloquear, borrar, degradar usuario, wipe: confirmación
   escrita + resumen "qué va a pasar" + deshacer donde exista. Nada irreversible a un clic.
5. **Estados que enseñan.** Vacío / cargando / error dicen *qué hacer ahora*, no un spinner mudo.
6. **Búsqueda global** (equipo, persona, empresa) + filtros visibles y fáciles de quitar.
7. **Progressive disclosure.** Lo avanzado/IT se oculta al admin de empresa; aparece solo para quien lo usa.
8. **Defaults seguros.** Toda política nueva nace en el estado menos peligroso; activar es explícito.

---

## 3. Modelo de navegación

**Vista del admin de empresa (primaria) — máximo 6 secciones**, en orden de tarea, no de módulo:

```
┌─ Barra superior fija: [Logo AZC Keeper]  [Empresa: ACME ▾]  [🔍 Buscar]  [👤 admin]
├─ Nav lateral (6 ítems, ícono+texto):
│   🏠 Inicio        → estado de la flota + alertas + acciones rápidas
│   💻 Equipos       → lista, estado, acciones remotas
│   👥 Usuarios      → altas/bajas, asignación equipo↔persona
│   🛡️ Reglas        → bloqueo web / descargas / instalación / horario (política)
│   📊 Reportes      → productividad, presencia (lectura)
│   ⚙️ Ajustes       → marca de la empresa, datos, claves de API
└─ Área de trabajo: breadcrumb + 1 acción primaria por pantalla
```

- El selector de empresa **solo aparece para super admin / IT AZC** (multi-tenant). Para el admin de una
  empresa, la empresa es fija y se muestra como etiqueta, no como selector (no puede cambiarla).
- Super admin / IT ven **secciones extra** (Empresas, Tiers/licencias, Auditoría, Releases, Diagnóstico,
  Control remoto avanzado, Roles) que al admin de empresa **no se le muestran**.

---

## 4. Visibilidad por permiso (progressive disclosure)

Como los permisos son parametrizables (§1.5), la visibilidad de cada sección **no se cablea al rol** sino
al **permiso** que la gobierna. La UI **oculta** lo que el usuario no puede usar (no lo muestra deshabilitado).
La tabla es el **default de fábrica** de los roles semilla; el Super admin (o quien tenga `roles.gestionar`)
lo ajusta.

| Sección | Permiso que la gobierna | Roles con acción por defecto | Roles solo lectura por defecto |
|---|---|---|---|
| Inicio (flota + alertas) | `inicio.ver` | — | todos los internos + Admin Empresa |
| Equipos + acciones remotas | `equipos.ver` / `equipos.accionar` | Super admin, IT, Admin Empresa | Gerencia, Dirección, Coordinación |
| Usuarios | `usuarios.gestionar` | Super admin, RRHH, IT, Admin Empresa | Gerencia, Dirección |
| Reglas / políticas | `reglas.editar` | Super admin, IT, Admin Empresa | — |
| Reportes (productividad/presencia) | `reportes.ver` | — | Super admin, Gerencia, Dirección, Coordinación, RRHH, Admin Empresa |
| Ajustes de empresa (marca, API keys) | `empresa.ajustes` | Super admin, Admin Empresa | — |
| Empresas (multi-tenant) | `tenants.gestionar` | Super admin, IT | — |
| Tiers / licencias | `tiers.gestionar` | Super admin | IT |
| Auditoría | `auditoria.ver` | Super admin, IT | Gerencia (su empresa) |
| Releases / versiones | `releases.gestionar` | Super admin, IT | — |
| Diagnóstico / control remoto avanzado | `diagnostico.ver` | Super admin, IT | — |
| Roles y permisos | `roles.gestionar` (meta) | Super admin (y a quien él lo conceda) | — |

Regla de UI: **si no tienes el permiso, la sección no aparece.** Nada de menús llenos de opciones grises.

---

## 5. Flujos clave del admin de empresa (primario)

Cada flujo: **entrada → acción primaria → guardrails → resultado visible**.

### F1 · Ver de un vistazo cómo está la flota
Entrada: Inicio (pantalla por defecto al entrar).
Muestra: nº equipos en línea / fuera, alertas críticas arriba (equipo sin conexión X días, intento de
robo, política sin aplicar). Cada alerta es **accionable** (clic → lleva al equipo/acción).
Sin jerga: "3 equipos llevan más de 7 días sin conectarse".

### F2 · Enrolar / dar de alta un equipo
Acción primaria en Equipos: **"Agregar equipo"**. Guía de 3 pasos: (1) genera código de enrolamiento,
(2) instrucción simple para el equipo, (3) confirma cuando aparece. Estado vacío enseña este flujo.

### F3 · Bloquear / recuperar un equipo (robo o incidente)  ⚠️ crítico
En el detalle del equipo, acción destacada **"Bloquear equipo"**. Al pulsar: modal con resumen
("El usuario no podrá usar el equipo hasta desbloquear. Los archivos siguen protegidos."), confirmación,
y estado claro tras la acción ("Bloqueado hace 2 min"). Desbloqueo igual de visible. Wipe/medidas
irreversibles: confirmación **escrita** (teclear el nombre del equipo).

### F4 · Aplicar reglas (bloqueo web / descargas / instalación / horario)
Sección Reglas = **una política por empresa**, en lenguaje llano y por bloques con interruptor:
- "Bloquear páginas" (lista de dominios, con ejemplos) · "Bloquear descargas" · "Bloquear instalación
  de programas" · "Horario laboral".
Cada bloque: interruptor + explicación de una línea de qué hace. Guardar = "Aplicar a todos los equipos"
con confirmación y aviso de cuándo surte efecto ("en los próximos minutos"). Alcance por defecto: toda la
empresa; opción avanzada (oculta) para excepciones por equipo/usuario.

### F5 · Gestionar usuarios y vincular a equipos
Usuarios: alta/baja, y **arrastrar/asignar** persona ↔ equipo con lenguaje claro. Baja de un empleado =
flujo guiado ("¿Qué hacer con su equipo? Liberar / reasignar").

### F6 · Ver reportes (sin acciones)
Reportes: tableros de productividad y presencia, lectura. Un número grande + tendencia + "qué significa".
Exportar en un clic. Nada de tablas crudas de 6M de filas: agregados legibles.
**El Admin Empresa ve los reportes por defecto, recortados a lo que corresponde a SU empresa**
(permiso `reportes.ver` con alcance de tenant). Nunca datos de otra empresa.

### F7 · Personalizar marca de su empresa (rebrand por tenant)
Ajustes: logo, color de acento, nombre visible. Vista previa en vivo. (El diseño base es
neomorfismo-antislop; el tenant solo cambia logo/acento vía variables CSS, no rompe la usabilidad.)

---

## 6. Mecanismos "no perderse" mapeados a pantalla

| Mecanismo | Dónde |
|---|---|
| Breadcrumb + empresa fija visible | Barra superior, todas las pantallas |
| 1 acción primaria destacada | Toda pantalla de acción (Equipos, Reglas, detalle) |
| Búsqueda global | Barra superior |
| Confirmación con "qué va a pasar" | F3 bloqueo/wipe, F4 aplicar reglas, F5 baja usuario |
| Estados vacíos que enseñan | Equipos vacío → F2; Reportes sin datos → explica |
| Deshacer / estado tras acción | Tras toda acción remota |
| Ayuda contextual en línea | Junto a cada interruptor de Reglas |

---

## 7. Qué cambia respecto al panel K3

- K3: ~16 páginas planas, todo pesa igual, lenguaje técnico, acciones sueltas → fácil perderse.
- v4: **6 secciones por tarea** para el admin de empresa, resto oculto por rol; acción primaria por
  pantalla; frontera de empresa dura; flujos guiados en lo crítico.

---

## 8. Siguiente paso

Con este spec aprobado, el rediseño de `neomorfismo-antislop/` se ajusta a esta IA (6 secciones,
barra de contexto, acción primaria por pantalla, flujos F1–F7, progressive disclosure por rol) y se
cierra el Delivery Gate anti-slop con agent-browser (render 1440/390 + foco/teclado/zoom).

Puntos resueltos:
1. ~~¿Quién aplica las reglas?~~ Es el permiso `reglas.editar`, parametrizable (§1.5).
2. ~~¿Reportes al Admin Empresa?~~ **Sí, por defecto, recortados a su empresa** (F6).
3. ~~Colaborador~~ En el modelo desde v4.0, sin login; se captura su registro completo incl. firma (§1).

Punto abierto — **parametrización por empresa (dos capas)**:
- **Capa global (Super admin):** define el catálogo maestro de permisos y los roles semilla de toda la
  plataforma. Esta capa siempre es de Super admin.
- **Capa por empresa (tenant):** ¿cada empresa puede tener sus **propios roles custom** y su propia
  asignación de permisos, gestionados por alguien de esa empresa con `roles.gestionar` (p. ej. su Gerencia),
  afectando **solo a esa empresa**? Ejemplo: ACME quiere un rol "Auditor interno" que solo ve reportes;
  su Gerencia lo crea para ACME sin tocar a otras empresas ni al catálogo global.
- **Decidido (Koichi 2026-09-16):** sí, dos capas, pero la autonomía del tenant nace **APAGADA**. El Super
  admin activa por empresa la capacidad de autogestionar roles (gate de empresa, §1.5); por defecto ninguna
  empresa puede, para evitar que hagan cosas raras. Revocable en cualquier momento.
