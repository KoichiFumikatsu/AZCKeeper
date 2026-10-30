# Paso 5 — Clientes, planes, accesos y ajustes técnicos

Maqueta local en español, sin llamadas de red. Abrir administrar.html o clientes.html con doble clic. Corte ficticio: 29 de octubre de 2026, 15:30, America/Bogota. Cierre: 30 de octubre.

Se copió el paso 4 después de cerrar ENCARGO-2.md y pasar node verificar.cjs. ORIGEN-PASO-4.json registra los SHA-256 de esa copia; REFERENCIA-PASO-4.md conserva su documentación. No se copiaron capturas antiguas como validación nueva.

## Diagnóstico por código: cómo es hoy

[Seguro] = código/contrato actual; [Probable] = recorrido deducido sin ejecutar panel; [Suposición] = decisión o datos de maqueta. Referencias relativas a la raíz del repo.

### Discrepancias frente al diagnóstico del 14 de octubre

- [Seguro] Ya existe ficha de cliente con seis pestañas (backend-v4/public/assets/k3.js:1197, backend-v4/public/assets/k3.js:1550) y asistente de cinco pasos (backend-v4/public/assets/k3.js:1581). Se reorganizan y amplían; no son funciones completamente nuevas.
- [Seguro] Autogestión tiene una sola llave desde el 15 de octubre: autogestion_accesos. rbac_self_management es un espejo (backend-v4/src/AdminAccess.php:38, backend-v4/src/FeatureAdmin.php:121). No queda como pregunta pendiente.
- [Seguro] La arquitectura anterior enumera titulos; el catálogo actual utiliza sitios para sitios visitados (backend-v4/src/Features.php:14).
- [Seguro] Ubicación figura pending: true (backend-v4/src/Features.php:20), pero tiene implementación y exige agente **4.0.30** (backend-v4/src/PolicyCompiler.php:10, backend-v4/src/PolicyCompiler.php:192). También necesita una regla habilitada y plan/contrato compatibles (backend-v4/src/PolicyComposer.php:116). Hay que reconciliar la bandera del catálogo.
- [Seguro] Cambio de plan en lote ya ofrece dry_run, versiones por persona y errores parciales (backend-v4/public/assets/k3.js:2016, backend-v4/public/assets/k3.js:2080). Es nueva la presentación del impacto como diferencias de protecciones/equipos/versiones.

### Crear cliente y estructura

[Seguro] Clientes → Dar de alta un cliente usa Datos → Qué compra → Primer administrador → Revisar y crear → Listo. Datos pide nombre, zona, país y primera sede. Qué compra permite paquete, funciones y plan por defecto. Primer administrador propone Corporativo con alcance de todo el cliente, editable. Revisión requiere autenticación reciente (backend-v4/public/assets/k3.js:1587, backend-v4/public/assets/k3.js:1596, backend-v4/public/assets/k3.js:1621, backend-v4/public/assets/k3.js:1640).

[Seguro] POST /tenants/provision crea cliente, contrato, plan, primera sede, persona/cuenta/acceso del administrador en transacción con auditoría. No importa estructura completa. Área/cargo iniciales del administrador son placeholders (docs/architecture/openapi-v4.yaml:6515, backend-v4/src/AdminApi.php:337, backend-v4/src/AdminApi.php:363).

[Seguro] Empresas internas, sedes, áreas y cargos se editan por separado. Las sedes pueden compartirse entre empresas; el árbol de dependencias no es árbol de permisos y mover una unidad no mueve personas ni accesos (backend-v4/public/assets/k3.js:240, backend-v4/public/assets/k3.js:257). GET/POST /organization y PATCH /organization/{id} ya existen (docs/architecture/openapi-v4.yaml:3436, docs/architecture/openapi-v4.yaml:8150). El CSV combinado del nuevo asistente requiere orquestación nueva y reconciliación con fuentes externas.

### Contratación, planes y efecto real

