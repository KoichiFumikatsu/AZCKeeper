---
name: azckeeper-v4-requisitos
description: Requisitos de negocio de AZCKeeper v4 (rediseño tras descartar K4 el 2026-09-15) — control total del equipo, bloqueos, anti-robo, multi-empresa, API pública
metadata:
  type: project
---
# AZCKeeper v4 — requisitos (definidos por Koichi 2026-09-15)

Contexto: K4 (`feature/modulo-seguridad`) DESCARTADA (ver decisions.md 2026-09-15). v4 se rediseña desde cero
tras la auditoría de K3 (`docs/audits/2026-09-15-auditoria-k3.md`, Codex) y una evaluación de stack/lenguaje.

## Qué debe ser v4
- **Control total del ordenador** (equipos propiedad de la empresa, trabajo REMOTO como modalidad vigente):
  restricción a medida por equipo/usuario/empresa, administrable desde el panel sin ir al equipo.
- **Bloqueos**: políticas del SO, web (dominios), descargas, instalación de software.
- **Anti-robo / protección de archivos de clientes**: que el equipo NO sea usable si lo roban y que los datos
  de clientes queden protegidos (implica cifrado de disco + bloqueo/borrado remoto, no solo bloqueo de sesión).
- **Multi-empresa**: >1000 usuarios por empresa; cada empresa sale por UNA sola IP pública → el backend no
  puede limitar/banear por IP (hosting compartido con CSF ya lo hace: descartar).
- **Bloqueo web sin tormenta de peticiones** (problema vivo en K3).
- **Panel rebrandeable** (marca por tenant) + nuevas features.
- **API documentada** (OpenAPI) para apps externas propias y de empresas cliente.

## Implicaciones técnicas (a validar en la evaluación de stack, no decididas)
- "Control total" y anti-robo exigen componente **elevado (servicio Windows SYSTEM, tamper-protected)** y
  primitivas del SO: AppLocker/WDAC o SRP, políticas HKLM, BitLocker+TPM, control de USB/instaladores.
  Un cliente per-user sin admin (modelo K3) no puede cumplirlo — ya se demostró con URLBlocklist en HKCU.
