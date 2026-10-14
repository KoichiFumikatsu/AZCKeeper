# Superadmin por tareas: diagnóstico y propuesta (pasos 1 y 2)

Fecha: 2026-10-14. Encargo: pasos 1 (recorrido como novato, con capturas) y 2 (reorganizar por tareas en lenguaje llano) del método de la [arquitectura del panel](2026-10-13-arquitectura-panel-keeper.md), sección «Superadministrador: mismo poder, sin confusión». El paso 3 (validar con Koichi) va antes de tocar código. Este documento no cambia código del panel ni despliega nada.

**Cómo se hizo.** Se sirvió `backend-v4/public` con `php -S` y el router `index.php`. La API `/v1` se simuló en Playwright con el fixture del repo (`backend-v4/tests/k3-panel-fixture.cjs`) más datos **ficticios** para las pantallas de plataforma: 3 clientes, el catálogo de 23 funciones leído de `src/Features.php`, 2 paquetes, 3 planes por persona, 55 permisos leídos de `migrations/0024_panel_accounts.sql`, 5 roles, 2 cuentas, ajustes de Windows, PIN y Portal. Todas las capturas son de 1366×768, sesión de superadmin de plataforma; las de página completa se tomaron con `fullPage`. Las rutas `archivo:línea` son relativas a `backend-v4/public/` salvo que se indique otra cosa.

**Límites de la evidencia.**
- [Seguro] Las etiquetas, el orden del menú, los diálogos y los defaults salen del código servido y se ven en las capturas.
- [Suposición] Las cifras (personas por plan, equipos) son inventadas. La captura 22 marca «Endurecimiento: desconocido» porque el arnés no simula `/admin/devices/{id}/hardening`; no es un fallo del panel.
- [Probable] Los conteos de clics salen del código y del recorrido. Cuentan desde Inicio y no incluyen escribir en los campos.
- No se capturó Clasificación: el arnés no reproduce la forma de `/app-classification`.

---

## 0. Lo más grave primero

1. **«Empresa» significa tres cosas distintas.** En el menú de plataforma se llama «Empresas» a los **clientes** (`partials/sidebar.php:21`, `empresas.php:3`). En Organización, «Empresas del grupo» son las **empresas internas** de un cliente (`assets/k3.js:20`, `assets/k3.js:229`). El selector de arriba dice «Empresa» y lista clientes (`partials/layout-k3.php:37`). Para rematar, el botón «Crear empresa» existe con los dos sentidos (`assets/k3.js:1407` para un cliente, `assets/k3.js:229` para una empresa interna). La arquitectura aprobada ya fijó el vocabulario **Cliente → Empresas → personas** (`docs/architecture/v4-clientes-funciones-accesos.md` §5), pero el panel no lo aplica.
2. **El grupo Plataforma está al fondo de un menú de 30 entradas.** El superadmin ve primero 4 entradas de «Principal» y 19 de «Administración», que son las del cliente. «Plataforma» empieza a 1284 px de altura en una pantalla de 768: nunca aparece sin desplazar la barra lateral (capturas 01 y 02).
3. **«Crear cuenta» propone por defecto crear un superadmin.** En Administradores → Crear cuenta, la primera opción, ya seleccionada, es «AZC Keeper (dueña) · superadmin de todo» (`assets/k3.js:1057`, captura 16). Un novato que quiere el administrador del cliente puede crear otro superadmin con acceso a todos los clientes.
4. **El nombre del menú no coincide con el título de la pantalla en 5 casos** (tabla en §1.2). El novato hace clic en «Versiones y mudanzas» y llega a una página titulada «Releases».
5. **Lo que protege un equipo está repartido en 5 lugares** y ninguno muestra el resultado:
   - qué compró el cliente: diálogo Funciones en Empresas;
   - el plan por defecto: al fondo de ese mismo diálogo;
   - el plan de la persona: su ficha → Datos y acceso;
   - las reglas: Reglas de apps y sitios;
   - la cuenta de IT, el PIN y Smart App Control: Ajustes, cuyo subtítulo solo habla de «Plazos de retención» (`ajustes.php:3`).

   La ficha del equipo muestra el nombre técnico «WebEnforcer» en vez de «Bloqueo web» (`assets/app.js:497`, captura 22).

---

## 1. Recorrido como novato

### 1.1 Contexto que el novato no ve