[Seguro] Features declara nivel cliente/persona, permisos, operaciones y reglas (backend-v4/src/Features.php:12). Paquetes son atajos de venta, no planes de personas. La ficha actual guarda funciones y plan por defecto juntos (backend-v4/public/assets/k3.js:1311, backend-v4/public/assets/k3.js:1333).

[Seguro] Funciones efectivas = contrato ∩ plan efectivo. Prevalece el plan propio activo; si no, el plan por defecto activo; sin ambos no hay funciones vendibles. No confundir con el núcleo siempre incluido (backend-v4/src/PersonPlans.php:10, backend-v4/src/PersonPlans.php:20).

[Seguro] Guardar contrato incrementa versiones de autorización y pide recompilación (backend-v4/src/FeatureAdmin.php:122, backend-v4/src/FeatureAdmin.php:127). Se genera una política por equipo (backend-v4/src/PolicyCompiler.php:35, backend-v4/src/PolicyCompiler.php:71) y se excluyen reglas no contratadas/incluidas (backend-v4/src/PolicyComposer.php:12, backend-v4/src/PolicyComposer.php:43). **Guardar, compilar, entregar y confirmar aplicación son estados distintos.** Ubicación necesita además regla habilitada y versión compatible.

[Seguro] Para cuentas de cliente la API responde 403 permission_denied con detalle feature_not_contracted:<código> si falta contrato aunque el rol tenga permiso. Superadmin tiene tratamiento de plataforma (backend-v4/src/AdminAccess.php:18, backend-v4/src/AdminAccess.php:28). /me/access devuelve funciones y permisos filtrados (backend-v4/src/AdminApi.php:658). nav-access.js consulta esa ruta y oculta entradas por permiso, alcance y función; no reemplaza autorización del servidor (backend-v4/public/assets/nav-access.js:19, backend-v4/public/assets/nav-access.js:28, backend-v4/public/assets/nav-access.js:41).

[Seguro] Planes combina catálogo, paquetes, planes y uso por cliente (backend-v4/public/assets/k3.js:3463, backend-v4/public/assets/k3.js:3479). Persona → Acceso y plan guarda cada bloque por separado y advierte que el plan no concede permisos (backend-v4/public/assets/k3.js:2488, backend-v4/public/assets/k3.js:2553, backend-v4/public/assets/k3.js:2560). Cambiar plan de un área hoy es asignar a personas seleccionadas, no crear una herencia del área para futuras altas.

### Roles, alcances y permiso de órdenes

[Seguro] Una persona tiene un rol y un alcance: todo, propio, equipo jerárquico o filtros. Dentro de una dimensión se suman valores; entre dimensiones se cruzan. Filtros sin unidades no equivalen a todo: no ven a nadie (backend-v4/src/AdminAccess.php:47, backend-v4/public/assets/access-scope.js:6, backend-v4/public/assets/access-scope.js:47). Delegación valida acceso actual y nuevo frente al asignante (backend-v4/src/AccessGrants.php:87, backend-v4/src/AdminAccess.php:110).

[Seguro] Órdenes requiere permisos equipos.bloquear, equipos.desbloquear, equipos.reiniciar, equipos.apagar; borrado usa equipos.borrar (backend-v4/src/AdminApi.php:54, backend-v4/src/Features.php:25). El editor guarda los permisos del rol (backend-v4/public/assets/k3.js:4491, backend-v4/public/assets/k3.js:4520); Guardar acceso asigna rol y alcance a la persona (backend-v4/public/assets/k3.js:2521, backend-v4/public/assets/k3.js:2530).

[Seguro] No se identificó operación independiente «supervisor con órdenes». Hoy se modifica el rol o se asigna otro. Editar un rol compartido afecta a todos sus miembros. Para cambiar solo a una persona, usar variante de rol y conservar alcance. [Suposición] La maqueta lo presenta como interruptor individual; implementación debe resolver variantes auditables sin cambiar otros supervisores. La prohibición absoluta de borrado a supervisores es decisión de pasos 2/5 que debe garantizarse en servidor; no se presume una restricción existente basada en nombre del rol.