- Evaluar lenguaje del agente (C#/.NET vs Rust vs Go) y del backend (PHP vs Go/Node/.NET) con criterios:
  peticiones/equipo, huella, firma/distribución del binario, push vs polling, tiempo de migración, mantenibilidad.

Relacionado: [[azckeeper-windows-context]] (K3 prod), [[azckeeper-k4-estado]] (histórico), [[decisions]].

## Auditoría K3 (Codex, 2026-09-15) — insumo para v4
Reporte: `docs/audits/2026-09-15-auditoria-k3.md` en el repo (1058 líneas, 856 citas archivo:línea; base `931fd12`,
suplemento `1638bd4` = rama `feature/moazc-bridge` que conserva PAC y añade `/api/external/*` para MOAZC).
Top hallazgos: (C) re-enroll da bearer con solo el GUID del device; (C) sin frontera de tenant (handshake reasigna
devices ajenos, admin muta por ID sin comprobar empresa = IDOR); (C) updater ejecuta ZIP sin firma/hash;
(A) 4,4–9 req/min/equipo (handshake reinicia update check; defaults iniciales A=10s/H=120s) → 1000 equipos
tras una IP = 73–150 req/s; (A) migraciones no reproducibles desde cero; (A) sesiones sin expiración; (A) PIN en
config y logs. **El PAC NO genera tráfico al servidor** (todo loopback); el handshake sí re-baja toda la política
sin ETag. Hipótesis abierta: política que bloquea el host de la API + ApiClient hereda proxy → auto-interferencia.
Plan: P0 auth/tenant/updater antes de multi-empresa; objetivo ~0,5 req/min/device con `/client/sync` versionado.
Gotcha operativo: el worker de Codex (plugin companion) murió a mitad de tarea sin escribir nada; se rescató con
`codex exec resume <thread-id> -c 'sandbox_mode="workspace-write"'` (NO `-s`) pidiendo escribir por secciones.

## Validación del equipo FumiWork (2026-09-15) — CONTRADICE supuestos
Koichi afirmó "todos los equipos son como FumiWork y todos tienen Windows 11 Pro". Lo medido en FumiWork:
- **Windows 11 HOME** ("Core edition", build 26200), NO Pro. → Home NO tiene AppLocker (enforcement), NO tiene
  gestión de BitLocker (solo Device Encryption si hay TPM+MS account), NO gpedit/GPO.
- **El usuario `FumiWork` ES admin local** (miembro de Administradores; junto con Administrator). UAC on
  (EnableLUA=1, ConsentPromptBehaviorAdmin=2). → si toda la flota es igual, el usuario puede deshacer controles.
- **WORKGROUP, no dominio** → sin GPO ni AD/Intune por dominio; despliegue del MSI debe ser manual/RMM por equipo.
- TPM/BitLocker no legibles sin elevación (Access denied); manage-bde presente (v10.0.26100).
PENDIENTE: confirmar contra la FLOTA real, no solo el equipo de Koichi (que como IT lead puede ser atípico).
Vía: `keeper_devices`/specs en prod (migración device_specs) reporta edición de Windows por equipo — cruzar ahí.
Implicación v4: el enforcement fuerte (AppLocker, BitLocker gestionado) exige **Pro/Enterprise**; en Home solo
queda WDAC vía CSP/MDM + políticas de navegador HKLM + usuario estándar. Decidir: subir flota a Pro o rediseñar
el enforcement para Home. WDAC (Application Control) SÍ funciona en Home vía MDM/CSP, AppLocker NO.

## Diseño panel v4 — dirección (2026-09-15)
Codex generó propuestas en `docs/design/2026-09-15-propuestas/` (10 páginas HTML estáticas c/u: login,index,devices,
device,policies,users,settings,states). ELEGIDO FINAL (2026-09-16): **neomorfismo-antislop** (variante del neomorfismo pasada por las 38 reglas anti-slop; relieve selectivo, foco en equipos). Antes finalistas: neomorfismo y minimal-lineas. DESCARTADAS:
brutalista, terminal, editorial, industrial, flat-corporativo, material-claro, glassmorphism-claro (estas 3 últimas
Koichi las llamó GENÉRICAS = defaults de IA). Reglas de marca firmes: **SIEMPRE fondo CLARO, nada dark/gaming/neón/
HUD/terminal** (entorno empresarial, personal administrativo); y **no aceptar los defaults genéricos** (SaaS/Material/
glass tipo Stripe/Linear/Bootstrap) — exige dirección de arte real con motivo visual y sistema tipográfico propios.
Ronda extra pedida: editorial-premium, bauhaus-geometrico, blueprint-tecnico, humanista-calido, swiss-datos.
Codex design thread reutilizable: session-id `01a0a6cf-2ef1-7072-8aac-fb4c3062185a` (resume con
`codex exec resume <id> -c 'sandbox_mode="workspace-write"'`). Gotcha: la validación visual Chromium falla en el
sandbox de Codex (`spawn EPERM`); el render real hay que verlo abriendo los HTML localmente.

## Panel v4 — diseño elegido + usabilidad (2026-09-16)
Diseño ELEGIDO: **`neomorfismo-antislop/`** en `docs/design/2026-09-15-propuestas/`. Requisito explícito de Koichi:
la app debe ser **SUPER usable, "que un tonto pueda usarla sin perderse"**. La usabilidad se desarrolla junto con
los requisitos (IA por rol, task-first, flujos guiados para acciones críticas/destructivas, no perderse).
Delivery Gate anti-slop de esa variante quedó FAIL solo por R-03/R-32/R-35 (Codex no pudo renderizar: Chromium
`spawn EPERM`); pendiente cerrar con agent-browser (render 1440/390 + foco/teclado/zoom).

## RBAC parametrizable v4 (2026-09-16, decisión Koichi)
Regla transversal: **si algo existe, debe ser parametrizable** (roles, permisos, políticas, catálogos, tiers,
marca, horarios) — nada hardcodeado; se edita desde el panel sin release. Roles = datos (crear custom,
clonar, desactivar); permisos = matriz granular (`equipos.bloquear`, `reglas.editar`, `roles.gestionar`…);
un rol = permisos + alcance (empresa/área/sede/propio). Meta-permiso `roles.gestionar` SOLO lo concede el
Super admin; quien lo recibe (p. ej. Gerencia) parametriza SOLO su empresa, sin auto-escalada (no otorgar lo
que no se tiene). Todo cambio a auditoría. Roles semilla (jerarquía): Super admin, Gerencia, Dirección,
Coordinación, IT, RRHH, Admin Empresa, Colaborador. **Colaborador** existe en el modelo desde v4.0 pero SIN login aún; se captura su REGISTRO COMPLETO incl. firma/tenant (obligatoria), area, sede, cargo, horario (editable por el a futuro); modelo extensible. Reservado para autoservicio (registrar actividades / modificar horarios). Reportes: Admin Empresa los ve por defecto recortados a SU empresa. Consistente con
K4 que ya tenía RBAC editable en BD. Detalle en `docs/design/2026-09-15-propuestas/USABILIDAD-IA-v4.md`.

## RBAC dos capas — gate de empresa OFF por defecto (2026-09-16)
Capa global (Super admin: catálogo maestro de permisos + roles semilla) + capa por empresa (roles custom del
tenant). PERO la autonomía del tenant nace APAGADA: dos gates para `roles.gestionar` — (1) gate de empresa
= capability del tenant que SOLO el Super admin activa por empresa (OFF por defecto; sin ella los roles de esa
empresa los maneja solo AZC); (2) gate de rol = dentro de una empresa habilitada, se asigna `roles.gestionar`
a un rol (p. ej. Gerencia) que parametriza SOLO su empresa, sin auto-escalada. Super admin puede revocar el
gate de empresa cuando quiera. Objetivo de Koichi: evitar que las empresas "hagan cosas raras".

## panel-v4 construido + validado (2026-09-16)
`docs/design/2026-09-15-propuestas/panel-v4/` = versión DEFINITIVA del panel (look neomorfismo-antislop + IA/roles
del spec USABILIDAD-IA-v4.md). 11 páginas (login,index,equipos,equipo,usuarios,reglas,reportes,ajustes,roles,
empresas,states) + styles.css + app.js. Implementa: nav 6 secciones Admin Empresa, barra de contexto con empresa,
acción primaria por pantalla, guardrails destructivos (wipe = confirmación escrita), progressive disclosure por
permiso, los DOS gates de roles (gate empresa OFF por defecto solo Super admin + meta `roles.gestionar`).
Delivery Gate cerrado con agent-browser (Codex no puede renderizar): reflow @390 sin desborde, foco teclado
visible 3px, contraste 5.24:1 → PASS. Menores pendientes: (1) alt/aria-label en link del logo, (2) @media
prefers-reduced-motion. Validación en panel-v4/VALIDACION.md; contact-sheet de capturas en scratchpad.

## Referencia de cara al cliente: LawyerDesk client-portal (2026-09-16)
URL demo: https://www.lawyerdesk.com/client-portal → "Explore the interactive demo". Es NUESTRA página (demo
que el gerente presenta al cliente). El resultado final NO será idéntico, PERO se están PROMETIENDO features de
ahí: suscripción por TIERS (badges por persona: Essential/Pro/Business = "LDKeeper <tier>"), GRÁFICAS de
productividad, y dashboard por miembro. El Admin Empresa (cliente) debe ver algo VISTOSO con gráficos.
Secciones de la demo (sidebar): Command Center (dashboard: KPIs staff online/horas/productividad+focus/ahorro,
line "Weekly output", donut "Workload by practice", tiles open tasks/active cases/AI leads, Live activity feed,
Today's roster con barras de utilización), My Team (sub-tabs: Team Dashboard con ranking+gráficas, Team Members
lista con tier/rol/login/%prod/focus, Time Off, Business Rules & PTO), **My Team→miembro = dashboard por persona**
(pestañas Overview [equipo asignado+specs, check-in history con GPS, 7-day trend, Focus Score "Focus Guard" gauge,
Productivity donut], Activity & Logs, HR & Time Off, Access & Devices), Tasks & Delegation, Case Outsourcing,
BL+AI Reception, Messages, Scheduling & Shifts, Billing & Savings (KPIs balance/ahorro/W-9, bar "Cost comparison"
US in-house vs LawyerDesk, invoices), Hire Talent, Support. Tema claro + toggle dark. Capturas en scratchpad/lawyerdesk.
MAPEO a Keeper: Focus/productividad = window_episode; Access&Devices = control de equipos; check-in GPS = puertas
(uhppoted)+location; tiers = suscripción por miembro. Las secciones de STAFFING (Case Outsourcing, Hire Talent,
BL+AI Reception, Tasks, Messages, Billing-vs-US) son del negocio LawyerDesk, NO de Keeper — Keeper es el MOTOR de
la parte de monitoreo/dispositivos/productividad. Definir qué entra en panel-v4 (que es la vista del cliente) ahora
vs después. Charts: hacer inline SVG (regla sin CDN); aplicar dataviz + antislop-ui.