- [Seguro] **Hay tres formas de elegir el cliente**, según la pantalla:
  - Pantallas K3 (Personas, Ajustes, Administradores…): selector «Empresa» arriba (`partials/layout-k3.php:37`, captura 03).
  - Pantallas antiguas con `layout.php` (Registrar equipos nuevos, Equipos, Ficha del equipo, Releases): selector «Empresa» dentro de la página (capturas 18, 22, 26; en Releases, `assets/fleet.js:96`).
  - Pantallas «globales» (Empresas, Planes, Portal): sin selector; la barra dice «AZC Keeper · Administración de la plataforma» (`assets/k3.js:4266`, captura 05).
- [Seguro] **El Resumen de plataforma** (`/admin/`, captura 04) dice «Tenants accesibles», «Equipos enrolados», «Releases publicados» y «Totales de los tenants accesibles, incluido el tenant reservado de plataforma» (`assets/app.js:316`, `assets/app.js:325-328`). Son palabras de base de datos.
- [Seguro] **Hay entradas que se parecen.** «Organización: firmas, sedes, áreas y cargos» es el catálogo y «Sedes» es un tablero para comparar sedes (`partials/sidebar.php:14`). El novato no puede saber cuál edita sedes.

### 1.2 Menú contra título de la pantalla

| Menú (`partials/sidebar.php`) | Título al llegar | Archivo |
|---|---|---|
| Funciones y planes (:22) | Planes y paquetes | `planes.php:3` |
| Integraciones (:22) | Fuentes de datos | `fuentes.php:3` |
| Versiones y mudanzas (:23) | Releases | `admin/releases.php:3` |
| Roles y visibilidad de módulos (:15) | Roles y permisos | `roles.php:3` |
| Administradores (:15) | Administradores, que incluye «Acceso al Portal» | `cuentas.php:3`, `assets/k3.js:950` |

### 1.3 Las 12 tareas

Columnas: **Clics** = clics mínimos hoy, contados desde Inicio (scroll aparte). **Pantallas** = páginas más diálogos.

