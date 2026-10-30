# Paso 3 · Qué se bloquea

Maqueta local, datos ficticios, español de Colombia, corte 29 de octubre de 2026 a las 15:30, America/Bogota. Abrir `index.html` o `reglas.html` con doble clic. HTML, CSS y JavaScript clásicos; logos y fuente locales; sin red, servicios ni paquetes de ejecución.

Se partió de la copia del paso 2 corregido: ambos modos, 300 personas, tres roles, bandeja agrupada, permiso revocable, órdenes, confirmaciones, seguimiento, programación de una sola fecha y asistente. No se copiaron capturas anteriores como evidencia nueva. El código productivo se leyó sin modificarlo. No hay commits.

## Diagnóstico contra el código actual

Las referencias son relativas a la raíz del repositorio. [Seguro] = observado en código/contrato; [Probable] = recorrido inferido sin navegador; [Suposición] = decisión o dato del prototipo.

### El diagnóstico histórico ya no describe todo el estado actual

- [Seguro] El diagnóstico del 14 de octubre decía que no había una vista del resultado. Hoy **sí existe**: `GET /users/{id}/rules` y `GET /devices/{id}/rules`, descritos en `docs/architecture/openapi-v4.yaml:9473` y `:9525`. `backend-v4/src/EffectiveRules.php:20` utiliza el mismo compilador, devuelve fuentes, ganadores/perdedores, exclusiones por contrato/plan, conflictos, políticas propias y versiones por equipo (`:63`). No se propone otro endpoint equivalente como si faltara.
- [Seguro] La ficha de persona ya tiene pestaña Reglas (`backend-v4/public/assets/k3.js:2155`, `:2394`), y el equipo también (`backend-v4/public/assets/app.js:469`, `:484`). `backend-v4/public/assets/policy-rules.js:139` monta la consulta y ofrece cambios rápidos (`:257`). El problema restante es descubrimiento, dispersión de controles y diferencias entre configuración, versión recibida y efecto real.
- [Seguro] El editor general ya tiene cuatro pasos y conteos aproximados de alcance (`backend-v4/public/assets/k3.js:3244`, `:3291`), pero no una simulación integral previa en el servidor; el cálculo local usa personas/equipos visibles y relaciones del catálogo.
- [Seguro] La versión mínima de ubicación presenta una discrepancia: `PolicyInput.location_tracking` en `docs/architecture/openapi-v4.yaml:21477` todavía menciona 4.0.29; `backend-v4/src/PolicyCompiler.php:10` explica que no se publicó y exige **4.0.30**. La maqueta sigue el compilador.
- [Seguro] El archivo de estilo base citado por el encargo, `backend-v4/public/assets/styles.css`, no existe. Se conserva el estilo claro heredado de la maqueta, basado en el real `k3.css`, con Inter local y logos de AZC Keeper.

### Qué se configura y dónde

