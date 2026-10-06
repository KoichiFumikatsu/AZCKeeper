# Revisión UX/UI del panel AZCKeeper v4

Fecha: 2026-10-05. Revisión estática del código: no se abrió una sesión del panel, no se consultaron datos de producción ni se hicieron pruebas con usuarios. Única escritura: este documento. Sin cambios de implementación ni commits.

Las referencias `assets/...`, `partials/...` y `*.php` son relativas a `backend-v4/public/`. Las referencias `backend-v4/src/...` son relativas a la raíz del repositorio. Todas las referencias citadas se leyeron. Las líneas de `assets/k3.js` corresponden a la versión revisada, SHA-256 `D243C822CF88A5DB8EE908E09A38E1C1D8E2DF142F88183D449F401304315847`; otro agente puede desplazarlas posteriormente.

**Observado** = comportamiento definido en el código. **Impacto probable** = hipótesis de usabilidad, no resultado de investigación. **Propuesta** = trabajo pendiente. **Frontend** = viable sobre datos y operaciones observados, no implementado todavía. **Requiere backend** = ampliación de modelo o contrato que no debe darse por existente.

## Decisión recomendada

Priorizar estructura de información y continuidad de tareas. Organización debe abrir una lista por tipo con creación contextual. Personas debe conectar datos laborales, plan y acceso sin confundirlos. Registrar equipos debe separar instalar de resolver solicitudes. La paleta existente permite hacerlo sin cambiar la identidad visual.

Orden: **Organización → Personas y Cuentas → Alta de equipos → Ficha de equipo → Reglas y funciones contratadas → resto**. Corregir en paralelo búsqueda y textos contradictorios, porque pueden inducir interpretaciones equivocadas.

### Discrepancias que condicionan el diseño

| Tema | Evidencia leída | Consecuencia |
| --- | --- | --- |
| Jerarquía de negocio frente al modelo actual | `assets/k3.js:15` mantiene `firm` y `company` distintos. `backend-v4/src/AdminApi.php:277` expone un `parent_id`; `backend-v4/src/AdminApi.php:294` y `backend-v4/src/AdminApi.php:297` reservan país y ciudad para sedes. `assets/k3.js:141` explica que empresas pueden compartir sedes. | No representar obligatoriamente Cliente → Empresa → País → Ciudad → Sede → Área → Cargo como relaciones almacenadas. País/ciudad pueden ser agrupaciones visuales. Horarios es un catálogo independiente. |
| Empresa y grupo tienen dos significados | `assets/k3.js:610` distingue grupos de clientes de empresas internas. `partials/sidebar.php:21` llama «Empresas» a clientes; `assets/k3.js:138` llama «Empresas del grupo» a unidades internas. | Usar Cliente para tenant y Empresa del grupo para empleador interno. Nombrar explícitamente Grupo de clientes a la asociación de tenants. |
| Paleta activa | `assets/k3.css:1` define fondo `#E4E4E4`, blanco `#F8F8F8` y acento índigo `#3C4082`; `assets/styles.css:11` conserva navy y `assets/styles.css:14` rojo. Los layouts cargan K3: `partials/layout-k3.php:27`, `partials/head.php:8`. | Seguir K3 y fondo claro. No reintroducir navy/rojo por asumir que el CSS histórico está activo. La búsqueda en `public/` no encontró carga de `assets/styles.css`. |
| Plan frente a ficha antigua | Personas consulta `/person-plans/available` (`assets/k3.js:867`); la ficha activa consulta `/tiers` y `/subscription` y muestra Tier (`assets/coordinator.js:249`, `assets/coordinator.js:251`). | Representar plan efectivo por persona en la ficha; no equiparar tier y plan con un cambio de etiqueta. |
| Festivos en reportes | `assets/coordinator.js:245` dice que Presencia no incluye festivos. `backend-v4/src/ActivityReports.php:54` usa `ActivityCalendar::expected()` y `backend-v4/src/ActivityCalendar.php:11` sí los considera. | Corregir el mensaje, no desarrollar de nuevo el soporte. |

Rutas verificadas: páginas migradas usan `layout-k3.php` y los controladores de `assets/k3.js:2221`. Alta y equipo usan `layout.php` y `app.js`. En el portal, ficha de persona y Productividad se delegan a `coordinator.js` (`assets/app.js:560`); las funciones antiguas `member()` y `reports()` de `app.js` no sustentan este diagnóstico. La navegación visible procede de `partials/sidebar.php`, no de los antiguos `nav-portal.php` y `nav-admin.php`.

## 1. Diagnóstico por flujo

**P0:** corregir antes de consolidar el rediseño, por información engañosa o bloqueo de tarea básica. **P1:** fricción frecuente o riesgo operativo. **P2:** mejora secundaria. No son niveles de una auditoría de seguridad.

### 1.1 Organización: listar, crear y mantener unidades

**ORG-1 · P1 · La tabla obliga a clasificar mentalmente cada fila.**

- **Observado:** tabla de siete columnas combina Firma, Empresa del grupo, Sede, Área y Cargo; solo filtra por Tipo y Estado (`assets/k3.js:143`, `assets/k3.js:145`). Ubicación queda vacía para cuatro tipos. Los KPI cuentan activas, mientras la lista incluye activas e inactivas (`assets/k3.js:137`).
- **Impacto probable:** cada visita exige limpiar la vista; contadores y listado parecen no coincidir. La mezcla diluye ubicación y dependencia.
- **Propuesta — frontend:** pestañas Empresas del grupo / Sedes / Áreas / Cargos, con contador y Activas por defecto. Conservar Firmas como quinta pestaña mientras haya registros: no migrarlos implícitamente a empresas. Todas las unidades queda como vista secundaria. Recordar pestaña y filtros por cliente.
- **Columnas iniciales:** empresas: Nombre / Personas activas visibles / Estado / Acciones; sedes: Sede / Ciudad y país / Dependencia / Personas / Estado / Acciones; áreas y cargos: Nombre / Depende de / Personas / Estado / Acciones. Detalles adicionales al abrir la unidad.

**ORG-2 · P1 · «Depende de» no comunica un recorrido verificable.**

- **Observado:** crear ofrece todas las unidades activas como superiores (`assets/k3.js:199`); editar excluye solo la propia unidad (`assets/k3.js:160`). Backend recorre ancestros para impedir ciclos e inactivos, sin imponer ahí una matriz de tipos padre/hijo (`backend-v4/src/AdminApi.php:284`).
- **Impacto probable:** el usuario descubre relaciones inválidas al guardar; un nombre aislado no desambigua unidades similares.
- **Propuesta — frontend:** selector buscable, agrupado por tipo, con ruta legible y descendientes excluidos al editar. Mantener Sin dependencia. Vista secundaria Dependencias que dibuje exactamente `parent_id`, con raíces sin padre.
- **Límite:** el árbol organizativo no es árbol de permisos. No afirmar que mover una unidad mueve personas o cambia accesos. Jerarquía obligatoria por tipos, relaciones múltiples sede/empresa o catálogos editables de países/ciudades **requieren backend** y una decisión de modelo.

**ORG-3 · P1 · Crear unidad empieza fuera del contexto.**

- **Observado:** botón global Crear unidad; Sede y Colombia seleccionadas por defecto (`assets/k3.js:193`, `assets/k3.js:197`, `assets/k3.js:200`). Nombre admite 160 caracteres al crear y 120 al editar (`assets/k3.js:198`, `assets/k3.js:157`).
- **Impacto probable:** quien está mirando cargos empieza creando una sede y puede aceptar valores sin revisarlos.
- **Propuesta — frontend:** + Nueva sede / + Nueva área / + Nuevo cargo / + Nueva empresa según pestaña; tipo fijado por contexto. Preseleccionar dependencia solo al crear desde una unidad concreta. País/ciudad pueden heredarse del filtro, indicando que son sugeridos. Fuera de contexto, pedir selección o explicar Sin país y su efecto en el calendario automático. Unificar longitudes con el contrato real antes de modificar validación.