| # | Tarea | Clics | Pantallas | Dónde se pierde | Términos que no entiende (etiqueta exacta, archivo:línea) |
|---|---|---|---|---|---|
| T1 | Dar de alta un cliente nuevo | 3, más desplazar el menú | 1 página + 2 diálogos (06, 07) | Busca «Clientes» y no existe; está en «Empresas», bajo Plataforma y fuera de vista. Crear pide paquete pero **no plan por defecto**. Los «siguientes pasos» (07) no incluyen «revisar funciones y plan». | «Crear empresa» (`k3.js:1407`), «Paquete» (`k3.js:1414`), «Roles propios: Los gestiona la plataforma» (`k3.js:1198`), «Grupo empresarial» (`k3.js:1195`) |
| T2 | Elegir qué funciones compró (venderle) | 3, más desplazar el diálogo | 1 + 1 diálogo largo (08, 09) | El botón «Funciones» es el tercer enlace de la columna Acciones. El diálogo tiene 23 casillas en 6 grupos. El **plan por defecto queda al final**, tras desplazarse (09). Si se deja vacío, el aviso dice que los equipos no reciben reglas (`k3.js:1291`). | «Paquete de origen» (`k3.js:1285`), «Por persona / Del cliente», «Pendiente de implementación», «Plan por defecto de sus personas» (`k3.js:1289`) |
| T3 | Definir planes de personas y asignarlos | Crear plan: 5. Asignarlo a una persona: 6 | Planes: 1 + diálogo con revisión (13, 14). Persona: 1 página larga (30) | El menú dice «Funciones y planes» y la página «Planes y paquetes». Hay que entender 4 pestañas y la cadena «Funciones → Paquete → Funciones del cliente → Plan → Lo que recibe» (10). Para asignar: cambiar el cliente arriba → Personas → persona → pestaña «Datos y acceso» → bajar hasta «Plan por persona» → «Guardar plan». | «Etiqueta» (en planes, `k3.js:3378`), «Planes por persona», «Heredado del cliente», «Ver los planes (tiers)» (`migrations/0024_panel_accounts.sql:58`) |
| T4 | Crear el primer administrador del cliente | 8–10 | 3 páginas: persona, cuenta, acceso; 3 guardados separados (15, 16, 30) | Hay que crear la persona, luego la cuenta, y luego el rol y el alcance **en otra sección** de la ficha («Guardar acceso»). Desde Administradores → Crear cuenta, el default es superadmin (`k3.js:1057`). | «Cuenta del panel», «Acceso al panel», «Alcance (qué datos ve)», «Sin acceso: no verá nada», «Tu contraseña (confirmación)» |
| T5 | Generar el instalador | 2, más la contraseña | 1 (18) | El selector del cliente está dentro de la página, no arriba como en el resto. La misma pantalla lleva «Solicitudes», «Equipos esperados» y «Configuración» con claves y reglas de aprobación: demasiado para la tarea de descargar. | «Registrar equipos nuevos» (menú) frente a «Alta», «Claves de alta», «enrolado», «Equipos esperados», «Endurecimiento: desde el panel, equipo por equipo» |
| T6 | Poner reglas web y USB | 3 abrir + 4 pasos del asistente | 1 + asistente (19, 20) | El asistente está bien guiado. Pero si el cliente no compró «web» o «usb», o el plan de la persona no lo incluye, solo se avisa al final y de forma genérica: «Activa no significa aplicada…» (`k3.js:3134`). No dice a cuántas personas no les llegará. | «Sin regla aquí (se usa la general)», «A quién aplica», «Cuándo y excepciones» |
| T7 | Ver que un equipo quedó protegido | 2–3 | 2 (21, 22) | La ficha mezcla actualizaciones, comandos, cambiar nombre, controles e historial. No hay una línea que diga «protegido: sí/no» y por qué. «Puesta en marcha» paso 8 (17) es lo más cercano, pero vive en otra pantalla. | «WebEnforcer» (`app.js:497`), «Keeper 3 (K3)», «Endurecimiento: desconocido», «Bloqueo: desconocido» |
| T8 | Cambiar la clave de IT o el PIN | 3, más reautenticación | 1 larga (23) | Nada en el menú sugiere «Ajustes» para esto; su subtítulo habla de retención (`ajustes.php:3`). Hay que bajar tras 4 campos de retención. Son 3 cosas de seguridad de Windows en 3 tarjetas separadas, con nombres técnicos. | «Seguridad de cuentas de Windows» (`k3.js:3764`), «Cuenta de IT», «PIN de desbloqueo local» (`k3.js:3778`), «Smart App Control» (`k3.js:3779`) |
| T9 | Ver la salud del servidor | 1, más desplazar el menú | 1 (24) | Bien resuelta, pero es la penúltima entrada del menú, fuera de vista. | «Días por recalcular», «Reglas por recalcular», «Versión del cálculo» |
| T10 | Autorizar una dirección del Portal | 2, más desplazar el menú | 1 (25) | Es la última entrada: ni a 1650 px de alto se ve sin desplazar (02). En Administradores existe otro «Acceso al Portal» por persona, descrito como «Permiso guardado para el futuro Portal» (`k3.js:950`): dos cosas distintas con el mismo nombre. | «Portal: direcciones autorizadas», «Dirección… sin ruta, sin barra final y sin *» |
| T11 | Publicar una versión a prueba | 3 | 1 (26) | La página se llama «Releases» y mezcla publicar versiones con «Mudanzas de servidor» (orden firmada, JWS). El anillo se elige por cliente, uno a la vez. | «Releases», «Publicar versión por anillo» (`fleet.js:91`), «Canal: stable», «Agente mínimo», «Orden firmada (… sign-relocation.ps1, o solo el JWS)» (`relocations.js:21`), «modo mudado» |
| T12 | Definir qué puede hacer un rol | 3 | 1 + diálogo con 55 casillas (28, 29) | El menú dice «Roles y visibilidad de módulos». El diálogo, abierto por un superadmin, muestra permisos «Solo plataforma» mezclados con los del cliente (`k3.js:4204`), por ejemplo «Catálogo de permisos». Quién gestiona los roles del cliente depende de dos interruptores distintos (ver §1.4). | «Catálogo de permisos», «Solo plataforma», «Se puede dar automáticamente (por cargo o jefatura…)», «Visibilidad de módulos» |

Lo que sí funciona y debe conservarse:
- **Puesta en marcha** (captura 17) ya está organizada por tareas: 8 pasos con estado, el motivo y un botón por paso.
- El asistente de **Crear regla** (20) tiene 4 pasos y revisa el efecto antes de guardar.
- **Planes** revisa el cambio antes de guardar (`reviewStep`).
- **Ajustes** muestra «Antes → Después» antes de reducir la retención.