| Control | Estado observado y camino actual | Fuente |
|---|---|---|
| Sitios web | [Seguro] Reglas → Crear/Editar o ficha → Reglas → Bloquear/Permitir sitio. Entradas por dominio, excepciones exactas; permitir ganador sale de la lista enviada al agente. | `backend-v4/public/assets/policy-rules.js:71`, `:257`; `backend-v4/src/PolicyComposer.php:98` |
| Aplicaciones concretas | [Seguro] No hay tipo genérico `app` en Rule. El editor no permite seleccionar ejecutables individuales. Clasificar una app o marcarla sospechosa no equivale a impedir su ejecución. | `docs/architecture/openapi-v4.yaml:21392`; `backend-v4/public/assets/policy-rules.js:13`, `:25` |
| USB | [Seguro] Bloquear todo, solo lectura (destino `write`) o permitir como excepción. El agente rechaza horarios y resuelve sus entradas por prioridad; diferentes destinos con igual prioridad pueden ser incompatibles. | `backend-v4/public/assets/policy-rules.js:17`, `:83`; `client-v4/src/Keeper.Agent/Modules/Enforcement/UsbEnforcer.cs:14` |
| Descargas | [Seguro] Bloquear/permitir todo (`*`) desde el editor. La API admite destinos más finos, pero el agente leído exige `*`, efecto permitir/bloquear y sin horario. | `backend-v4/src/AdminApi.php:979`; `backend-v4/public/assets/policy-rules.js:81`; `client-v4/src/Keeper.Agent/Modules/Enforcement/DownloadEnforcer.cs:23` |
| Instalación / AppLocker | [Seguro] Regla global de instalación `*`, no editor por ejecutable. El agente construye y aplica AppLocker; el modo Auditoría registra sin bloquear. No basta con ver una regla de bloqueo guardada. | `backend-v4/public/assets/policy-rules.js:82`; `client-v4/src/Keeper.Agent/Modules/Enforcement/InstallEnforcer.cs:13`, `:35`, `:46` |
| Horarios de uso y franjas de reglas | [Seguro] Rule tiene `schedule_id` y existe `/schedules`; el compilador transporta horarios. El editor no crea condiciones horarias y preserva reglas avanzadas. Web/USB/descargas/instalación rechazan `ScheduleId`; no se debe prometer bloqueo según franja ni confundir jornada de productividad con impedir usar Windows. | `backend-v4/public/assets/k3.js:3344`; `backend-v4/public/assets/policy-rules.js:25`; `backend-v4/src/PolicyCompiler.php:186`; `client-v4/src/Keeper.Agent/Modules/Enforcement/WebEnforcer.cs:15`, `UsbEnforcer.cs:16`, `DownloadEnforcer.cs:24` |
| Cambio manual de hora | [Seguro] Booleano de política, no entrada Rule. Compilador exige 4.0.28; el contrato excluye equipos de dominio y deja libre la zona horaria. El editor general no lo ofrece y guardar sin preservarlo puede retirarlo. | `backend-v4/src/PolicyCompiler.php:8`, `:166`; `docs/architecture/openapi-v4.yaml:21470`; `backend-v4/public/assets/k3.js:3444` |
| Apagado semanal | [Seguro] Flujo separado `/admin/power-schedules`, una fecha o semanal, días, vigencia, hora, destinos, exclusiones, aviso, inactividad, aplazamiento y ventana. No es una orden puntual ni una regla del compositor. | `backend-v4/public/assets/power-schedules.js:153`, `:192`; `docs/architecture/openapi-v4.yaml:14660` |
| Ubicación | [Seguro] Booleano de política. Panel en cliente/persona/equipo. Punto cada 15 minutos/cambio de red dentro de jornada ±30 min; fuera solo perdido/robado. Requiere función y agente compatible. | `backend-v4/public/assets/location.js:77`, `:96`; `backend-v4/src/PolicyCompiler.php:11`; `backend-v4/src/PolicyComposer.php:115` |

### Alcances y combinación real

- [Seguro] API de políticas: cliente, sede, área, persona o equipo. No incluye empresa interna ni cargo como destino directo (`docs/architecture/openapi-v4.yaml:21452`). La instantánea se resuelve con asignaciones vigentes de persona y equipo (`backend-v4/src/PolicyCompiler.php:138`, `:153`). El nivel global se incorpora desde plataforma, no se ofrece como destino de cliente.
- [Seguro] Para **cada par tipo/destino exacto**, gana equipo > persona > área > sede > cliente > global; después, prioridad de la entrada Rule (`backend-v4/src/PolicyComposer.php:9`, `:55`, `:64`). Los destinos distintos se componen; no hay una única regla ganadora para toda la persona.
- [Seguro] En un mismo nivel/prioridad, bloquear y permitir el mismo destino provoca `PolicyConflict`; no gana el último guardado ni el bloqueo. El conflicto se detecta incluso en candidatos que después perderían frente a otro nivel. Si empatan con el mismo efecto pueden conservarse varios orígenes (`PolicyComposer.php:57`, `:68`, `:95`).
- [Seguro] La prioridad de **asignación** no es la prioridad de **entrada**. Cambio de hora y ubicación usan alcance y prioridad de asignación; activado gana en empate (`PolicyComposer.php:32`). El CRUD asigna prioridad nueva creciente al cambiar de destino/crear (`backend-v4/src/AdminApi.php:1020`). La maqueta no ofrece editar esa prioridad como si fuese Rule.priority.
- [Seguro] Permitir web retira solo el destino ganador, no un comodín diferente que también lo cubra (`PolicyComposer.php:98`, `:150`). El motor del prototipo agrupa por destino exacto; no inventa equivalencia de dominios.
- [Seguro] Apagados: unión de destinos y exclusiones que vetan el equipo, con asignaciones vigentes (`backend-v4/src/PowerSchedules.php:260`, `:280`). Si coinciden en el mismo minuto se ejecuta una; reiniciar gana, las otras se reportan reemplazadas. Una cuenta manual puede reemplazar la programada (`client-v4/src/Keeper.Agent/Modules/Devices/PowerScheduler.cs:161`). No sigue la precedencia de las políticas.