## Arquitectura de frontends — DOS SEPARADOS (decisión Koichi 2026-09-16)
- **panel-v4** = panel de ADMINISTRACIÓN de Keeper (uso interno AZC + admin de empresa/tenant). Lleva gráficos y
  dashboard por miembro igual (Koichi los pidió), pero NO es el portal del cliente final.
- **Portal cliente (estilo LawyerDesk)** = frontend SEPARADO que consume Keeper por **API**. App aparte, su propio
  código/branding. No se mantiene un solo frontend.
- CONSECUENCIA CLAVE: la **API documentada de Keeper es EL CONTRATO** entre el motor y el portal LawyerDesk (y
  otras apps de terceros). Sube de prioridad: OpenAPI, auth por API key/OAuth por empresa, scopes read, versionado,
  rate limit por device/tenant. El portal LawyerDesk vistoso es un proyecto futuro que se alimenta de esa API.
- ALCANCE panel-v4: núcleo Keeper AHORA (dashboard+charts, My Team, miembro, equipos, reglas, reportes, roles) +
  contemplar como PLACEHOLDER (IA/nav, desarrollo posterior): **Scheduling & Shifts**, **Tiers/suscripción (gestión)**,
  **Messages/notificaciones**. Fuera: Case Outsourcing, Hire Talent, BL+AI Reception, Tasks, Billing-vs-US (staffing LawyerDesk).

