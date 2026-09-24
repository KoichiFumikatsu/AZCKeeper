# Validación estática y DOM de panel-v4

172 comprobaciones aprobadas; 0 fallos.

Pruebas Node + JSDOM desde archivos locales. No se invoca red ni navegador; los diálogos nativos y descargas se simulan en DOM. **No demuestra layout, reflow, zoom ni recorrido nativo de teclado.** R-03/R-32/R-35 permanecen PENDIENTES de agent-browser por el cliente.

## Contrastes calculados

| Uso | Texto / borde | Fondo | Ratio |
|---|---|---|---|
| Texto perla | #303030 | #E4E4E4 | 10.38:1 |
| Secundario perla | #505050 | #E4E4E4 | 6.34:1 |
| Secundario hover | #505050 | #D9D9D9 | 5.71:1 |
| Acento perla | #245D68 | #E4E4E4 | 5.81:1 |
| Acento hover | #245D68 | #D9D9D9 | 5.24:1 |
| Botón principal | #F8F8F8 | #245D68 | 6.96:1 |
| Aviso | #303030 | #F8F8F8 | 12.43:1 |
| Control perla | #686868 | #E4E4E4 | 4.38:1 |
| Control hover | #686868 | #D9D9D9 | 3.95:1 |

## Comprobaciones aprobadas