### Contrato, plan, permisos y prueba

- [Seguro] La función de una persona requiere compra del cliente y plan propio o por defecto; se calcula en `backend-v4/src/PersonPlans.php:20` y se consulta al compilar (`PolicyCompiler.php:151`). `PolicyComposer.php:43` excluye entradas no incluidas. Persona inactiva queda sin reglas (`PolicyCompiler.php:177`). La arquitectura lo define en `docs/architecture/v4-clientes-funciones-accesos.md`, secciones 2–4.
- [Seguro] Las cuatro funciones vendibles de reglas son web, USB, descargas e instalaciones; no son el permiso para editar. `AdminApi.php:985` verifica contratación para cuentas no plataforma y `:973` el alcance del destino; la ficha usa `reglas.ver` y `reglas.editar` (`policy-rules.js:142`). El nuevo permiso de órdenes del paso 2 no concede edición de reglas.
- [Seguro] Programaciones comprueban **función del cliente** `comandos`, no la misma selección por plan que las reglas de bloqueo (`PowerSchedules.php:267`). La maqueta señala compatibilidad de versión aparte.
- [Seguro] Existe auditoría AppLocker: `AppLockerPolicy.cs:12` usa Auditoría por defecto y `:22` lee `KEEPER_APPLOCKER_MODE`; `InstallEnforcer.cs:11` reporta que no bloquea. También existe ejecución técnica en seco del agente (`RegistryEnforcer.cs:49`). No hay campo por regla de «solo avisar» o «probar primero» en `PolicyInput`/`Rule`. No se usa como evidencia el dry-run de retención o de modificación de personas: son otras tareas.
- [Seguro] La comparación de versiones ya existe (`EffectiveRules.php:106`). Demuestra versión reportada, no ejecución correcta de cada protección. Hay que componerla con `/devices/{id}/security` y el resumen de protección; no declarar aplicado un control que falló o solo audita.

### Clics desde Inicio

[Probable] Conteo por acciones, sin contar escritura, búsqueda, scroll, apertura del selector nativo ni cambio previo de cliente. Sin sesión real ni navegador; variantes por permisos y contenido pueden añadir pasos.

| Tarea | Camino y conteo |
|---|---|
| Bloquear YouTube a X | Personas (1) → X (2) → Reglas (3) → Bloquear sitio (4) → escribir youtube.com → Revisar (5) → Crear regla/Guardar cambio (6). Fuentes: `k3.js:2155`, `:2394`; `policy-rules.js:257`, `:280`, `:306`. Ya existe un atajo de 6 acciones; no afirmar que siempre exige el asistente general. |
| Permitir USB al área Y | Reglas (1) → Crear regla (2) → elegir USB Permitir (3) → Siguiente (4) → alcance Área (5) → elegir Y (6) → Siguiente (7) → Siguiente/Revisar (8) → Crear regla (9). Fuentes: `k3.js:3304`, `:3322`, `:3365`, `:3422`, `:3455`. Si Seguridad está plegado, añadir su apertura; con dos clics por selector nativo, hasta 12. Una regla de persona o equipo puede seguir bloqueando USB. |

## Maqueta y decisiones