**ORG-4 · P1 · País y ciudad no sirven para navegar Organización.**

- **Observado:** `cityInput()` sugiere ciudades de todas las sedes sin separar países (`assets/k3.js:20`). Organización no filtra geografía (`assets/k3.js:145`), Personas sí (`assets/k3.js:903`). País se vincula con festivos (`assets/k3.js:162`).
- **Impacto probable:** encontrar sedes requiere leer ubicaciones fila a fila; una ciudad sugerida puede elegirse para otro país.
- **Propuesta — frontend:** en Sedes, filtros País → Ciudad y agrupación opcional País / Ciudad. Sugerencias de ciudad limitadas al país, conservando texto libre. Grupo Sin ubicación para pendientes. Antes de cambiar país: «Puede cambiar el calendario automático de las personas de esta sede»; no decir que cambia calendarios propios.
- **Límite:** un filtro Empresa no debe volver exclusiva una sede compartida. Si se deriva de personas, llamarlo «Sedes con personas de esta empresa», respetar permisos y no usarlo para ocultar sedes nuevas vacías por defecto.

**ORG-5 · P0 · Cero personas no garantiza que la unidad esté libre.**

- **Observado:** si `/users` no es accesible, se sustituye por `[]` y se muestra cero (`assets/k3.js:132`, `assets/k3.js:133`). Conteo solo de activas; desactivación consulta personas sin filtrar estado y otras dependencias (`backend-v4/src/AdminApi.php:289`). Eliminar tiene controles adicionales (`backend-v4/src/AdminApi.php:252`). Cada fila expone Editar, Desactivar y Eliminar (`assets/k3.js:191`).
- **Impacto probable:** se interpreta 0 como permiso para borrar y el error contradice lo mostrado. Un conteo parcial se puede interpretar como total.
- **Propuesta — frontend:** rotular Personas activas visibles o Sin permiso para consultar. Editar visible y acciones de estado en Más. Al bloquear una operación, mantener unidad y contexto, explicar categorías devueltas por la API y enlazar a los módulos relevantes.
- **Requiere backend:** previsualización autoritativa completa de dependencias antes de borrar; no simularla con el conteo local.

**Aceptación:** crear cargo desde Cargos sin escoger otra vez su tipo; volver a la misma búsqueda al editar; encontrar sede por país/ciudad; no presentar conteos inaccesibles como cero; no duplicar sedes compartidas para hacer encajar un árbol.

### 1.2 Personas: alta, edición, rol, alcance y plan

**PER-1 · P0 · El buscador puede no encontrar nombre o correo.**

- **Observado:** el buscador compartido solo compara getters que devuelven string (`assets/k3.js:59`). Nombre y correo de Personas devuelven un nodo DOM (`assets/k3.js:897`); también identidad de Cuentas (`assets/k3.js:373`) y nombre visible de Empresas (`assets/k3.js:587`). Tablekit detecta el buscador propio y no añade otro que compense esto (`assets/tablekit.js:40`).
- **Impacto probable:** una persona visible desaparece al buscarla; se puede creer que falta y volver a crearla.
- **Propuesta — frontend:** texto de búsqueda por entidad independiente del render: nombre, correo y documento; nombres de unidades y rutas; nombre interno y visible de clientes. Normalizar espacios y tildes. No buscar en botones de acciones. Es corrección funcional prioritaria.

**PER-2 · P1 · Listado y formulario exponen demasiadas decisiones simultáneas.**

- **Observado:** nueve/diez columnas y seis filtros (`assets/k3.js:896`, `assets/k3.js:903`). Modal mezcla identidad, jefe, monitoreo, firma, sede, área, cargo, horario, empresa, festivos, estado, plan y acceso (`assets/k3.js:907`, `assets/k3.js:941`). Sin horario llamado por defecto, toma el primero (`assets/k3.js:863`). Faltantes de sede/área/cargo aparecen al enviar (`assets/k3.js:947`).
- **Impacto probable:** se mezclan alta laboral, monitoreo y autorización; el orden alfabético puede decidir un horario sin elección consciente.
- **Propuesta — frontend:** listado inicial Persona / Empresa y sede / Área y cargo / Estado / Acciones; vista alternativa Accesos y planes. Búsqueda y Empresa visibles, resto en Filtros con chips. Alta por bloques Identidad → Organización y jornada → Revisar; monitoreo y calendarios especiales en Opciones adicionales.
- Detectar catálogos requeridos vacíos antes de llenar; + Crear área solo con permiso, conservando borrador. Mostrar por qué se sugiere horario y permitir Sin horario. No hacer obligatoria Empresa del grupo sin revisar contrato.

**PER-3 · P1 · Crear persona no conduce a habilitar su acceso.**

- **Observado:** acceso solo se edita para persona ya existente (`assets/k3.js:941`). Crear cuenta exige persona activa sin cuenta; si falta, manda a Personas (`assets/k3.js:496`, `assets/k3.js:498`). Persona, plan y acceso se guardan por separado con mensajes de éxito parcial (`assets/k3.js:958`, `assets/k3.js:965`, `assets/k3.js:974`).
- **Impacto probable:** no se sabe si crear persona ya permite iniciar sesión; ir a otra pantalla rompe la tarea.
- **Propuesta — frontend:** al crear, mostrar Persona creada y acciones Dar acceso al panel / Registrar su equipo según permisos. Dar acceso preselecciona persona y pide un rol + un alcance y la reautenticación existente. Separar Persona registrada, Cuenta habilitada y Acceso definido.
- Conservar éxito parcial: «Datos guardados · Plan pendiente» y reintentar solo lo pendiente. Guardado realmente atómico de persona + plan + cuenta **requiere backend**.

**PER-4 · P1 · El nombre abre una ficha diferente del lugar donde se edita.**

- **Observado:** nombre va a `miembro.php`, Editar abre modal K3 (`assets/k3.js:897`, `assets/k3.js:902`). La ficha tiene actividad, presencia, políticas y equipos (`assets/coordinator.js:277`, `assets/coordinator.js:288`, `assets/coordinator.js:297`) y muestra Tier (`assets/coordinator.js:251`).
- **Impacto probable:** dos destinos para una persona con información comercial distinta y sin continuidad administrativa.
- **Propuesta — frontend:** ficha única Datos / Acceso y plan / Actividad / Equipos. Encabezado con cliente, identidad y Editar persona. Usar modelo de plan actual; mostrar Heredado del cliente / Propio y funciones efectivas. Permisos administrativos no son funciones compradas.

**PER-5 · P2 · Datos sincronizados e importación necesitan salidas contextuales.**

- **Observado:** `managed_by` reemplaza Editar por texto (`assets/k3.js:902`), con aviso de cambios externos (`assets/k3.js:888`). CSV ya cuenta listas, omitidas y errores (`assets/k3.js:1044`).
- **Impacto probable:** se entiende el bloqueo pero no dónde resolverlo; una importación puede validar nombres sin que el operador vea qué asignación final aceptará. distinguir campos laborales gestionados fuera de plan/acceso de Keeper comprobando cada operación. Mantener resumen CSV y mostrar muestra de unidades/horarios resueltos antes de crear. Descargar errores puede generarse con resultados existentes; no requiere nuevo endpoint.

### 1.3 Alta de equipos: claves, instalador y solicitudes pendientes

**ALT-1 · P1 · La secuencia empieza por el resultado de instalar.**