- Veinte páginas exactas: doce existentes y ocho placeholders
- JavaScript local compila
- login: DOM, idioma, título y contenido inicial
- login: referencias relativas resueltas desde disco
- login: labels, botones, muestras y una primaria de contenido
- index: DOM, idioma, título y contenido inicial
- index: referencias relativas resueltas desde disco
- index: labels, botones, muestras y una primaria de contenido
- index: estado empty con salida
- index: estado loading con salida
- index: estado error con salida
- equipos: DOM, idioma, título y contenido inicial
- equipos: referencias relativas resueltas desde disco
- equipos: labels, botones, muestras y una primaria de contenido
- equipos: estado empty con salida
- equipos: estado loading con salida
- equipos: estado error con salida
- equipo: DOM, idioma, título y contenido inicial
- equipo: referencias relativas resueltas desde disco
- equipo: labels, botones, muestras y una primaria de contenido
- equipo: estado empty con salida
- equipo: estado loading con salida
- equipo: estado error con salida
- usuarios: DOM, idioma, título y contenido inicial
- usuarios: referencias relativas resueltas desde disco
- usuarios: labels, botones, muestras y una primaria de contenido
- usuarios: estado empty con salida
- usuarios: estado loading con salida
- usuarios: estado error con salida
- miembro: DOM, idioma, título y contenido inicial
- miembro: referencias relativas resueltas desde disco
- miembro: labels, botones, muestras y una primaria de contenido
- miembro: estado empty con salida
- miembro: estado loading con salida
- miembro: estado error con salida
- reglas: DOM, idioma, título y contenido inicial
- reglas: referencias relativas resueltas desde disco
- reglas: labels, botones, muestras y una primaria de contenido
- reglas: estado empty con salida
- reglas: estado loading con salida
- reglas: estado error con salida
- reportes: DOM, idioma, título y contenido inicial
- reportes: referencias relativas resueltas desde disco
- reportes: labels, botones, muestras y una primaria de contenido
- reportes: estado empty con salida
- reportes: estado loading con salida
- reportes: estado error con salida
- ajustes: DOM, idioma, título y contenido inicial
- ajustes: referencias relativas resueltas desde disco
- ajustes: labels, botones, muestras y una primaria de contenido
- ajustes: estado empty con salida
- ajustes: estado loading con salida
- ajustes: estado error con salida
- roles: DOM, idioma, título y contenido inicial
- roles: referencias relativas resueltas desde disco
- roles: labels, botones, muestras y una primaria de contenido
- roles: estado empty con salida
- roles: estado loading con salida
- roles: estado error con salida
- empresas: DOM, idioma, título y contenido inicial
- empresas: referencias relativas resueltas desde disco
- empresas: labels, botones, muestras y una primaria de contenido
- empresas: estado empty con salida
- empresas: estado loading con salida
- empresas: estado error con salida
- states: DOM, idioma, título y contenido inicial
- states: referencias relativas resueltas desde disco
- states: labels, botones, muestras y una primaria de contenido
- turnos: DOM, idioma, título y contenido inicial
- turnos: referencias relativas resueltas desde disco
- turnos: labels, botones, muestras y una primaria de contenido
- tiers: DOM, idioma, título y contenido inicial
- tiers: referencias relativas resueltas desde disco
- tiers: labels, botones, muestras y una primaria de contenido
- mensajes: DOM, idioma, título y contenido inicial
- mensajes: referencias relativas resueltas desde disco
- mensajes: labels, botones, muestras y una primaria de contenido
- soporte: DOM, idioma, título y contenido inicial
- soporte: referencias relativas resueltas desde disco
- soporte: labels, botones, muestras y una primaria de contenido
- tareas: DOM, idioma, título y contenido inicial
- tareas: referencias relativas resueltas desde disco
- tareas: labels, botones, muestras y una primaria de contenido
- casos: DOM, idioma, título y contenido inicial
- casos: referencias relativas resueltas desde disco
- casos: labels, botones, muestras y una primaria de contenido
- recepcion: DOM, idioma, título y contenido inicial
- recepcion: referencias relativas resueltas desde disco
- recepcion: labels, botones, muestras y una primaria de contenido
- contratar: DOM, idioma, título y contenido inicial
- contratar: referencias relativas resueltas desde disco
- contratar: labels, botones, muestras y una primaria de contenido
- Sin red, fuentes externas ni dependencias remotas
- CSS elegido conservado como prefijo literal
- Navegación Admin: seis secciones y empresa fija
- Super admin: selector y acceso a varias empresas
- Tenant forzado en URL no cambia Admin Empresa
- Equipo de otro tenant no se resuelve desde URL
- Gerencia lectura: acciones ocultas, sin controles grises
- Coordinación: intersección área y sede
- IT: empresas asignadas, no puede editar gate
- Colaborador: login rechazado y acceso directo sin permisos
- Filtrado de equipos, sin resultados y recuperación
- Búsqueda global recortada a tenant y permisos
- F3 bloquear: nombre exacto, motivo, cola y respuesta
- F3 desbloquear: nombre exacto, motivo, cola y respuesta
- F3 apagar: nombre exacto, motivo, cola y respuesta
- F3 borrar: nombre exacto, motivo, cola y respuesta
- F3 cancelar conserva estado y restaura foco DOM
- F3 modelo rechaza acciones sin permiso y en otro tenant
- F2 alta: código, instrucciones y aparición del equipo
- F4 restricciones seguras, dominios y horario validados
- F5 alta completa, empresa fija y asignación por selección
- F5 baja guiada: release
- F5 baja guiada: reassign
- F5 no permite reasignar fuera de empresa
- F6 exporta agregados de una sola empresa
- F7 preview en vivo, contraste bajo rechazado y guardado aislado
- Claves API ficticias: crear y revocar con confirmación
- RBAC: ambos gates, revocación y rechazo de autoactivación
- RBAC: nuevo rol vacío, no autoescalada ni permiso ajeno
- RBAC: alcance no puede ampliarse al crear rol
- RBAC interfaz: habilitar gate y delegar meta-permiso entre páginas
- RBAC interfaz: crear, clonar, desactivar rol sin perder auditoría
- RBAC rol asignado exige reasignación, no borrado
- Catálogo global editable y semillas aplicadas a nuevo tenant
- Nueva empresa nace con gates y reglas apagados
- Escape cierra menú móvil y devuelve foco DOM
- CSS: estados, foco, targets, adaptación y preferencias
- Contraste Texto perla
- Contraste Secundario perla
- Contraste Secundario hover
- Contraste Acento perla
- Contraste Acento hover
- Contraste Botón principal
- Contraste Aviso
- Contraste Control perla
- Contraste Control hover
- Inicio: cuatro KPI, 20/24, charts, alertas y roster
- SVG inline: títulos, descripciones, geometría finita y alternativas
- Series 30 días: suma de clasificados y tiempos posibles
- Reportes: 7/30 días, ranking ordenado y sin tablas
- CSV y gráficos usan la misma serie y alcance
- Donut: porcentajes suman cien para fixtures y foco es proporcional
- Usuarios: cada nombre enlaza al miembro con sesión/tenant y tier
- Miembro: cuatro vistas accesibles y sin módulos ajenos
- Miembro: frontera de tenant, área y permiso en acceso directo
- Miembro: progressive disclosure también dentro de pestañas
- Miembro: guardrails reutilizados para bloquear
- Miembro: guardrails reutilizados para apagar
- Miembro y gráficos: estados mantienen persona, pestaña y periodo
- Sin fixtures de actividad para personas recién agregadas
- Feed: evento manual, persiste solo en el tenant y no altera métricas
- Datos locales anteriores se enriquecen sin borrar roles o comandos
- Gráficos sin puentes sobre días desconocidos
- Contraste de series sobre perla y relleno de área
- Núcleo: doce contenidos main intactos y cinco fuentes sin cambios
- Navegación nueva: núcleo primero y dos grupos de cuatro
- Placeholder turnos: título, estado, preview honesto y menú actual
- Placeholder tiers: título, estado, preview honesto y menú actual
- Placeholder mensajes: título, estado, preview honesto y menú actual
- Placeholder soporte: título, estado, preview honesto y menú actual
- Placeholder tareas: título, estado, preview honesto y menú actual
- Placeholder casos: título, estado, preview honesto y menú actual
- Placeholder recepcion: título, estado, preview honesto y menú actual
- Placeholder contratar: título, estado, preview honesto y menú actual
- Visibilidad de stubs por permiso y rechazo en acceso directo
- Permiso de un rol editado afecta tanto menú como stub
- Tiers: Admin consulta, IT por permiso central, Gerencia no recibe acceso implícito
- Stubs conservan empresa fija y no activan funciones futuras
- CSS nuevo se limita a placeholders y conserva tokens y foco existentes
- Diez archivos del original intactos por SHA-256

## Fallos

Ninguno.