### Ajustes técnicos y marca

[Seguro] Ajustes coloca seguridad después de retención (backend-v4/public/assets/k3.js:4033). Cuenta IT requiere hardening.gestionar, alcance completo y Endurecimiento; GET no revela contraseña, editar/revelar exige autenticación reciente (backend-v4/public/assets/hardening-settings.js:4, backend-v4/public/assets/hardening-settings.js:14, backend-v4/public/assets/hardening-settings.js:37). Endurecimiento tiene modo manual/automático (backend-v4/public/assets/hardening-settings.js:33).

[Seguro] PIN requiere Órdenes remotas, desbloqueo y alcance completo para cuentas de cliente (backend-v4/public/assets/unlock-pin.js:8). Smart App Control exige desbloqueo con alcance completo, agente 4.0.19+, aplica al reiniciar y no se reactiva desmarcando la solicitud (backend-v4/public/assets/windows-security.js:10, backend-v4/public/assets/windows-security.js:18).

[Seguro] Marca usa PATCH de cliente y carga separada de logo, con versión (backend-v4/public/assets/k3.js:1421, docs/architecture/openapi-v4.yaml:12474). Maqueta edita nombre/color y conserva el logo copiado; no implementa carga de marca.

## Recorridos y clics actuales

[Probable] Estimaciones por código, desde Inicio como Superadmin con cliente elegido. Se cuentan botones, pestañas y selectores; no teclas, scroll ni espera del agente. Cambiar cliente añade abrir/elegir selector. No son mediciones en navegador.

| Tarea | Camino y clics estimados | Pantallas / límites |
|---|---|---|
| (a) Cliente listo para inscribir | Clientes (1) → Dar de alta (2) → Siguiente en Datos (3), Qué compra (4), Primer administrador (5) → Crear (6) → Registrar equipos desde Listo (7). Paquete/plan añaden 2–4. | Lista + asistente + alta. Primera sede y administrador, no estructura completa. Descargar instalador/autenticarse son adicionales. |
| (a), estructura completa | Estructura (1) → tipo (2) → Nuevo (3) → Guardar (4). Empresa, área y cargo añaden aproximadamente 10–12 interacciones según pestaña inicial. | Estructura aparte. Datos gestionados por One se corrigen en su fuente. |
| (b) Contratar Ubicación y entregarla | Clientes (1) → ficha (2) → Funciones y plan (3) → marcar (4) → Guardar (5). | Recompila si plan/regla ya la incluyen. Si falta plan: Personas → selección → edición lote → marcar plan → elegir → Revisar → Aplicar: al menos 7 más. Regla y actualización son recorridos adicionales. Un equipo desconectado no confirma por cantidad de clics. |
| (c) Cambiar plan de área | Personas (1) → abrir/elegir área (2–3) → seleccionar resultados (4) → Editar selección (5) → marcar Plan (6) → abrir/elegir (7–8) → Revisar (9) → Aplicar (10). | Página + diálogo. Varias páginas añaden selecciones; resultados parciales se revisan. Fuentes: backend-v4/public/assets/k3.js:1838, backend-v4/public/assets/k3.js:2061, backend-v4/public/assets/k3.js:2083. |
| (d) Supervisor de área con órdenes | Con variante de rol existente: Personas (1) → ficha (2) → Acceso y plan (3) → elegir rol (4–5) → filtros (6–7) → elegir área (8–9) → Guardar acceso (10). | Si falta variante: Roles → Crear → marcar cuatro permisos de órdenes más consulta → Guardar: al menos 7 adicionales. Crear cuenta también es adicional si no tiene. No editar rol compartido para una excepción individual. |

## Maqueta y decisiones