- **Observado:** explicación → solicitudes → equipos esperados → claves/reglas (`assets/intake.js:83`). Descargar instalador aparece al final junto a Crear clave, ambos primarios (`assets/intake.js:291`). Descargar ya crea clave nueva (`assets/intake.js:304`).
- **Impacto probable:** el cliente nuevo no encuentra inicio y puede creer que debe crear una clave manual antes de descargar.
- **Propuesta — frontend:** Descargar instalador en encabezado; pestañas Instalar / Solicitudes pendientes / Equipos esperados / Configuración. Pasos preparar personas → descargar → ejecutar en Windows → comprobar registro. Claves manuales en configuración avanzada para IT; informar nueva clave antes de descargar.

**ALT-2 · P1 · Aprobar combina decisión y captura dentro de una fila.**

- **Observado:** cada fila pendiente tiene cédula/correo, placa, Aprobar y Rechazar (`assets/intake.js:121`). Vacío puede significar aceptar sugerida (`assets/intake.js:122`); alertas están en coincidencia (`assets/intake.js:114`).
- **Impacto probable:** filas altas y aprobación de identidad apoyada en un placeholder; alertas fáciles de pasar por alto.
- **Propuesta — frontend:** tabla Equipo / Persona sugerida / Motivo / Última conexión / Revisar. Revisar abre panel con serie, identidad declarada, selección explícita y «Aprobar para [nombre]». Conservar confirmación al rechazar. Llamarlas Solicitudes de registro de equipos, no Accesos pendientes sin aclaración: no son cuentas del panel.
- Resolver cédula/correo mediante un servicio nuevo **requiere backend** si no se dispone de la lista autorizada de personas.

**ALT-3 · P0 · Corte de 100 registros y éxito que desaparece.**

- **Observado:** se piden 100 solicitudes/equipos esperados y solo se avisa que hay más recientes (`assets/intake.js:105`, `assets/intake.js:151`, `assets/intake.js:214`, `assets/intake.js:233`). Al aprobar se escribe feedback dentro de la fila y se recarga el contenedor, retirándolo (`assets/intake.js:135`, `assets/intake.js:106`).
- **Impacto probable:** pendientes antiguos son inaccesibles desde la interfaz; una aprobación parece desaparecer sin resultado.
- **Propuesta — frontend:** Cargar más con continuación `next_offset` existente, además de paginación visual. Éxito persistente fuera de tabla: «Aprobada; esperando conexión». Enlace a Aprobadas sin registrar y actualización con consulta existente. No inventar totales globales.

**ALT-4 · P1 · Estados y claves no cierran el ciclo.**

- **Observado:** alta/enrolado/registro se alternan (`assets/intake.js:14`). Crear clave manual no actualiza la lista (`assets/intake.js:322`); descargar sí (`assets/intake.js:311`). Aviso de descarga promete pendiente hasta aprobación aunque hay autoaprobación (`assets/intake.js:310`, `assets/intake.js:247`).
- **Impacto probable:** una clave recién creada parece no existir y una aprobación automática contradice la explicación del instalador. Rechazado como salida separada. Refrescar lista sin borrar revelación única. Texto «El registro seguirá las reglas de aprobación de este cliente». Descargar no equivale a instalar.
### 1.4 Ficha de equipo: endurecimiento y comandos

**EQ-1 · P1 · Acciones operativas antes del diagnóstico completo.**

- **Observado:** resumen → Cambiar nombre → Endurecimiento → Controles → Registros (`assets/app.js:364`, `assets/app.js:369`, `assets/app.js:384`). Persona solo aparece como enlace genérico (`assets/app.js:362`). Se exponen Modo B, identificadores de control y códigos (`assets/app.js:406`, `assets/app.js:373`).
- **Impacto probable:** hace falta recorrer varias secciones para entender qué falla; las acciones llaman más atención que el diagnóstico.
- **Propuesta — frontend:** encabezado Equipo + Persona + última conexión + estado; pestañas Resumen / Seguridad / Acciones e historial / Diagnóstico. Destacar controles no aplicados y antigüedad. Traducir códigos conocidos y dejar identificadores en detalles. Conservar «Estado reportado por el agente», ya explicado (`assets/app.js:372`).

**EQ-2 · P1 · Orden enviada y resultado están desconectados.**

- **Observado:** endurecer informa envío y vuelve a habilitar botón sin refrescar estado en esa función (`assets/app.js:436`, `assets/app.js:443`). Renombrar informa sincronización/reinicio y limpia el campo (`assets/app.js:474`). El historial de comandos ya se consulta desde la ficha de persona, pestaña Equipos (`assets/coordinator.js:301`).
- **Impacto probable:** se interpreta envío como ejecución o se repite la acción; seguirla exige buscar otra ficha.
- **Propuesta — frontend:** mover/acercar historial existente a ficha de equipo; mostrar acción solicitada y estado real de API. Endurecimiento se actualiza con GET existente. «Orden enviada; aún sin confirmación» hasta evidencia de ejecución, con última consulta y Actualizar. No inventar porcentaje de avance ni correlacionar órdenes distintas por proximidad temporal.
- Una línea de tiempo unificada, correlación adicional o eventos no expuestos **requieren backend**; no son necesarios para mostrar el historial ya disponible.

**EQ-3 · P1 · Confirmaciones fragmentadas para operaciones sensibles.**

- **Observado:** endurecer usa prompt de hostname (`assets/app.js:433`), reinicio usa confirm (`assets/app.js:467`), configurar IT tiene diálogo propio y confirmación automática (`assets/hardening-settings.js:39`, `assets/hardening-settings.js:93`). Ayuda sí advierte cierre de sesión a los dos minutos (`assets/app.js:409`).
- **Impacto probable:** cuesta reconocer el nivel de riesgo y el momento real del cambio al alternar mecanismos de confirmación. y «Enviar orden de endurecimiento». Conservar escritura de nombre donde existe. Advertencia de cierre dentro de confirmación, no solo en ayuda previa. Resolver falta de cuenta IT desde la tarea, sin dar por ejecutada la orden al guardar ese ajuste.
- Distinguir Cuenta del panel de Cuenta local de IT en Windows. Conservar permisos y auditoría; añadir Ocultar contraseña tras revelación (`assets/hardening-settings.js:71`).

### 1.5 Reglas

**REG-1 · P1 · Formulario completo y destino más amplio por defecto.**

- **Observado:** nueva regla aplica inicialmente a todo el cliente (`assets/k3.js:1542`). Muestra web, descargas, instalaciones y USB (`assets/k3.js:1555`, `assets/k3.js:1563`). Listado explica precedencia y presenta todas las reglas internas como listas (`assets/k3.js:1530`, `assets/k3.js:1532`).
- **Impacto probable:** se acepta destino global por omisión y se guarda sin revisar efecto legible.
- **Propuesta — frontend:** Qué controlar → A quién → Revisar. Elección consciente del alcance al crear. «Bloquear 3 sitios en Sede Cali; mantiene las demás reglas». Mostrar bloque seleccionado y Agregar otro control. Tabla con efecto breve y detalle expandible.
- No añadir por analogía Empresa del grupo, País o Ciudad: destinos observados son cliente, sede, área, persona y equipo (`assets/k3.js:1514`). Nuevos destinos **requieren backend**.

**REG-2 · P1 · Activa no significa aplicada.**

- **Observado:** página carga organización, personas, equipos y políticas (`assets/k3.js:1516`); estado solo refleja `enabled` (`assets/k3.js:1533`). Funciones efectivas dependen de cliente ∩ plan (`assets/k3.js:1642`). Ya existe consulta de reglas efectivas por persona (`assets/coordinator.js:261`, `assets/coordinator.js:292`).
- **Impacto probable:** una regla activa se interpreta como aplicada a todos los destinatarios.
- **Propuesta — frontend:** separar Configurada y activa de Reportada como aplicada. Explicar dependencias comerciales con datos autorizados y enlazar a reglas efectivas de una persona. No presentar simulación global falsa. Previsualización autoritativa de impacto/conflictos antes de guardar **requiere backend** si no se añade contrato específico.