La propuesta los convierte en el patrón, no los reemplaza.

### 1.4 Dos interruptores para una misma idea

- [Seguro] En «Editar y marca» de un cliente está la casilla «La empresa gestiona sus propios roles y cuentas» (`k3.js:1373`, `rbac_self_management`).
- [Seguro] En «Funciones» está la función vendible «El cliente gestiona sus roles y cuentas» (`autogestion_accesos`), que el menú usa para ocultar Administradores y Roles (`assets/nav-access.js:18`).
- [Probable] Son dos llaves para lo mismo. Un novato puede encender una y no la otra, y no sabrá por qué el cliente no ve sus roles. **Hace falta una decisión de Koichi** (pregunta Q3 en §4).

### 1.5 Capturas

Están en [`2026-10-14-capturas-superadmin/`](2026-10-14-capturas-superadmin/):

| Captura | Qué muestra |
|---|---|
| [01](2026-10-14-capturas-superadmin/01-menu-lateral-inicio.png) | Menú a 768 px: no se ve Plataforma |
| [02](2026-10-14-capturas-superadmin/02-menu-lateral-completo.png) | Menú completo a 1650 px de alto: Salud y Portal siguen debajo |
| [03](2026-10-14-capturas-superadmin/03-selector-empresa.png) | Selector «Empresa» = cliente |
| [04](2026-10-14-capturas-superadmin/04-resumen-plataforma.png) | Resumen con «Tenants», «enrolados», «Releases» |
| [05](2026-10-14-capturas-superadmin/05-empresas.png), [06](2026-10-14-capturas-superadmin/06-crear-empresa.png), [07](2026-10-14-capturas-superadmin/07-empresa-creada-siguientes-pasos.png) | T1 |
| [08](2026-10-14-capturas-superadmin/08-funciones-del-cliente.png), [09](2026-10-14-capturas-superadmin/09-funciones-del-cliente-final.png) | T2 |
| [10](2026-10-14-capturas-superadmin/10-planes-como-funciona.png)–[14](2026-10-14-capturas-superadmin/14-crear-plan-por-persona.png) | T3 |
| [15](2026-10-14-capturas-superadmin/15-administradores.png), [16](2026-10-14-capturas-superadmin/16-crear-cuenta.png), [30](2026-10-14-capturas-superadmin/30-persona-datos-y-acceso.png) | T4 |
| [17](2026-10-14-capturas-superadmin/17-puesta-en-marcha.png) | Puesta en marcha |
| [18](2026-10-14-capturas-superadmin/18-registrar-equipos.png) | T5 |
| [19](2026-10-14-capturas-superadmin/19-reglas.png), [20](2026-10-14-capturas-superadmin/20-crear-regla.png) | T6 |
| [21](2026-10-14-capturas-superadmin/21-equipos.png), [22](2026-10-14-capturas-superadmin/22-ficha-equipo.png) | T7 |
| [23](2026-10-14-capturas-superadmin/23-ajustes.png) | T8 |
| [24](2026-10-14-capturas-superadmin/24-salud-servidor.png) | T9 |
| [25](2026-10-14-capturas-superadmin/25-portal-direcciones.png) | T10 |
| [26](2026-10-14-capturas-superadmin/26-versiones.png) | T11 |
| [27](2026-10-14-capturas-superadmin/27-integraciones-fuentes.png) | Fuentes de datos |
| [28](2026-10-14-capturas-superadmin/28-roles.png), [29](2026-10-14-capturas-superadmin/29-crear-rol.png) | T12 |
| [31](2026-10-14-capturas-superadmin/31-organizacion.png) | Organización |

---

## 2. Propuesta: menú y pantallas por tareas

Principios:
- **Un solo contexto visible**: una barra fija «Trabajando en: [Cliente ▾]» en todas las pantallas de cliente, en los dos layouts.
- **Plataforma arriba** para el superadmin.
- **Cada entrada del menú es una tarea** y su título es idéntico a la entrada.
- **Lo técnico va en «Avanzado»**: una sección plegada dentro de cada pantalla, no otra pantalla. Nada se borra.

### 2.1 Árbol del menú (superadmin)