| Entrada | Recorrido |
|---|---|
| administrar.html | Índice final de cinco pasos; Clientes, Planes, Quién puede hacer qué y Ajustes técnicos. Versiones, salud, auditoría y registros en Más herramientas. |
| clientes.html#ficha | Una pantalla: contrato/no contratadas, estructura, personas/equipos, plan por defecto, supervisores, marca y preparación técnica. |
| clientes.html#nuevo | Datos/primer administrador → estructura importable → funciones → plan/revisión → listo. Cancelación, regreso conservando borrador, validación de duplicados y estructura. |
| clientes.html#contrato | Borrador → impacto calculado → confirmar o volver. Funciones nuevas/retiradas, personas, equipos, controles, versiones y desconexión. Planes propios conservados. |
| planes.html | Tres planes lado a lado, incluido/no incluido/falta contratar, uso y personas. Cambio de área con opción explícita de reemplazar planes propios. Selección actual, no regla para futuras altas. |
| accesos.html | Roles/alcances en llano, edición de empresa/sede/área, dar/quitar órdenes, «Ver como» con personas visibles y capacidad efectiva. Nunca borrado al supervisor; no inicia sesión ajena. |
| ajustes-tecnicos.html | IT, PIN, Smart App Control y endurecimiento separados, riesgo breve, revisión antes/después y autenticación simulada. Descarta contraseñas/PIN. |
| clientes.html#ia y asistente-ia.html | Contratación/activación y configurador heredado de proveedor/instrucciones/payload. Apagado inicialmente; Inicio informa no contratada/apagada. Descontratar apaga. Cliente nuevo sin actividad no recibe payload de otro cliente. |

[Suposición] Se preservan las 300 personas del fixture anterior: 142 tienen plan propio con Ubicación, inicialmente no contratada. Sus 142 equipos son 4.0.20: el ejemplo muestra **142 afectados y 142 que necesitan 4.0.30**. No se repitió 142/12 como texto fijo. Litigios conserva 75 personas; cambiar a Observación retira hasta tres protecciones y calcula cantidad según planes propios.

[Suposición] Primer administrador nuevo = IT de todo su cliente, nunca Superadmin. Se muestra aparte de las personas de trabajo en esta maqueta; en producción provision sí crea su registro de persona. No genera credenciales reales. El enlace al paso 4 lleva nombre, estructura y plan ficticios en URL y abre altas sin personas/equipos del cliente anterior.

CSV de estructura: empresa;sede;area;cargo, una combinación por fila, sin comillas ni delimitadores dentro de nombres. Valida todas las filas, elimina duplicados exactos, no crea personas. Editar estructura agrega unidades sin borrar las usadas ni mover personas. Esto es distinto del CSV **de personas del paso 4**, que conserva guardado parcial de válidas y reintento de inválidas.

Administración conserva datos ficticios en sessionStorage cuando se permite. Si file:// lo impide, UI lo indica: la navegación interna por hash conserva estado mientras dure esa página. Los recorridos heredados son modelos locales de demostración, no un motor común de producción. Un guardado local no confirma aplicación por el agente.

## API existente y trabajo nuevo

Rutas verificadas en contrato; la maqueta no las llama.