- `administrar.html` abre Qué se bloquea. `persona.html` muestra Hoy → Sus equipos → resumen plegable de bloqueos → historial único. `equipo.html` muestra el resultado antes del resto del detalle, origen, regla ganadora, estado del reporte y enlace a Ver la regla / Cambiar para esta persona. Los supervisores consultan solo a su gente y no editan.
- `reglas.html`: catálogo y excepciones buscables; editor Qué → A quién → Cuándo → Impacto; creación/edición reales en memoria, validación, revisión obligatoria, guardar y consultar el resultado sin abandonar la página.
- [Suposición] Grupo AZC ficticio no tiene Descargas; p005 ilustra un plan personal sin web/USB/instalaciones/ubicación. El estado de instalación del ejemplo es auditoría. Se distinguen fallo web, pendiente, reporte antiguo, versión incompatible y programación sin ejecutar.
- [Suposición] Área Litigios abarca las tres empresas: 75 personas y 76 equipos. El supervisor del paso 2 ve Litigios **dentro de AZC Jurídica**, 25 personas. No intercambiar ambos alcances. Tres excepciones USB personales están en el área; cambiar su regla general no las elimina.
- Empresa/cargo: deshabilitados, **Planeado (2)**. Se retira el atajo de selección fija para no confundirlo con herencia dinámica. La selección explícita de personas sigue disponible.
- Excepciones: permitir USB a Valeria hasta el 15 de noviembre (inclusive en Bogotá), otra vence en 3 días y otra no vence; una cuarta excepción, fuera de Litigios, vence hoy con equipo desconectado. Un selector de fecha en el inspector permite comprobar el regreso al bloqueo el día 16. El desarrollo del vencimiento está aprobado. No existe todavía en PolicyInput; la maqueta simula el comportamiento futuro y no escribe al servidor.
- Cuándo: Siempre es operativo para controles actuales; En horario/Franja explican la incompatibilidad y no dejan guardar una regla que el agente rechazaría. Ubicación usa la ventana laboral real, no una franja libre. Horario de uso y apps específicas se muestran deshabilitados con razón.
- Probar primero está visible y deshabilitado con la razón: auditoría es un ajuste técnico del agente, no una opción remota de esta regla. La vista previa es cálculo sin aplicar, no una prueba de bloqueo en Windows.
- Apagado semanal tiene días, hora Bogotá, vigencia opcional, aviso, inactividad, aplazamiento, ventana y exclusión de persona. Se enlaza el flujo de fecha única del paso 2. Es una vista unificada de tareas, conservando la separación real de modelos.
- Cuenta de IT, PIN y Smart App Control quedan en Más herramientas: son configuración de credenciales/Windows, con ciclo de autorización y despliegue distinto; no son condiciones por sitio/USB ni excepciones de una persona. La pantalla completa queda para una fase posterior.
- El modelo local conserva ganadores/perdedores por destino y rechaza empates opuestos. No replica todo el compilador ni los efectos del agente. No presupone que una regla guardada sea recibida o aplicada.

## API existente frente a trabajo nuevo

| Necesidad | API/fuente existente | Qué falta para implementar este diseño |
|---|---|---|
| Origen, conflictos, plan y versiones | `GET /users/{id}/rules`, `GET /devices/{id}/rules` | Reorganizar presentación; agregar información técnica que no esté expuesta (p. ej. procedencia completa del cambio de hora). |
| Crear/editar regla | `GET/POST /policies`, `GET/PATCH /policies/{id}` (`openapi-v4.yaml:3674`, `:8451`) | Preservar todos los campos al editar, control de versión y errores. La maqueta no escribe estos endpoints. |
| Personas, empresa, área, cargo y equipos | `GET /users`, `GET /organization`, `GET /tenants/{id}/devices` | NUEVO: destinos dinámicos empresa/cargo, sus permisos, composición y recompilación; no hay destino directo company/job en PolicyInput. |
| Impacto antes de guardar | Conteos locales en `k3.js:3244`; compositor puro y consultas efectivas actuales | Vista previa autoritativa de un borrador, conflictos, exclusiones y versiones para todo el alcance. No se inventa una ruta de preview existente. |
| Excepción permanente | Regla propia de persona/equipo en `/policies` | Flujo visible y revisión de prioridad; no requiere otro modelo para la excepción sin fecha. |
| Excepción con vencimiento | No hay campo de vigencia en PolicyInput | NUEVO: vigencia temporal, recompilación al vencer, auditoría, sincronización y semántica para equipos desconectados. |
| Confirmar aplicación | `GET /devices/{id}/security`, `GET /tenants/{id}/protection`, versiones en `/rules` | Unir reporte/versión/estado del control sin confundir recibido con aplicado. |
| Horarios y bloqueo según franja | `GET/POST /schedules`, `GET/PUT /schedules/{id}`, Rule.schedule_id | NUEVO soporte efectivo en los módulos del agente y editor; no basta cambiar la interfaz. |
| Apagados semanales | `GET/POST /admin/power-schedules`, `GET/PATCH/DELETE /admin/power-schedules/{id}`, `GET /admin/power-schedules/{id}/runs` | Integrar entrada y resultados en esta tarea, sin convertirlos en órdenes puntuales. |
| Ubicación | Booleano en `/policies`; `GET /users/{id}/locations`, `GET /devices/{id}/locations` | Mantener restricciones laborales, versión mínima real y permisos; corregir después la divergencia documental de versión. |
| Prueba sin bloqueo | Auditoría AppLocker / ejecución en seco configuradas en agente | No existe una opción remota uniforme por regla. Si se quiere, requiere contrato y soporte específico nuevo. |