```
PLATAFORMA (arriba, plegable)
  Inicio de plataforma            ← Resumen de plataforma + Salud del servidor (resumen en tarjetas)
  Clientes                        ← «Empresas» (lista) → abre la Ficha del cliente
    + Dar de alta un cliente      ← asistente (nuevo, reutiliza Puesta en marcha)
  Qué vendemos                    ← «Funciones y planes» / «Planes y paquetes»
      Pestañas: Planes de personas · Paquetes · Catálogo de funciones · Cómo funciona
  Versiones del agente            ← «Versiones y mudanzas» / «Releases» (publicar a prueba / a todos)
      Avanzado: Mudanzas de servidor
  Salud del servidor              ← igual
  Conexiones                      ← «Portal: direcciones autorizadas» + «Integraciones / Fuentes de datos» (globales)
      Avanzado: Catálogo de permisos (solo plataforma), Grupos empresariales

TRABAJANDO EN: [Cliente ▾]  (barra fija; arriba de todo el bloque de cliente)
  Inicio                          ← igual
  Personas                        ← igual (+ «Cargos que no se monitorean» en Avanzado)
  Equipos
      Pestañas: Equipos · Instalar Keeper · Solicitudes de equipos · Asignación · Cobertura
      Avanzado: Claves de alta manuales, reglas de aprobación, Logs técnicos
  Productividad                   ← Productividad + «Comparar sedes» (antes «Sedes»)
  Alertas                         ← Doble empleo + Apps sospechosas
  Seguridad de los equipos        ← NUEVA agrupación
      Pestañas: Resumen de protección · Reglas de apps, sitios y USB · Cuenta de IT y PIN · Smart App Control
  Jornada                         ← Horarios · Festivos · Ausencias · Puertas
  Estructura del cliente          ← «Organización: firmas, sedes, áreas y cargos»
      Pestañas: Empresas internas · Sedes · Áreas · Cargos   (Firmas → Avanzado mientras tengan registros)
  Accesos                         ← «Administradores» + «Roles y visibilidad de módulos»
      Pestañas: Administradores · Roles · Asignación automática · Acceso al Portal
  Clasificación de apps           ← igual (en Avanzado del menú si el cliente no la compró)
  Ajustes del cliente             ← Conservación de datos, aviso de Cobertura, Fuentes de datos (AZC One)
      Avanzado: Auditoría, Aplicar la retención
  Puesta en marcha                ← igual; se muestra arriba mientras no esté cerrada
```

Resultado: el superadmin pasa de 30 entradas sueltas a 6 de plataforma y 12 de cliente, con Plataforma visible sin desplazar. Para un administrador de cliente el bloque Plataforma no aparece (igual que hoy, `nav-access.js:29`).

### 2.2 Pantallas guiadas nuevas o rehechas

1. **Ficha del cliente.** Ya aprobada en `v4-clientes-funciones-accesos.md` §5; hoy es una tabla con 4–5 enlaces por fila y 3 diálogos. Pestañas:
   - **Resumen**: estado, personas por plan, equipos, avance de la puesta en marcha.
   - **Qué compró**: paquete, funciones, plan por defecto, todo visible sin diálogo.
   - **Marca**.
   - **Administradores**.
   - **Avanzado**: zona horaria, grupo empresarial, quién gestiona los roles, archivar y eliminar.
2. **Asistente «Dar de alta un cliente».** Cada paso trae un valor por defecto y una línea que lo explica:
   1. Nombre y zona.
   2. Paquete; marca funciones y deja ajustar.
   3. Plan por defecto, preseleccionado según el paquete.
   4. Primer administrador: persona + cuenta + rol «Corporativo» + alcance «Todo el cliente» en un paso.
   5. Descargar instalador.
   6. Seguir en Puesta en marcha.
3. **Seguridad de los equipos → Resumen de protección.** Una tabla: control (en palabras) × ¿lo compró? × ¿qué planes lo incluyen? × personas a las que llega × equipos que lo reportan aplicado. Responde «¿quedó protegido?» sin cruzar 5 pantallas.
4. **Ficha del equipo.** Una primera línea «Protección: aplicada / parcial / sin controles» con el motivo; los controles con nombre legible (reusar `LOG_COMPONENTS`, `k3.js:3528`). Cambiar nombre, historial y logs pasan a Avanzado.
5. **Versiones del agente.** Dos botones de tarea: «Probar en algunos equipos» (pruebas, %) y «Publicar para todos» (automáticas). Arquitectura, agente mínimo y canal pasan a Avanzado; Mudanzas, a Avanzado con su propio aviso.