| Necesidad | Ya existe | Nuevo / integración |
|---|---|---|
| Cliente | POST /tenants/provision; GET /tenants; GET/PATCH /tenants/{id} (docs/architecture/openapi-v4.yaml:6515, docs/architecture/openapi-v4.yaml:2474, docs/architecture/openapi-v4.yaml:6868) | Estructura múltiple importada, deduplicación/recuperación; provision no acepta hoy el CSV completo. |
| Estructura | GET/POST /organization; PATCH /organization/{id} | Resolver unidades/padres y fuentes externas; reutilizar catálogo. |
| Venta | GET /features; GET/POST /feature-packages; GET/PUT /tenants/{id}/features; GET /tenants/{id}/plan-usage (docs/architecture/openapi-v4.yaml:2921, docs/architecture/openapi-v4.yaml:2981, docs/architecture/openapi-v4.yaml:12212, docs/architecture/openapi-v4.yaml:12341) | Vista previa contractual por equipo/control y confirmación sobre versión revisada. No se identificó ruta de impacto contractual. |
| Planes | GET/POST /person-plans; GET /person-plans/available; PATCH /person-plans/{id}; GET/PUT /users/{id}/plan (docs/architecture/openapi-v4.yaml:3178, docs/architecture/openapi-v4.yaml:3305, docs/architecture/openapi-v4.yaml:3366, docs/architecture/openapi-v4.yaml:8842) | Comparación e impacto. La maqueta compara/asigna; el editor global de catálogo ya existe en servidor. |
| Plan por área | POST /users/batch con dry_run (docs/architecture/openapi-v4.yaml:7684) | Reutilizar versiones y resultados por fila, ampliar explicación de controles. No crear herencia de área implícita. |
| Roles/alcance | GET /me/access; GET/POST /roles; PATCH /roles/{id}; GET /permissions; PUT /users/{id}/access (docs/architecture/openapi-v4.yaml:2883, docs/architecture/openapi-v4.yaml:3840, docs/architecture/openapi-v4.yaml:8582, docs/architecture/openapi-v4.yaml:3960, docs/architecture/openapi-v4.yaml:7894) | Variantes seguras de supervisor, revocación individual auditada y comprobador efectivo. «Ver como» sería consulta autorizada, no entrega de sesión ajena. |
| Cuentas | GET/POST /panel-accounts; PATCH /panel-accounts/{id} (docs/architecture/openapi-v4.yaml:11834, docs/architecture/openapi-v4.yaml:11956) | Integrar sin confundir cuenta, rol, alcance y plan. |
| IT/endurecimiento | GET/PUT /admin/tenants/{id}/hardening; /admin/tenants/{id}/hardening/reveal; /admin/devices/{id}/hardening; /admin/devices/{id}/hardening/command (docs/architecture/openapi-v4.yaml:1264, docs/architecture/openapi-v4.yaml:1378, docs/architecture/openapi-v4.yaml:1435, docs/architecture/openapi-v4.yaml:1489) | Reutilizar autorización, reautenticación, estado por equipo; separar de contrato. |
| PIN/Windows | GET/PUT /admin/tenants/{id}/unlock-pin; POST /admin/tenants/{id}/unlock-pin/reveal; GET/PUT /admin/tenants/{id}/windows-security; POST /auth/reauth (docs/architecture/openapi-v4.yaml:851, docs/architecture/openapi-v4.yaml:968, docs/architecture/openapi-v4.yaml:1024, docs/architecture/openapi-v4.yaml:2312) | Alcance, riesgo, versiones y reporte de aplicación. No registrar secretos en diagnósticos. |
| Marca | PATCH /tenants/{id}; POST /tenants/{id}/logo | Carga/validación de logo y versiones; aquí nombre/color. |
| IA contratable | No está en Features.php; asistente-ia.html es maqueta heredada | Nueva función cliente, guardias API/menú, proveedor/clave seguros, agregación autorizada y envío desde servidor. No inventar endpoint existente. |

## Dudas para Koichi al implementar

1. Variantes Supervisor/Supervisor con órdenes frente a roles personalizados. Prohibición de borrado y facultad de revocar ya decididas.
2. Primer administrador IT del cliente frente al default Corporativo actual; conservar el actual si se implementa antes de decidir.
3. Catálogo comercial/nombres de planes y proveedor de IA. Los planes sintéticos no definen oferta comercial.
4. Reconciliar bandera pendiente de Ubicación con soporte actual. API/Webhooks permanecen deshabilitados en maqueta por su estado pendiente.
5. CSV de estructura y reconciliación con One; no asumir jerarquía exclusiva empresa/sede/área.

No se reabren vigencia de 24 h, CSV de personas parcial, bloqueo previo al retiro ni revocación inmediata del panel: decisiones cerradas del paso 4.

## Verificación

Ejecutar node verificar.cjs. Incluye regresiones anteriores y verificar-administracion.cjs: sintaxis/enlaces, referencias archivo:línea, origen SHA-256, intersección contrato/plan, impacto calculado, rechazo de revisión caducada, alcance/órdenes/borrado, estructura, asistente completo, paso 4 con contexto, estados vacío/error y descarte de secretos. Eventos con DOM mínimo propio, sin navegador ni dependencias.