**REG-3 · P1 · Guardar puede simplificar datos que el editor no representa.**

- **Observado:** controles toman primera regla de cada tipo (`assets/k3.js:1553`); guardar reconstruye reglas con IDs nuevos, `schedule_id: null`, prioridad cero (`assets/k3.js:1578`).
- **Impacto probable, condicionado:** si existen registros de otra vía con horarios, prioridades o múltiples entradas no representables, abrir/guardar podría alterar significado. No se verificó que esos registros existan en producción.
- **Propuesta — frontend:** detectar información no representable; conservarla o explicar que ese registro no admite edición simplificada. No ocultarla detrás de Guardar. Ampliar editor después de verificar contrato actual.

### 1.6 Planes y paquetes

**PLA-1 · P1 · La explicación desplaza el trabajo frecuente.**

- **Observado:** Cómo funciona → Catálogo → Paquetes → Planes (`assets/k3.js:1630`). Funciones como listas completas en celdas (`assets/k3.js:1656`); otra celda acumula uso por cliente (`assets/k3.js:1687`).
- **Impacto probable:** editar exige recorrer contenido pedagógico y tablas extensas.
- **Propuesta — frontend:** pestañas Planes por persona / Paquetes / Catálogo; Cómo funciona plegable con ejemplo corto. Acción contextual Nuevo plan o Nuevo paquete. Listado con nombre, número de funciones, personas/clientes y estado; desglose al abrir.

**PLA-2 · P1 · Cambios con consecuencias distintas usan presentación parecida.**

- **Observado:** paquete es plantilla, no actualiza clientes existentes (`assets/k3.js:1639`). Quitar funciones/desactivar plan usado pide confirmación genérica (`assets/k3.js:1700`). En cliente, cambiar paquete reemplaza casillas y se guarda sin resumen de diferencias (`assets/k3.js:692`, `assets/k3.js:695`).
- **Impacto probable:** se confunde plantilla comercial con configuración vigente; retiradas de funciones pueden pasar inadvertidas.
- **Propuesta — frontend:** revisión Se agregan / Se retiran / Se conservan. En paquete: «No cambia clientes actuales». En plan: personas en uso y funciones afectadas; desactivación explica fallback al predeterminado conforme al comportamiento descrito en Personas (`assets/k3.js:933`). En cliente: mostrar plan predeterminado y resultado de funciones.
- Fallos de uso por cliente se convierten a null (`assets/k3.js:1616`): presentar Conteo no disponible, no ausencia de uso. Impacto exacto en cada equipo antes de guardar **requiere backend**.

**PLA-3 · P1 · Función pendiente puede parecer operativa.**

- **Observado:** `pending` muestra «Aún sin pantalla: se puede vender pero todavía no se aplica» (`assets/k3.js:1650`).
- **Impacto probable:** se puede interpretar una función seleccionada como disponible para aplicar inmediatamente. en catálogo, selección y revisión. Separar Contratada de Disponible para aplicar. No deshabilitar ventas por decisión de diseño: conservar política comercial existente.

### 1.7 Empresas: ficha del cliente

**CLI-1 · P1 · Administración dispersa, sin ficha estable.**

- **Observado:** fila con Editar y marca, Cuentas, Funciones y Archivar (`assets/k3.js:600`). Marca, zona, estado, autogestión y grupo comparten modal (`assets/k3.js:749`, `assets/k3.js:767`). La página también dibuja estructura y grupos (`assets/k3.js:585`, `assets/k3.js:606`).
- **Impacto probable:** para responder qué tiene contratado o qué le falta al cliente hay que abrir varios diálogos y recordar sus resultados. ficha Resumen / Funciones y plan / Accesos / Marca y ajustes. Estructura de clientes como vista separada. Resumen con estado, paquete de origen, plan predeterminado y próximos pasos verificables. Entrar al panel de este cliente debe establecer contexto antes de navegar.

**CLI-2 · P0 · El siguiente paso ofrecido puede estar bloqueado.**

- **Observado:** crear cliente propone crear inmediatamente su cuenta (`assets/k3.js:818`), pero esta requiere persona (`assets/k3.js:498`). Cuentas en la fila navega a `?empresa=...` (`assets/k3.js:601`), parámetro que abre creación, no listado filtrado (`assets/k3.js:540`).
- **Impacto probable:** primer recorrido sin candidatos; enlace de aparente consulta abre creación.
- **Propuesta — frontend:** Configurar organización → Crear administrador como persona → Crear cuenta → Preparar instalación, mostrando requisitos faltantes. Separar Ver cuentas y Crear cuenta; implementar filtro por cliente y parámetro de creación explícito.

**CLI-3 · P1 · Guardado parcial y recuperación poco visibles.**

- **Observado:** edición encadena marca, logo, gate RBAC y grupo (`assets/k3.js:781`); error posterior general (`assets/k3.js:795`). Desarchivar mantiene suspensión (`assets/k3.js:734`). Archivado y purga tienen confirmaciones específicas (`assets/k3.js:733`, `assets/k3.js:704`).
- **Impacto probable:** un fallo tardío invita a repetir cambios ya guardados; desarchivar puede confundirse con reactivar. no repetir lo completado. Archivo y purga en zona separada. Tras desarchivar mostrar Suspendido y Revisar estado, sin insinuar reactivación. Conservar contraseña y nombre exacto de purga. Operación atómica entre cliente, logo y grupo **requiere backend**.

### 1.8 Cuentas y Roles

**ACC-1 · P1 · El alcance exige entender una intersección sin verla.**

- **Observado:** cuatro grupos de casillas con «sin marcar = todas» (`assets/k3.js:286`), resumen sin explicar combinación (`assets/k3.js:307`). Servidor combina dimensiones con AND y opciones de una dimensión con IN (`backend-v4/src/AccessScope.php:86`, `backend-v4/src/AccessScope.php:93`).
- **Impacto probable:** Empresa A y Sede B se interpretan como unión de poblaciones; una combinación incompatible deja acceso sin personas.
- **Propuesta — frontend:** «Verá personas que cumplan TODAS estas condiciones: Empresa A Y Cali Y cualquiera de estas sedes». Selección buscable con chips; dimensiones no restringidas como Todas. Conservar rechazo de filtros totalmente vacíos. Siempre un rol + un alcance, no roles acumulables.
- Una estimación local solo abarca personas autorizadas y cargadas; no presentarla como total garantizado. Simulación completa de acceso/equipo/delegación **requiere backend**.

**ACC-2 · P1 · Cuenta activa, acceso y primer ingreso son estados distintos.**

- **Observado:** una cuenta puede tener «Sin acceso: no verá nada» y estado activo (`assets/k3.js:361`, `assets/k3.js:376`). Desactivar cuenta cierra sesiones (`assets/k3.js:391`); retirar persona revoca equipos (`assets/k3.js:950`).
- **Impacto probable:** se intenta resolver un problema de permisos activando una cuenta, o se retira una persona para impedir solo el acceso al panel. · Acceso sin configurar · Pendiente de primer ingreso», acción Completar acceso. Verbos distintos Desactivar cuenta / Retirar persona con impacto correspondiente. No insinuar que una operación sustituye a la otra.
- Conservar entrega única y copia de contraseña temporal (`assets/k3.js:330`). No prometer envío por correo: no se observó ese mecanismo.

**ACC-3 · P2 · Roles y automatizaciones compiten y usan vocabulario plural.**