### 2.3 Diccionario: término actual → término llano

| Hoy | Propuesta |
|---|---|
| Empresas (plataforma), tenant, Crear empresa | **Clientes**, **Dar de alta un cliente** |
| Empresa (selector de arriba) | **Cliente** |
| Empresas del grupo | **Empresas internas** (de este cliente) |
| Firma | Avanzado: «Firma (heredado)» mientras tenga registros |
| Releases, Releases publicados | **Versiones del agente** |
| Publicar versión por anillo · Primero (pruebas) · Automáticas | **Probar en algunos equipos** · **Publicar para todos** |
| Equipos enrolados, enrolar, alta | **Equipos registrados**, **registrar** |
| Funciones y planes / Planes y paquetes | **Qué vendemos** |
| Paquete de origen | **Paquete con que se vendió** |
| Roles y visibilidad de módulos / Roles y permisos | **Accesos → Roles** |
| Seguridad de cuentas de Windows / Cuenta de IT / endurecimiento | **Cuenta de administrador de IT en los equipos** (y «quitar admin local al usuario») |
| WebEnforcer, UsbEnforcer… | **Bloqueo web**, **USB**… |
| Ver los planes (tiers) | **Ver los planes de personas** |
| Integraciones / Fuentes de datos | **Conexiones** (plataforma) y **Fuentes de datos** (cliente) |

### 2.4 Parámetro actual → dónde queda

Ninguna parametrización desaparece. «Av.» = sección plegada «Avanzado» dentro de esa pantalla.