## CORRECCIÓN arquitectura (2026-09-16, SUPERSEDE "dos frontends separados")
Decisión final de Koichi: **TODO EN KEEPER, API-FIRST**.
- Un solo sistema: Keeper (panel-v4 = el panel completo). Se construye todo dentro de Keeper.
- API-first: exponer TODAS las APIs desde ya, para que cuando se construya el PORTAL CLIENTE (estilo LawyerDesk,
  futuro) solo se "cablee" a esas APIs. No se construye el portal cliente ahora; se deja el terreno listo
  (endpoints + OpenAPI + auth por tenant/API-key + scopes read + versionado + rate-limit por device/tenant).
- Alcance panel-v4: TENER TODO aunque sea PLACEHOLDER, en ESTE ORDEN: (1) NÚCLEO primero (dashboard+charts,
  My Team, miembro, equipos, reglas, reportes, roles); (2) luego placeholders navegables: Scheduling & Shifts,
  Tiers/suscripción, Messages/notificaciones y demás secciones prometidas. Núcleo antes que placeholders.

## panel-v4 COMPLETO (2026-09-16) — 20 páginas
`docs/design/2026-09-15-propuestas/panel-v4/`. NÚCLEO funcional: login, index (dashboard vistoso con charts SVG
inline: KPIs, salida semanal, donut carga, roster, actividad), equipos, equipo, usuarios, **miembro** (dashboard
por persona: Tier badge, 4 pestañas Resumen/Actividad/RRHH/Acceso-y-dispositivos, equipo asignado, check-in,
tendencia 7d, Focus Score gauge, productividad donut), reglas, reportes (charts), roles (RBAC 2 gates), empresas
(super admin), ajustes (rebrand tenant), states. PLACEHOLDERS navegables "En desarrollo": grupo Próximamente
(turnos, tiers, mensajes, soporte) + grupo Portal cliente futuro (tareas, casos, recepcion, contratar).
Charts = SVG inline (sin CDN). Validado con agent-browser: reflow @390 ok, foco 3px, contraste 5.24. Pendientes
menores: (1) alt/aria-label logo, (2) @media prefers-reduced-motion, (3) cortar línea "salida semanal" en día actual
(no bajar a 0). Diseño de panel v4 = CERRADO como referencia. Próximo gran frente: API-first (OpenAPI + auth tenant)
y el cliente/agente SYSTEM.