## Verificación y recorrido de aceptación

Ejecutar `node verificar.cjs`. Incluye las regresiones del paso 2; archivos/enlaces locales; sintaxis; conteos de impacto calculados; prioridad, empate, excepción vigente/vencida; plan y contrato; empresa/cargo como selección; múltiples personas; compatibilidad, creación/edición, exclusiones de apagado y ausencia de llamadas de red. Las referencias de código del diagnóstico también se validan contra archivos y líneas existentes.

Sin navegador por instrucción expresa: no se acredita geometría ni interacción DOM real. Validación visual/teclado pendiente para Claude a 1366×768 y 375 px:

1. Inicio → Valeria: resultado, origen de YouTube, excepción USB con fecha; abrir los dos equipos y comparar fallo web / reporte antiguo / versión incompatible.
2. Administrar → Qué se bloquea → editar Protección de archivos: seguir los cuatro pasos y leer personas/equipos, excepciones y plan excluido antes de guardar.
3. Crear web para una persona; revisar impacto, confirmar y guardar; consultar su resultado en la misma página. Recargar restaura los ejemplos.
4. Crear permiso youtube.com para Litigios, prioridad 0: conflicto con Redes y video. Volver, cambiar prioridad a 1 y revisar que gane; no hay guardado mientras siga el conflicto.
5. Comprobar Empresa y Cargo deshabilitados con Planeado (2), razón visible; Personas permite selección explícita.
6. Inspector: Valeria al 15 nov y 16 nov; excepción vigente y vencida. Editar excepción para quitar vencimiento o cambiar fecha.
7. Cuándo: horario/franja deshabilitados con Planeado (1) y razón visible. Comprobar Descargas no contratado, apps/horario de uso no disponibles y Probar primero sin soporte remoto.
8. Apagado semanal: elegir días/hora, persona excluida, aviso/ventana; revisar equipos antiguos; guardar y comprobar resultado pendiente, enlazar fecha única.
9. Roles: supervisor y supervisor con órdenes sin edición de reglas, incluso por URL; mantener alcance y revocación del paso 2. Escenarios vacío/error, filtros sin resultados y regla inexistente.
10. Móvil sin scroll horizontal; foco visible, Escape en diálogos de órdenes; botón Enviar visible en escritorio y revisión de lotes.

## Cierre aprobado el 30 de octubre de 2026

La fecha de demostración se mantiene en 29 oct a las 15:30 para conservar las regresiones. El historial de persona reúne sus equipos, ordena por fecha descendente y muestra tres órdenes; «Ver todo el historial» abre las anteriores. El resumen cuenta bloqueos configurados, no controles confirmados en Windows.

### Excepciones con fecha: desarrollo aprobado

[Seguro] `docs/architecture/openapi-v4.yaml:21439` y `backend-v4/src/AdminApi.php:966` no exponen vencimiento. [Suposición de implementación] agregar campo nullable de fin y zona del cliente en almacenamiento, contrato, lectura/escritura y auditoría. La fecha elegida incluye el día completo en America/Bogota; se vuelve a la regla heredada a las 00:00 del día siguiente. La excepción puede quedar sin fecha.