- **Observado:** Roles añade Asignación automática (`assets/k3.js:2096`), cuya ayuda dice «le dan o le quitan roles» y «propone el rol» (`assets/k3.js:2106`). Permisos ya agrupados por módulo (`assets/k3.js:2150`).
- **Impacto probable:** se confunden reglas de acceso con reglas de equipos y se interpreta que se pueden acumular varios roles. con Nuevo rol y Nueva asignación automática para distinguirlas de reglas de equipos. Mantener grupos, añadir búsqueda/conteo y resumen Puede ver / modificar / administrar. Explicar rol efectivo y origen sin insinuar múltiples roles; confirmar resolución de coincidencias automáticas antes de cambiar ese texto. Conservar confirmación sensible devuelta por backend (`assets/k3.js:2137`).

### 1.9 Ajustes

**AJU-1 · P1 · Alertas y retención comparten guardado sin revisar efectos.**

- **Observado:** desconexión, detalle, resúmenes y logs en un formulario (`assets/k3.js:1865`). Ayuda explica borrado, pero no presenta diferencia al guardar (`assets/k3.js:1873`). Éxito/error comparten párrafo sin status/alert (`assets/k3.js:1870`, `assets/k3.js:1874`).
- **Impacto probable:** reducir conservación parece equivalente a cambiar un umbral de aviso; un fallo puede leerse como mensaje informativo. con feedback contextual. Al reducir plazo, Antes → Después y aviso de eliminación conforme al proceso existente; no inventar fecha o cantidad. Confirmación para reducción, no cada cambio inocuo. Errores persistentes y asociados a campos.
- Estimación real de volumen perdido y fecha de purga **requiere backend**.

**AJU-2 · P2 · Atajos a pantallas inaccesibles.**

- **Observado:** Marca, logo y colores enlaza a Empresas sin condición de plataforma (`assets/k3.js:1895`), pero Empresas rechaza no superadmin (`assets/k3.js:2240`). IT sí comprueba función/permiso (`assets/k3.js:1879`).
- **Impacto probable:** un atajo visible termina en Sin permiso y hace desconfiar de la navegación. Gestionado por la plataforma cuando sea información necesaria. IT separado de retención, con alcance Todo este cliente.

### 1.10 Festivos y Horarios

**CAL-1 · P1 · Calendarios, días y asignaciones mezclados.**

- **Observado:** calendarios y todos los festivos juntos (`assets/k3.js:1930`, `assets/k3.js:1939`); filtros calendario/año sin selección inicial (`assets/k3.js:1947`). Agregar festivo aparece sin calendario y falla al abrir (`assets/k3.js:1950`, `assets/k3.js:2036`). Subtítulo todavía habla de sociedades (`festivos.php:3`).
- **Impacto probable:** se agrega un día al calendario incorrecto o se encuentra un requisito después de iniciar la tarea. año actual, próximo festivo y + Agregar en este calendario. Sin calendario: Crear calendario. Asignaciones y excepciones en otra pestaña. Corregir subtítulo. Mostrar en persona «Colombia · por país de sede» o «Especial · asignado» reutilizando resolución existente (`assets/k3.js:1915`) y su fallback.

**CAL-2 · P1 · Selección masiva conserva personas fuera de vista.**

- **Observado:** selección inicial incluye todas con ese calendario propio (`assets/k3.js:2000`); filtros solo cambian vista y envío usa todo `chosen` (`assets/k3.js:2031`). Ya hay contador de marcadas/mostradas (`assets/k3.js:2014`).
- **Impacto probable:** se confunde visible con seleccionado, especialmente al volver a automático.
- **Propuesta — frontend:** «24 seleccionadas: 8 visibles y 16 fuera de estos filtros», Revisar selección y resumen antes de aplicar. Diferenciar asignación actual de nuevas selecciones; desmarcar no debe presentarse como retirada si el endpoint solo modifica personas enviadas. Retorno automático debe informar éxitos parciales: hoy hace PATCH por persona (`assets/k3.js:2024`). No ofrecer deshacer masivo sin soporte.

**CAL-3 · P2 · Horario técnico sin semana efectiva.**

- **Observado:** zona horaria libre con ejemplo IANA (`assets/k3.js:1102`), horario general más excepciones diarias (`assets/k3.js:1109`). Tabla muestra almuerzo general (`assets/k3.js:1094`). Lista cuenta personas pero editor no resume impacto antes de guardar (`assets/k3.js:1139`).
- **Impacto probable:** se debe reconstruir mentalmente la semana y el efecto de excepciones antes de asignar o cambiar la jornada. semana con horas/almuerzo efectivos y días siguientes explícitos. No rechazar turno nocturno: backend interpreta salida menor/igual como día siguiente (`backend-v4/src/ActivityCalendar.php:30`). Mostrar personas visibles usuarias y cambios. Vigencia futura/programación de cambios **requiere backend** si se desea.

### 1.11 Productividad y Reportes

**REP-1 · P1 · Fechas expresadas como detalle de API.**

- **Observado:** Hasta excluido en formulario (`partials/layout.php:68`), ayuda reiterada y máximo 31 días (`assets/coordinator.js:232`, `assets/coordinator.js:240`). Menú dice Productividad, título Reportes (`partials/sidebar.php:11`, `reportes.php:3`).
- **Impacto probable:** se excluye involuntariamente el último día elegido o se consulta un rango distinto del que se pretende. Fechas inclusivas «Del 1 al 31», traducidas a `to` exclusivo al consultar; máximo 31 días efectivos. Últimos 7 días / Últimos 30 / Este mes. Periodo, zona y filtros consultados sobre resultados/exportación.

**REP-2 · P1 · Filtros independientes y contenido acumulado.**

- **Observado:** empresa, país, ciudad, área, sede y persona independientes (`assets/coordinator.js:109`, `assets/coordinator.js:235`). Productividad, apps y presencia seguidas (`assets/coordinator.js:242`). Gráfico y CSV ya existen (`assets/coordinator.js:58`, `assets/coordinator.js:243`).
- **Impacto probable:** combinaciones incompatibles producen vacíos y no queda claro si los controles editados ya corresponden a los resultados. resto bajo Filtros. País limita ciudad/sede; limitar por empresa solo con relaciones verificables y conservando sedes compartidas. Filtros visuales no cambian alcance de seguridad. Pestañas Resumen / Aplicaciones / Presencia, manteniendo tabla accesible y CSV.
- Si controles difieren del resultado, «Filtros modificados: pulsa Consultar». CSV sigue la consulta ejecutada. Conservar Sin dato como diferente de cero.

**REP-3 · P0 · Mensajes contradicen datos o representación.**

- **Observado:** texto incorrecto de festivos documentado al inicio. Leyenda dice Focus rojo (`assets/coordinator.js:71`), K3 usa índigo `--p-400` (`assets/k3.css:48`). Footer declara zona del navegador (`partials/layout.php:74`), coordinator usa zona del cliente (`assets/coordinator.js:84`).
- **Impacto probable:** la explicación hace interpretar un día o una serie de modo distinto de lo que realmente calcula y dibuja el panel. describir trazos además de color; zona del cliente explícita en reportes. Eventos que sí usan navegador deben declararlo en su sección. No cambiar globalmente fechas sin revisar módulo.

**REP-4 · P1 · Promedio no coincide exactamente con la explicación.**

- **Observado:** media aritmética de porcentajes diarios disponibles (`assets/coordinator.js:130`), ayuda dice uso productivo dividido por uso total (`assets/coordinator.js:133`). Ya hay avisos de clasificación/cobertura (`assets/coordinator.js:136`, `assets/coordinator.js:138`).
- **Impacto probable:** se interpreta ratio del período, aunque días de distinta duración pesan igual; baja cobertura pasa a segundo plano.
- **Propuesta — frontend:** Promedio diario de productividad con fórmula exacta, sin cambiar cálculo silenciosamente. Cobertura y clasificación junto a lectura principal y enlaces según permisos. Si se desea ratio ponderado, verificar numeradores/denominadores; un agregado nuevo autoritativo **requiere backend** si no está en contrato. No llamar bajo desempeño a ausencia de datos.
## 2. Patrones transversales