| Parámetro actual (pantalla de hoy) | Dónde queda |
|---|---|
| Crear empresa: nombre, zona horaria, paquete (Empresas) | Asistente Dar de alta un cliente, pasos 1–2 |
| Nombre interno, nombre visible, logo, color principal, color de acento (Editar y marca) | Ficha del cliente → Marca |
| Marca del producto, cliente dueño (Editar y marca de AZC Keeper) | Plataforma → Inicio de plataforma → Av. «Marca del producto» |
| Estado activa/suspendida (Editar y marca) | Ficha del cliente → Resumen (botón Suspender) |
| Zona horaria del cliente | Ficha del cliente → Av. |
| Pertenece al grupo empresarial; crear, editar o eliminar grupos (Empresas) | Ficha del cliente → Av.; lista de grupos en Conexiones → Av. Grupos empresariales |
| «La empresa gestiona sus propios roles y cuentas» (`k3.js:1373`) | Ficha del cliente → Qué compró, junto a «El cliente gestiona sus roles y cuentas», o fusionado (Q3) |
| Archivar, desarchivar, eliminar definitivamente, mostrar archivadas | Clientes (filtro) y Ficha del cliente → Av. |
| Ver cuentas (Empresas) | Ficha del cliente → Administradores |
| Paquete de origen, 23 funciones, plan por defecto (diálogo Funciones) | Ficha del cliente → Qué compró (página, no diálogo; plan por defecto arriba) |
| Planes en uso (conteo) | Ficha del cliente → Resumen |
| Catálogo de funciones (lectura) | Qué vendemos → Catálogo de funciones |
| Paquetes: nombre, funciones | Qué vendemos → Paquetes |
| Planes por persona: nombre, etiqueta, funciones, activo | Qué vendemos → Planes de personas |
| Plan de una persona (ficha → Datos y acceso) | Ficha de la persona → pestaña propia «Plan y acceso» |
| Rol y alcance de una persona; contraseña del panel/Portal | Ficha de la persona → «Plan y acceso» |
| Crear cuenta: cliente, correo, nombre, confirmación (Administradores) | Accesos → Administradores; cliente fijado por el contexto; superadmin solo en Plataforma → Av. |
| Activar cuenta, restablecer contraseña, cuenta de grupo | Accesos → Administradores |
| Acceso al Portal por persona (Administradores) | Accesos → Acceso al Portal |
| Roles: nombre, permisos, activo, «se puede dar automáticamente» | Accesos → Roles (permisos «Solo plataforma» en Av.) |
| Asignación automática (reglas por cargo o jefatura) | Accesos → Asignación automática |
| Editar el catálogo de permisos (plataforma) | Conexiones → Av. Catálogo de permisos |
| Cargos que no se monitorean (`k3.js:1573`) | Personas → Av. |
| Persona: datos, jefe, monitoreo, empresa interna, sede, área, cargo, horario, festivos, estado laboral | Ficha de la persona → Datos (monitoreo y firma en Av.) |
| Descargar instalador | Equipos → Instalar Keeper, y paso 5 del asistente |
| Reglas de aprobación (3 casillas), claves manuales, revocar clave | Equipos → Instalar Keeper → Av. |
| Solicitudes: aprobar, rechazar | Equipos → Solicitudes de equipos |
| Equipos esperados: agregar, importar, cancelar | Equipos → Instalar Keeper («Preparar a las personas») |
| Asignación de equipos | Equipos → Asignación |
| Cobertura: exento, nota; días sin conexión para alertar | Equipos → Cobertura (el ajuste de días va a su Av.) |
| Actualizaciones del equipo (anillo, motivo de pausa) | Ficha del equipo → Actualizaciones |
| Bloquear, desbloquear, reiniciar, apagar, borrado remoto | Ficha del equipo → Acciones (borrado en zona de peligro, igual) |
| Cambiar nombre del equipo, historial, registro del agente | Ficha del equipo → Av. |
| Reglas: nombre, sitios, descargas, instalar programas, USB, a quién, cuándo y excepciones | Seguridad de los equipos → Reglas (mismo asistente) |
| Cuenta de IT: usuario, contraseña, modo, bloquear inicio de sesión por red (Ajustes) | Seguridad de los equipos → Cuenta de IT y PIN |
| PIN de desbloqueo local: ver y cambiar (Ajustes) | Seguridad de los equipos → Cuenta de IT y PIN |
| Desactivar Smart App Control (Ajustes) | Seguridad de los equipos → Smart App Control |
| Días de detalle, meses de resúmenes, días de registros técnicos (Ajustes) | Ajustes del cliente → Conservación de datos |
| Aplicar la retención (Ajustes) | Ajustes del cliente → Av. |
| Fuentes de datos: conectar, quién manda en cada dato, sincronizaciones, pendientes | Ajustes del cliente → Fuentes de datos |
| Auditoría | Ajustes del cliente → Av. Auditoría |
| Logs de los equipos | Equipos → Av. Logs técnicos |
| Horarios, margen de puntualidad, horario predeterminado | Jornada → Horarios |
| Festivos: calendarios, sociedades, asignación | Jornada → Festivos |
| Ausencias, Puertas | Jornada |
| Organización: firmas, empresas internas, sedes, áreas, cargos, dependencias | Estructura del cliente |
| Sedes (tablero comparativo) | Productividad → Comparar sedes |
| Clasificación de apps (categoría, excepciones) | Clasificación de apps |
| Doble empleo, Apps sospechosas (catálogo y detecciones) | Alertas |
| Puesta en marcha: piloto, responsable, revisiones, cierre | Puesta en marcha (sin cambios) |
| Versión, cliente, anillo, porcentaje (Releases) | Versiones del agente («Probar» / «Publicar para todos»); arquitectura y agente mínimo en Av. |
| Mudanzas de servidor: orden firmada, modo mudado, equipos excluidos | Versiones del agente → Av. Mudanzas |
| Salud del servidor | Salud del servidor + tarjeta en Inicio de plataforma |
| Portal: direcciones autorizadas | Conexiones → Portal |

---

## 3. Cambios priorizados

### Rápidos (horas; solo etiquetas, orden o defaults; sin backend)