Pendiente externo: geometría 1366×768 y 375 px, altura de ficha, ausencia real de scroll horizontal, foco nativo y Escape. CSS adaptable revisado por código; no certifica layout. No se generaron capturas.

## Resumen del rediseño (pasos 1–5)

### Mapa final

| Modo/tarea | Pantallas | Resultado |
|---|---|---|
| Supervisor, paso 1 | index.html → persona.html | Buscar persona, hoy/ayer/fecha, actividad, productividad, alertas, equipos y alcance. |
| Administrar | administrar.html | Índice de tareas y regreso claro a supervisar. |
| Órdenes, paso 2 | problemas.html, equipo.html, programacion.html, Acciones en ficha | Revisar efecto, enviar/cancelar, seguir resultado y vencimiento. Entrega no equivale a éxito. |
| Reglas, paso 3 | reglas.html y explicación en ficha | Bloqueos, destinatarios, horarios/excepciones, precedencia y prueba previa. |
| Incorporación, paso 4 | personas-equipos.html y rutas internas | Altas, solicitudes, CSV parcial, protección, reemplazo bloqueado antes del retiro y baja con acceso revocado inmediatamente. |
| Administración, paso 5 | clientes.html, planes.html, accesos.html, ajustes-tecnicos.html, asistente-ia.html | Cliente único, contrato/plan con impacto, permisos comprobables y técnica separada. |

### Desarrollo de servidor/agente priorizado

| Prioridad | Trabajo exigido | Reutilizar/ampliar |
|---|---|---|
| P0 | Separar revocación inmediata de panel de identidad del agente en bajas; proceso durable con confirmaciones, fallo/reintento y auditoría. | Cuentas/personas/comandos existentes; no revocar agente antes de completar. |
| P0 | Reemplazo durable: bloquear viejo → confirmar → retirar; pendientes sin conexión y reglas del nuevo. | Asignación/comandos; orquestación e idempotencia nuevas. |
| P0 | Protección en tres estados con timestamps por control, versión aplicada y umbral 24 h; fallo prioritario. | Security/rules/protection; agregación y evidencia del agente donde falte. |
| P0 | Revocación individual de órdenes y prohibición de borrado a supervisores; servidor valida alcance/contrato/plan. | RBAC/accesos, variantes de rol y pruebas de envío/cancelación tras revocar. |
| P1 | Impacto contractual/plan por persona/equipo: controles, versiones mínimas, desconectados, conflictos y confirmación revisada. | Compilador, catálogo y users/batch dry_run; composición y seguimiento nuevos. |
| P1 | Altas/lotes, solicitudes agrupadas, estructura importable y recuperación parcial sin duplicar; cohorte reciente. | Provision, organization, users y solicitudes; UI y estado durable. |
| P1 | Unificar reglas, excepciones y explicaciones con política efectiva del compilador. | No tratar prioridades «Planeado» como soporte existente; consultar REFERENCIA-PASO-3.md. |
| P1 | Seguimiento de órdenes, cancelación confirmada, vencimiento y capacidades por versión. | Comandos/programación existentes; completar brechas descritas por paso 2. |
| P2 | Resumen diario consistente: jornada, actividad, productividad, alertas, desconocido/error explícitos. | APIs de reportes/actividad/presencia; no inferir asistencia por conexión. |
| P2 | IA contratable y apagada por defecto, proveedor servidor, secretos solo escritura y agregados por alcance. | Nueva función/API/servicio; sin enviar identidades. |
| P2 | «Ver como» de solo consulta efectiva, auditoría y catálogo de capacidades/pending reconciliado. | AccessScope, me/access, roles/funciones; sin suplantación. |

Las maquetas quedan entregadas; integración de frontend, pruebas reales y validación visual pertenecen a la implementación posterior. No se modifica ni despliega servidor o agente.