### 2.1 Pestañas primero; árbol complementario

La pestaña responde qué se administra. Un árbol único del negocio no basta porque el modelo no forma la jerarquía estricta descrita. Usar:

1. **Por tipo**, vista inicial: Empresas del grupo / Sedes / Áreas / Cargos / Firmas si existen.
2. **Por ubicación**, solo sedes: País → Ciudad → Sedes, agrupación derivada; país/ciudad no se editan como unidades.
3. **Por dependencias**, opcional: `parent_id` real, raíces Sin dependencia. Sin arrastrar para reorganizar en primera versión.

Sede compartida aparece una vez en su catálogo. Área/cargo sin padre no desaparece ni se coloca bajo una empresa supuesta. Horarios permanece independiente. Obligar pertenencias adicionales requiere decidir modelo/backend antes.

### 2.2 Tablas con una intención

- Cuatro a seis columnas útiles, identidad con subtítulo, detalle expandible y vista administrativa alternativa.
- Un buscador con campos explícitos; un responsable de filtros, orden y páginas. K3 dibuja (`assets/k3.js:45`) y Tablekit agrega orden/paginación (`assets/tablekit.js:29`): no sumar una tercera capa sin resolver estado.
- Conservar filtros, búsqueda, orden y página al guardar. Hoy se reconstruyen contenido y controles, por ejemplo en Organización (`assets/k3.js:136`, `assets/k3.js:150`).
- Chips activos y Limpiar filtros. Distinguir sin datos, sin coincidencias, fallo de carga y falta de permiso.
- Cargar datos del servidor y paginar datos cargados son operaciones distintas. Tablekit no recupera solicitudes que Intake nunca pidió.

### 2.3 «+ Nuevo» y estados vacíos contextuales

| Estado | Mensaje / acción |
| --- | --- |
| Sin áreas | «Crea la primera para asignarla a personas». **+ Nueva área**, con permiso. |
| Sin coincidencias | «No hay resultados con estos filtros». **Limpiar filtros**, conservando búsqueda hasta acción explícita. |
| Cliente sin personas | «Para crear una cuenta primero registra a su persona». **Crear persona** en ese cliente. |
| Sin calendario | «Crea un calendario y después agrega sus festivos». **Crear calendario**. |
| Sin solicitudes | «No hay solicitudes pendientes». **Ver cómo instalar**; no implica cero equipos. |
| Reporte vacío | Período/alcance visibles; **Cambiar período**, **Revisar cobertura** si autorizado. No afirmar causa no informada. |
| Dato sin permiso | Explicar qué no se puede consultar; no reemplazarlo por cero. |

`empty()` hoy solo recibe título y texto (`assets/k3.js:37`): admitir acción contextual. Un primario por tarea activa; no un primario por tarjeta.

### 2.4 Contexto y navegación

- «Cliente: Grupo AZC» persistente. Empresa del grupo como filtro separado. En plataforma, Administración de clientes.
- Breadcrumbs Cliente → Organización → Sedes; Cliente → Personas → Ana Pérez; Cliente → Equipos → ACT-0015. Hoy layouts muestran Inicio / título (`partials/layout-k3.php:41`, `partials/layout.php:59`).
- Volver a lista recupera filtros/posición. Enlace a otro cliente establece contexto y verifica acceso; no basta con añadir ID a URL.
- Menú ya filtra por permisos/funciones (`assets/nav-access.js:18`). Reutilizar esa información en atajos y acciones sin sustituir validación del servidor. No volver a mostrar indiscriminadamente módulos bloqueados.

### 2.5 Formularios, confirmaciones y feedback

- Modal para un objeto pequeño: sede, área, cargo, calendario. Ficha para persona, cliente, equipo.
- Verbo concreto: Guardar sede / Asignar calendario / Enviar orden. Cancelar secundario; destructivas separadas.
- Título de diálogo asociado, foco inicial, retorno de foco, error anunciado y foco en primer inválido. `dialogShell()` no asocia nombre mediante `aria-labelledby` ni agrega rol de alerta al error (`assets/k3.js:313`); el `<h2>` no debe asumirse suficiente. Conservar modal nativo.
- Mantener entradas tras error y distinguir éxito parcial. Advertir al cerrar solo cuando se perderían cambios.
- Unificar gradualmente confirm/prompt/alert y diálogos propios. Conservar reautenticación y escritura de nombre donde ya se requieren.
- Confirmación proporcional: guardar nombre sin pregunta extra; retirar persona con consecuencias; revocar clave explicando qué instalaciones quedan invalidadas; endurecer con cierre de sesión visible. No añadir confirmaciones a navegación o filtros.

### 2.6 Resúmenes legibles

| Objeto | Ejemplo de resumen propuesto |
| --- | --- |
| Acceso | «Coordinador · Empresa A Y Cali · todas las áreas». |
| Plan | «Básico · heredado del cliente · 3 funciones de persona efectivas». |
| Regla | «Bloquea 3 sitios · Sede Cali · activa; consultar aplicación». |
| Calendario | «Colombia · por país de sede». |
| Equipo | «ACT-0015 · Ana Pérez · última conexión hace 4 min · orden pendiente de confirmación». |

Ejemplos, no datos reales. Nombres antes que UUID, códigos y JSON; detalles técnicos bajo Diagnóstico. Alcance de acceso, destinatario de regla y funciones comerciales son tres decisiones separadas.

### 2.7 Estilo y accesibilidad

Conservar tokens de K3 (`assets/k3.css:1`), fondo claro, acento único para acción principal y estados existentes. No introducir dark/neón ni biblioteca visual nueva. Reutilizar `.tabs`, `.surface`, `.form-grid`, `.badge`, `.notice` y `.dialog-footer`. Separar tareas con espacio/títulos antes de agregar más KPI.

CSS ya tiene foco visible y controles base de 44 px (`assets/k3.css:2`). Preservarlos. Tablas de 12 px y acciones de fila de 32 px (`assets/k3.css:3`) merecen comprobación real con zoom/portátil; no se declara fallo visual medido. En ancho reducido, filtros plegables y scroll localizado, sin desplazar toda la página para llegar a Guardar. Pestañas con teclado/selección, tomando como referencia `assets/coordinator.js:268`. Estados con texto además de color.

## 3. Mockups de las tres pantallas prioritarias

Esquemas propuestos, no capturas ni datos reales. Nombres y cifras ilustrativos. Fondo claro y componentes K3. Acciones condicionadas por permisos.

### A. Organización / Sedes

```text
+--------------------------------------------------------------------------------+
| Cliente: [Grupo AZC v]                          Administración del cliente      |
| Inicio / Organización / Sedes                                                   |
| Organización                                                [+ Nueva sede]     |
| Unidades que se asignan a las personas.                                          |
|                                                                                |
| [Empresas del grupo 3] [SEDES 8] [Áreas 12] [Cargos 24] [Firmas 1]                |
| Vista: (Por tipo)  [Por ubicación]  [Dependencias]                               |
|                                                                                |
| Buscar sede [_____________]  País [Colombia v]  Ciudad [Cali v]                  |
| Estado [Activas v]                                     [Limpiar filtros]        |
| 2 de 8 sedes visibles                                                           |
|--------------------------------------------------------------------------------|
| Sede      Ubicación       Depende de         Personas*  Estado                  |
| Centro    Cali, Colombia  Sin dependencia       36      Activa [Editar] [Más v] |
| Norte     Cali, Colombia  Sin dependencia       14      Activa [Editar] [Más v] |
| * Personas activas visibles para tu cuenta.                                     |
| Las sedes pueden ser compartidas por empresas del grupo.                        |
+--------------------------------------------------------------------------------+

+----------------------------------------------------+
| Nueva sede                                         |
| Cliente: Grupo AZC                                 |
| Nombre *      [____________________________]       |
| País          [Colombia v]                         |
| Ciudad        [Cali________________________]       |
|               Sugeridos por la vista actual        |
| Dependencia   [Sin dependencia v]                  |
|               Buscar por nombre o ruta             |
| El país participa en el calendario automático      |
| de festivos de las personas de la sede.             |
|                          [Cancelar] [Crear sede]   |
+----------------------------------------------------+
```