| # | Cambio | Archivos |
|---|---|---|
| R1 | «Crear cuenta»: por defecto, el cliente del contexto. La opción superadmin al final y con aviso. | `assets/k3.js` (~1050–1060) |
| R2 | El texto del menú es igual al título de la página (5 casos de §1.2). | `partials/sidebar.php:13-24`, `planes.php:3`, `fuentes.php:3`, `admin/releases.php:3`, `roles.php:3` |
| R3 | Plataforma arriba y plegable para el superadmin; Administración plegable. | `partials/sidebar.php:39-48`, `assets/k3.js` (`start`), `assets/app.js` (menú), `assets/k3.css` |
| R4 | Vocabulario Cliente: «Empresas» → «Clientes», «Crear empresa» → «Dar de alta un cliente», selector «Empresa» → «Cliente», «Empresas del grupo» → «Empresas internas». | `partials/sidebar.php:21`, `empresas.php:3`, `assets/k3.js:20,229,1173-1447,4266`, `partials/layout-k3.php:37`, `assets/app.js:19`, `assets/fleet.js:96` |
| R5 | Resumen de plataforma sin jerga («Tenants», «enrolados», «Releases»). | `assets/app.js:316-330` |
| R6 | Controles del equipo con nombre legible. | `assets/app.js:497` (reusar el mapa de `assets/k3.js:3528`) |
| R7 | En el diálogo de creación, pedir el plan por defecto; agregar «Revisar funciones y plan» a los siguientes pasos. | `assets/k3.js:1409-1447` |
| R8 | Subtítulo de Ajustes, con las 3 tarjetas de Windows bajo un encabezado «Seguridad de los equipos». | `ajustes.php:3`, `assets/k3.js:3672-3779` |
| R9 | «Organización: firmas, sedes…» → «Estructura del cliente»; «Sedes» → «Comparar sedes». | `partials/sidebar.php:14` |
| R10 | Etiqueta del permiso «Ver los planes (tiers)». | nueva migración (texto en `migrations/0024_panel_accounts.sql:58`) |
| R11 | En «Crear rol» abierto por superadmin para un cliente, plegar los permisos «Solo plataforma». | `assets/k3.js:4192-4205` |

### Grandes (días; reestructuran pantallas, algunas con backend)

| # | Cambio | Archivos | Backend |
|---|---|---|---|
| G1 | Ficha del cliente con pestañas (reemplaza tabla y diálogos de Empresas) | `empresas.php`, `assets/k3.js` (`tenantsPage`) | No |
| G2 | Asistente «Dar de alta un cliente» | `assets/k3.js`, `assets/onboarding*.js` | Sí, si el primer administrador se crea atómico (persona + cuenta + acceso) |
| G3 | Contexto único de cliente: migrar Registrar equipos, Equipos, Ficha del equipo y Releases de `layout.php`/`app.js` a `layout-k3.php`/`k3.js` | `partials/layout.php`, `assets/app.js`, `assets/intake.js`, `assets/fleet.js`, `alta.php`, `equipos.php`, `equipo.php`, `admin/releases.php` | No |
| G4 | «Seguridad de los equipos → Resumen de protección» (compró × plan × personas × aplicado) | nueva pantalla, `assets/k3.js` | Probable: un endpoint de resumen por cliente; hoy se cruzaría `/tenants/{id}/features`, `/person-plans`, `plan-usage` y `/devices/{id}/security` equipo por equipo |
| G5 | Accesos: fusionar Administradores + Roles + Asignación automática + Acceso al Portal en pestañas | `cuentas.php`, `roles.php`, `assets/k3.js` (`accounts`, `rolesPage`) | No |
| G6 | Secciones «Avanzado» plegadas, recordadas por persona | `assets/k3.js`, `assets/app.js`, `assets/k3.css` | No |
| G7 | Unificar `rbac_self_management` y `autogestion_accesos` | `src/Features.php`, `src/AdminApi.php`, `assets/k3.js:1373`, `assets/nav-access.js:18` | Sí; antes, decisión de Koichi |
| G8 | Versiones del agente por tarea («Probar» / «Publicar para todos»); Mudanzas a Av. | `assets/fleet.js`, `assets/relocations.js`, `admin/releases.php` | No |

Orden sugerido: R1 (riesgo real), R2–R4 y R9 (orientación), R7 y G1 + G2 (alta de cliente completa), G4 (la pregunta «¿quedó protegido?»), y luego el resto.

---

## 4. Preguntas para validar con Koichi (paso 3)

- **Q1.** ¿Aceptas «Cliente» para el tenant y «Empresa interna» para `org_units kind=company`? Ya está en la arquitectura del 2026-10-02, pero el panel no lo aplica.
- **Q2.** ¿Plataforma arriba y plegable, o un interruptor «Modo plataforma / Modo cliente» que muestre solo un bloque a la vez?
- **Q3.** ¿«La empresa gestiona sus propios roles y cuentas» y la función «El cliente gestiona sus roles y cuentas» son lo mismo? Si sí, se deja solo la función vendible.
- **Q4.** ¿El primer administrador del cliente recibe por defecto el rol «Corporativo» con alcance «Todo el cliente»?
- **Q5.** ¿Las Mudanzas de servidor van en «Avanzado» de Versiones del agente, o en su propia entrada de Plataforma?
- **Q6.** ¿«Seguridad de los equipos» va como entrada propia del cliente (propuesta), o dentro de Equipos?