[Suposición de implementación] una tarea idempotente deberá detectar vencimientos, invalidar la asignación, recompilar todos los equipos afectados (incluidos los desconectados), registrar resultado y reintentar fallos. Reutilizar el mecanismo de cola/recompilación de `backend-v4/src/AdminApi.php:73` y la compilación auditada de `backend-v4/src/PolicyCompiler.php:72`; hoy la selección de asignaciones en `backend-v4/src/PolicyCompiler.php:155` no filtra fechas. Registrar creación, cambio de fecha, vencimiento, versión calculada y aplicación pendiente/confirmada. No borrar la evidencia al vencer.

No se requiere un nuevo campo en el agente si el servidor entrega una política efectiva nueva por la sincronización existente. Un equipo sin conexión conserva lo recibido: «Vence hoy; se aplicará cuando el equipo vuelva a conectarse». No equivale a una revocación local instantánea. Garantizar el vencimiento sin conexión sería otro desarrollo en el agente; no está prometido aquí.

### Capacidades planeadas, en orden aprobado

| Prioridad | Servidor | Agente |
|---|---|---|
| 1. Web solo en horario/franja | [Seguro] Rule.schedule_id y horarios ya viajan: `backend-v4/src/PolicyCompiler.php:185`, `backend-v4/src/PolicyComposer.php:104`. [Suposición] validar zona, cruce de medianoche y conflictos temporales; conservar permisos condicionados al componer (hoy se elimina el permiso web ganador). Versionar compatibilidad y ampliar vista previa. | [Seguro] `client-v4/src/Keeper.Agent/Modules/Enforcement/WebEnforcer.cs:15` rechaza horario. [Suposición] evaluar franjas y reactivar/restaurar listas en sus límites, arranque, reanudación y cambio de política; reportar estado dentro/fuera de franja. |
| 2. Empresa y cargo como destinos | [Seguro] `backend-v4/src/PolicyCompiler.php:155` solo incluye cliente/área/sede/persona/equipo; `backend-v4/src/PolicyComposer.php:9` fija precedencias. [Suposición] ampliar modelo de asignación, contrato, permisos (`backend-v4/src/AdminApi.php:970`), consulta de organización y recompilación por movimientos; resolver precedencia antes de producción. | [Probable] ninguna nueva ejecución si recibe el resultado ya compuesto. Verificar protocolo/procedencia y compatibilidad; no necesita resolver organización localmente. |
| 3. Probar primero por regla | [Seguro] `docs/architecture/openapi-v4.yaml:21392` no tiene modo de prueba por Rule. [Suposición] agregar modo de ejecución, composición, auditoría, telemetría y lectura de resultados; mantener modo al editar. | [Seguro] auditoría actual es global de AppLocker (`client-v4/src/Keeper.Agent/Modules/Enforcement/AppLockerPolicy.cs:12`), no por regla. [Suposición] actualizar protocolo (`client-v4/src/Keeper.Shared/Policy/Policy.g.cs:53`) y módulos para observar intentos sin aplicar bloqueos; el bloqueo web por registro no registra por sí mismo intentos. Definir esa instrumentación y reportar compatibilidad. |
| 4. Apps concretas por ejecutable (mayor costo) | [Seguro] Rule no tiene tipo app (`docs/architecture/openapi-v4.yaml:21392`). [Suposición] contrato de ejecutables/identidad, excepciones, prioridad, impacto, permisos, compilador y versión mínima. | [Seguro] instalación actual usa una regla global y aplica un XML AppLocker (`client-v4/src/Keeper.Agent/Modules/Enforcement/InstallEnforcer.cs:18`, `client-v4/src/Keeper.Agent/Modules/Enforcement/InstallEnforcer.cs:35`). [Suposición] generar reglas por ejecutable, validar disponibilidad de Windows, impedir bloquear Keeper/IT, aplicar/restaurar y distinguir auditoría de bloqueo real. Un nombre de archivo solo no ofrece identidad robusta. |

Las cuatro prioridades permanecen deshabilitadas con su razón y etiqueta Planeado (1)…(4). No se implementó código productivo.

## Dudas restantes para Koichi

- Precedencia exacta entre empresa, área, sede y cargo cuando se crucen reglas; no se inventa un nivel operativo.
- Identidad de ejecutables (ruta, hash o editor) y alcance de la observación del modo de prueba.
- Vista previa autoritativa del servidor y procedencia completa del cambio de hora.

Vencimiento, comportamiento desconectado y orden de prioridades ya están decididos; no se solicita aprobarlos de nuevo.