Guardar conserva Sedes / Colombia / Cali y resalta fila nueva. Si no coincide con filtros, «Sede creada fuera de esta vista» y Verla. Dependencias usa `parent_id`; Por ubicación no crea unidades País/Ciudad. Sin columnas vacías de tipo/ubicación donde no aplican.

### B. Persona / Acceso y plan

```text
+--------------------------------------------------------------------------------+
| Cliente: Grupo AZC                                                              |
| Inicio / Personas / Ana Pérez                                                   |
| Ana Pérez [Activa]                                         [Editar persona]     |
| Empresa: AZC Legal | Sede: Centro, Cali | Área: Servicio                         |
|                                                                                |
| [Datos] [ACCESO Y PLAN] [Actividad] [Equipos 1]                                  |
|--------------------------------------------------------------------------------|
| Acceso al panel                                                [Configurar]    |
| ana@ejemplo.com | Cuenta habilitada | Primer ingreso completado                 |
| Rol: Coordinador                                                               |
| Alcance: personas de AZC Legal Y Cali; todas las áreas                          |
|                                                                                |
| Plan por persona                                                   [Cambiar]   |
| Básico [Heredado del cliente]                                                   |
| Recibe: [funciones efectivas devueltas para este cliente y plan]                |
| El plan no concede permisos para administrar el panel.                          |
+--------------------------------------------------------------------------------+

+-----------------------------------------------------------------+
| Acceso de Ana Pérez                                              |
| Rol (qué puede hacer)       [Coordinador v]                       |
| Alcance (qué datos ve)      [Filtros por organización v]          |
| Empresa del grupo           [AZC Legal x] [+ Seleccionar]        |
| Ciudad                      [Cali x]      [+ Seleccionar]        |
| Sedes                       Todas                               |
| Áreas                       Todas                               |
|                                                                 |
| Verá personas que cumplan TODAS las condiciones anteriores.      |
| Coordinador · AZC Legal Y Cali · todas las sedes de Cali           |
|                                 [Cancelar] [Guardar acceso]     |
+-----------------------------------------------------------------+
```

Sin cuenta: «Todavía no puede iniciar sesión» y Crear cuenta con persona preseleccionada. Rol no finge crear credenciales. En alta, guardar persona primero y ofrecer cuenta como tarea explícita. No se añade conteo autoritativo de personas visibles: una evaluación completa requeriría soporte adicional si no existe.

### C. Registrar equipos / Instalar y revisar

```text
+--------------------------------------------------------------------------------+
| Cliente: Grupo AZC                                                              |
| Inicio / Equipos / Registrar equipos                                            |
| Registrar equipos                                       [Descargar instalador] |
|                                                                                |
| [INSTALAR] [Solicitudes pendientes] [Equipos esperados] [Configuración]          |
|--------------------------------------------------------------------------------|
| 1. Preparar personas       2. Instalar en Windows      3. Comprobar registro     |
| La persona debe existir.   Paquete de este cliente.    Revisar solicitudes      |
| [Ir a Personas]            Genera una clave nueva.    [Ver solicitudes]        |
|                                                                                |
| Instalación masiva: registra personas + series antes de instalar.              |
| [Registrar equipo esperado] [Importar CSV]                                      |
|                                                                                |
| El registro sigue las reglas de aprobación de este cliente.                    |
| [Ver reglas de alta]                                                           |
+--------------------------------------------------------------------------------+

Pestaña Solicitudes pendientes:
+--------------------------------------------------------------------------------+
| Mostrar [Pendientes v]                         Buscar [________________]       |
| Equipo      Persona sugerida  Motivo                    Última vez   Acción    |
| ACT-0015    Ana Pérez         Serie sin coincidencia    Hace 2 min   [Revisar] |
| PORTATIL-8  Sin identificar   Cédula no encontrada      Hace 5 min   [Revisar] |
| [Cargar más solicitudes]                                                       |
+--------------------------------------------------------------------------------+

+------------------------------------------------------------+
| Registrar ACT-0015                                          |
| Serie: PF3ABC12 | Cliente: Grupo AZC                         |
| Motivo pendiente: sin coincidencia de serie                 |
| Persona: [Ana Pérez, cédula terminada en 4050] [Cambiar]     |
| Placa opcional: [ACT_0015____________________]               |
|                                                            |
| Aprobar autoriza registro; falta que el equipo conecte.     |
| [Rechazar...]             [Cancelar] [Aprobar para Ana]      |
+------------------------------------------------------------+

Mensaje persistente fuera de la tabla:
Solicitud aprobada. Esperando conexión. [Ver seguimiento]
```

Descargar abre la reautenticación existente; no obliga a crear clave manual. Buscar solicitudes debe indicar si solo abarca registros cargados; no prometer búsqueda global hasta cargar todos o implementar filtro servidor. Contadores solo completos o explícitamente parciales.
## 4. Lista priorizada de implementación

Estimaciones en días de una persona familiarizada con el código, incluyendo verificación específica; no son compromisos de calendario. Hay dependencias compartidas: no sumar sin planificación. Rutas con el convenio de este documento. Para ampliaciones backend se indica el área a investigar, sin inventar un módulo o endpoint existente.

### Victorias rápidas — cada una ≤ 1 día

| Orden | Prioridad / hallazgo | Entrega y condición de terminado | Esfuerzo | Archivos afectados | Backend |
| --- | --- | --- | --- | --- | --- |
| Q1 | P0 · PER-1 | Buscar campos de entidad aunque celdas sean DOM; verificar nombre/correo, tildes y filtros combinados. | 0,5–1 d | `assets/k3.js` | No |
| Q2 | P0 · ORG-5 | No disponible en vez de cero falso; conteos rotulados como activas visibles. | 0,25–0,5 d | `assets/k3.js` | No |
| Q3 | P0 · CLI-2 | Siguiente paso del cliente: persona antes de cuenta; texto Crear cuenta para enlace que abre creación. | 0,5 d | `assets/k3.js` | No |
| Q4 | P0 · REP-3 | Corregir festivos, leyenda Focus y zona horaria indicada en reportes. | 0,5 d | `assets/coordinator.js`, `partials/layout.php`, `assets/app.js` | No |
| Q5 | P1 · REP-4 | Promedio diario con definición exacta, sin cambiar cálculo. | 0,25 d | `assets/coordinator.js` | No |
| Q6 | P1 · ALT-1 | Acceso al instalador al principio; Crear clave secundaria; nota de clave nueva y reautenticación conservada. | 0,5–1 d | `assets/intake.js`, `assets/k3.css` | No |
| Q7 | P1 · ALT-3/4 | Éxito persistente, refresco tras clave manual y mensaje compatible con aprobación automática. | 0,5–1 d | `assets/intake.js` | No |
| Q8 | P1 · ORG-1/3 | Pestañas iniciales por tipo, crear contextual y Activas por defecto; sin árbol ni persistencia avanzada. | 1 d | `assets/k3.js`, `assets/k3.css`, `organizacion.php` | No |
| Q9 | P1 · CAL-1 | Sin calendario: Crear calendario; año actual y subtítulo correcto. | 0,5 d | `assets/k3.js`, `festivos.php` | No |
| Q10 | P2 · AJU-2 | Atajos según acceso y feedback status/alert en ajustes. | 0,5 d | `assets/k3.js`, `assets/nav-access.js` si se comparte comprobación | No |
| Q11 | P1 · ACC-1 | Explicar Y entre dimensiones y cualquiera dentro de selección; consistencia de Su equipo entre editor y resumen. | 0,5–1 d | `assets/access-scope.js`, `assets/k3.js` | No |
| Q12 | P1 · PLA-3 | Pendiente de implementación visible en catálogo y selección. | 0,5 d | `assets/k3.js` | No |

Q8 debe aplicarse sobre la versión final del agente que está editando `k3.js`; no reemplazar el archivo completo. Ninguna de estas implementaciones se realizó durante esta revisión.

### Mejoras medianas

| Orden | Prioridad / hallazgo | Entrega concreta | Esfuerzo | Archivos afectados | Backend |
| --- | --- | --- | --- | --- | --- |
| M1 | P1 · ORG-2/4 | Dependencias buscables, exclusión de descendientes, geografía contextual y agrupación visual de sedes. | 2–3 d | `assets/k3.js`, `assets/k3.css` | No; relaciones actuales |
| M2 | P1 · transversal | Estado de tabla persistente, limpiar filtros, orden/paginación coordinados y recuperación al guardar. | 2–4 d | `assets/k3.js`, `assets/tablekit.js`, `assets/k3.css` | No |
| M3 | P1 · PER-2/3 | Alta por bloques, catálogos faltantes anticipados, continuación a cuenta preseleccionada y guardado parcial explícito. | 3–4 d | `assets/k3.js`, `assets/k3.css`, `usuarios.php`, `cuentas.php` | No; operaciones separadas |
| M4 | P0/P1 · ALT-1/2/3 | Pestañas de alta, revisión fuera de fila, continuación real `next_offset`. | 2–4 d | `assets/intake.js`, `alta.php`, `assets/k3.css` | No; búsqueda global no incluida |
| M5 | P1 · EQ-1/2/3 | Resumen de equipo, historial disponible en su ficha, actualización de endurecimiento y confirmaciones completas. | 3–4 d | `assets/app.js`, `assets/coordinator.js`, `assets/hardening-settings.js`, `equipo.php`, `assets/k3.css` | No para consultas observadas |
| M6 | P1 · REG-1/3 | Editor progresivo, revisión de destino/efecto y protección de reglas no representables. | 2–4 d | `assets/k3.js`, `assets/k3.css` | No; verificar contrato antes de ampliar campos |
| M7 | P1 · PLA-1/2 | Pestañas, detalle de funciones, diferencias antes de guardar y conteos no disponibles explícitos. | 2–3 d | `assets/k3.js`, `planes.php`, `assets/k3.css` | No; sin simulación global |
| M8 | P1 · ACC-1/2/3 | Alcance por chips, estados de cuenta diferenciados y Roles / Asignación automática. | 2–4 d | `assets/k3.js`, `assets/access-scope.js`, `assets/k3.css` | No para representación |
| M9 | P1/P2 · CAL-1/2/3 | Calendario/año, revisión de selección masiva y semana efectiva del horario. | 2–4 d | `assets/k3.js`, `festivos.php`, `horarios.php`, `assets/k3.css` | No; sin vigencia futura |
| M10 | P1 · REP-1/2 | Fechas inclusivas traducidas al contrato, pestañas, filtros dependientes y estado sin consultar. | 2–3 d | `assets/coordinator.js`, `assets/app.js`, `partials/layout.php`, `reportes.php`, `partials/sidebar.php` | No |
| M11 | P1 · transversal/AJU-1 | Diálogos coherentes, revisión de retención, feedback accesible, foco y retorno al contexto. | 3–5 d | `assets/k3.js`, `assets/app.js`, `assets/intake.js`, `assets/hardening-settings.js`, `assets/k3.css` | No |

### Cambios grandes

| Orden | Prioridad / hallazgo | Entrega concreta | Esfuerzo | Archivos/áreas afectados | Backend |
| --- | --- | --- | --- | --- | --- |
| G1 | P1 · PER-4 | Ficha única Datos / Acceso y plan / Actividad / Equipos; retirar Tier como representación del plan actual. | 5–8 d | `miembro.php`, `usuarios.php`, `assets/coordinator.js`, `assets/k3.js`, `assets/app.js`, `assets/access-scope.js`, `assets/k3.css` | No en principio; reutilizar APIs observadas, conservar guardados separados |
| G2 | P1 · CLI-1/2/3 | Ficha del cliente y puesta en marcha con contexto explícito, cuentas filtradas y estado por etapa. | 5–8 d | `empresas.php`, `cuentas.php`, `assets/k3.js`, `partials/layout-k3.php`, `partials/layout.php`, `partials/sidebar.php`, `assets/k3.css` | No para composición; onboarding persistido en servidor sería ampliación |
| G3 | P2 · ORG-2 | Jerarquía estricta, geografía como entidades y pertenencias compartidas persistentes, solo si se confirma necesidad. | 8–15 d, preliminar | `backend-v4/src/AdminApi.php`, `backend-v4/src/AccessScope.php`, contratos/migraciones por identificar; `assets/k3.js`, `assets/coordinator.js`, `assets/access-scope.js` | **Requiere backend** y migración; no necesario para despejar lista |
| G4 | P1 · ORG-5/ACC-1/REG-2 | Previsualizaciones autoritativas de dependencias, acceso e impacto de reglas/planes; contratos separados. | 6–10 d, sujeto a contrato | `backend-v4/src/AdminApi.php`, `backend-v4/src/AccessScope.php`; área de compilación de políticas por revisar; contratos por definir; `assets/k3.js`, `assets/app.js` | **Requiere backend**; no se verificó endpoint de simulación |

Secuencia práctica: Q1–Q5 para confianza en datos; Q8 + M1 para Organización; M2 como base de listas; M3 + G1 para Personas; Q6–Q7 + M4–M5 para instalación y operación. Seguir con M6–M10 y G2. G3 es opcional y posterior a validar que las agrupaciones frontend no bastan; no debe bloquear el alivio inmediato de Organización.

## 5. Verificación propuesta para la implementación

Pendiente; no se ejecutó durante esta revisión de solo lectura.

1. **Organización:** sede compartida, unidades sin padre, tipos mixtos e inactivas. Crear cargo desde Cargos, editar conservando filtros, País/Ciudad sin duplicar unidades. Cuenta sin lectura de personas nunca muestra cero como total.
2. **Persona:** buscar nombre/correo/documento; alta con catálogos incompletos; cambiar plan sin alterar acceso; guardar datos con fallo del plan; volver a filtro desde ficha. Contrastar local/sincronizada.
3. **Acceso:** all, filters, team, self y un único rol. Empresa A + Cali + Área B explicado como intersección. Confirmar diferencia entre desactivar cuenta y retirar persona.
4. **Alta:** cliente vacío, clave manual, descarga con nueva clave, aprobaciones automática/manual, exclusión de monitoreo y más de 100 pendientes. Diferenciar aprobada de registrada.
5. **Equipo:** sin conexión, agente antiguo, IT sin configurar, endurecimiento en curso/fallido, renombrado pendiente de reinicio. No mostrar aplicado antes del reporte; historial desde equipo.
6. **Reglas/planes:** guardar sin alterar campos no representables; paquete sin propagación retroactiva; retirada de funciones con resumen; permisos insuficientes sin simulación engañosa.
7. **Calendarios/reportes:** calendario propio/por país/predeterminado; selección masiva con filas ocultas; turno nocturno; 31 días inclusivos; festivo no laborable; promedio explicado; CSV consistente con consulta.
8. **Usabilidad:** observar a administrador no técnico creando sede, dando acceso e instalando equipo sin instrucciones verbales. Medir elecciones erróneas de cliente/tipo, retrocesos y tareas no completadas. Revisar teclado, zoom 200 % y ancho de portátil. No atribuir resultados a esta auditoría antes de probarlos.