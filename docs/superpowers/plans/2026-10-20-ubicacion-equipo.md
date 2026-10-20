# Plan de implementación de ubicación del equipo

**Base aprobada:** [Diseño del 20 de octubre](../../design/2026-10-20-ubicacion-equipo.md), commit `a70b3f9`.

**Objetivo:** registrar ubicación y red del equipo cada 15 minutos y al cambiar de red; reconocer sedes; consultar el historial según permisos y horario; conservar los puntos de equipos perdidos mientras el caso esté abierto.

**Estado al 20 de octubre:** implementación local de servidor, agente y panel. Las casillas marcadas corresponden a código revisado y verificación local; no sustituyen el piloto de Windows. Producción y reglas de los equipos no se han modificado. Véase el registro de implementación al final.

## Alcance y condiciones de entrega

- Captura habilitada únicamente por `location_tracking` y por las funciones contratadas de la persona.
- Coordenadas opcionales, sin estimaciones inventadas ni geolocalización por IP.
- Captura fuera del horario laboral; consulta de esos puntos restringida y auditada.
- Retención de 90 días, con excepción por caso de pérdida o robo abierto.
- Panel con historial, enlace a Google Maps, configuración de sedes y regla por empresa, persona o equipo.
- Quedan fuera las alertas de salida, autorizaciones de traslado y mapas embebidos.
- Cada tarea termina con verificación y un commit descriptivo. El despliegue general depende del piloto.

## Puntos que deben resolverse antes de cerrar el contrato

### Consentimiento de Windows

El requisito de no mostrar interfaz necesita validación real. Microsoft documenta consentimiento por aplicación para ubicación y acceso a BSSID; determinadas llamadas pueden generar un aviso o devolver acceso denegado. Encender `lfsvc` y permitir ubicación a nivel de equipo no demuestra que la sesión pueda leerla silenciosamente. Véase [acceso Wi-Fi y ubicación](https://learn.microsoft.com/en-us/windows/win32/nativewifi/wi-fi-access-location-changes).

`Geolocator.RequestAccessAsync` exige hilo de interfaz y aplicación en primer plano según su [documentación](https://learn.microsoft.com/en-us/uwp/api/windows.devices.geolocation.geolocator.requestaccessasync). La tarea 1 debe comprobar el comportamiento del ejecutable WinForms sin empaquetar que utiliza Keeper. Si hace falta interacción inicial, registrar la discrepancia para ajustar el diseño antes de distribuir; no prometer ausencia de avisos ni intentar ocultar los indicadores del sistema.

### IP de envío frente a red de captura

La IP observada por el servidor es la de recepción del sync. Un punto tomado sin conexión puede enviarse desde otra sede. Aplicar esa IP indiscriminadamente al lote falsearía el historial.

**Propuesta a cerrar en la tarea 1:** guardar por separado hora de captura y recepción, conservar la IP como dato de transporte y usarla para clasificación únicamente cuando exista evidencia de que el punto corresponde a esa conexión. El contrato debe definir esa evidencia y su caducidad. En los puntos históricos sin evidencia se utilizan Wi-Fi y coordenadas capturadas, sin atribuirles la IP actual. Esto precisa la prioridad por red del diseño aprobado.

### Alcance externo y horario

El panel tiene `AdminAccess`, permisos y alcance `all`; la API externa autentica integraciones mediante scopes. Una integración no equivale automáticamente a un administrador de toda la empresa.

**Propuesta:** consulta externa limitada a horario laboral mientras no exista una autorización explícita equivalente para fuera de horario. Si esa consulta externa es necesaria en esta versión, definir su representación en credenciales, revocación y auditoría antes de exponerla. Mantener `location:read` e `include_location=true` como requisitos.

### Casos sin información y coincidencias ambiguas

Concretar antes de implementar:

- Sin sesión interactiva, el servicio puede seguir programando muestras, pero no puede atribuir una posición nueva a `Keeper.Session`. Registrar red disponible y ausencia de coordenadas.
- Sin horario resoluble, propuesta conservadora: `in_work_hours=false`; no ampliar la visibilidad del supervisor.
- Distinguir red desconocida de conexión por cable. Un fallo de lectura Wi-Fi no equivale a cable.
- Precisar si «red sin coincidencia» incluye solo el medio cable o exige un identificador. Propuesta: cable sin IP utilizable ni coordenadas no demuestra estar fuera.
- Si varias sedes comparten IP o SSID, evitar seleccionar una por orden SQL. Definir desempate con BSSID/geocerca y representación de coincidencia ambigua. Un SSID por sí solo puede repetirse.
- Conservar el estado `en_sede` y la sede reconocida aunque sea distinta de la asignada a la persona; mostrar ambas cuando corresponda.

## Integraciones existentes verificadas

Todas las rutas siguientes son relativas a la raíz del repositorio.

| Área | Código actual | Trabajo necesario |
|---|---|---|
| Catálogo y contratación | `backend-v4/src/Features.php`, `PersonPlans.php` | `ubicacion` está pendiente; añadir la regla y comprobar contratación efectiva |
| Políticas | `PolicyCompiler.php`, `PolicyComposer.php`, `EffectiveRules.php`, `AdminApi.php` | Seguir precedencia y corte de versión de `time_change_lock` |
| Contrato | `docs/architecture/openapi-v4.yaml`, `backend-v4/config/contract.json` | Ampliar contrato y regenerar DTO |
| Sync | `backend-v4/src/Application.php`, `Ingest.php` | Nueva colección de puntos y ACK individuales |
| Transporte y cola | `client-v4/src/Keeper.Agent/Transport/SyncClient.cs`, `Storage/DurableOutbox.cs` | Persistencia, lotes, reintentos y ACK para ubicación |
| Sesión | `Keeper.Shared/Contracts/SessionProtocol.cs`, `Keeper.Session/Ipc/SessionBuffer.cs`, `Keeper.Agent/Hosting/SessionSupervisor.cs` | El IPC todavía no transporta ubicación |
| Endurecimiento | `Keeper.Agent/Modules/Maintenance/TimeSync.cs`, `Modules/Security/TimeChangeLockModule.cs` | Reutilizar patrones de dominio, restauración y ejecución de prueba |
| Horarios y alcance | `backend-v4/src/ActivityCalendar.php`, `AdminAccess.php`, `AccessScope.php` | Evaluación puntual y filtro antes de consultar datos |
| API externa | `ExternalAuth.php`, `ExternalApi.php` | Actualmente devuelve coordenadas de `check_ins`, no un historial de equipos |
| Retención | `RetentionWorker.php`, `config/retention-cron.php` | Hoy usa plazos por tipo y `retention_settings.enforce`; ubicación necesita su regla de 90 días |
| Panel | `public/miembro.php`, `equipo.php`, `sedes.php`, `reglas.php`, `assets/k3.js`, `assets/policy-rules.js` | Integrar en las vistas y controladores existentes |

La última migración presente es `0067_command_cancel.sql`; reservar `0068_device_locations.sql` solo si sigue libre al implementar. El agente de referencia es `4.0.28`; usar la siguiente versión disponible, propuesta `4.0.29`, y una única constante de compatibilidad en el servidor.

## Tarea 1 Validar Windows y cerrar decisiones de contrato

**Nuevos archivos propuestos:** prueba aislada bajo `client-v4/tools/` y registro de resultados bajo `docs/design/`.

- [ ] Compilar una prueba mínima con el mismo tipo de aplicación que `Keeper.Session`; determinar el target de Windows y referencias WinRT necesarios sin alterar todavía los proyectos comunes.
- [ ] Probar lectura de coordenadas y SSID/BSSID con permisos permitidos y denegados, usuario estándar, pantalla bloqueada, sin sesión y equipo con cable.
- [ ] Verificar en Windows 11 24H2 o posterior si aparece aviso, qué valores puede gestionar SYSTEM y cuáles pertenecen al usuario.
- [ ] Verificar restauración exacta de servicio y configuración; no modificar políticas de dominio. Documentar conflicto con políticas locales.
- [x] Cerrar las decisiones de IP histórica, integración externa, horario desconocido y redes ambiguas descritas arriba.
- [ ] Registrar versión de Windows, contexto de ejecución, método empleado, resultado y limitaciones reproducibles.

**Aceptación:** hay un camino comprobado para el colector y una decisión explícita sobre cualquier diferencia con «sin interfaz visible». La implementación que dependa de estas decisiones comienza después de resolverlas.

**Commit:** `Ubicación: validar captura Windows y concretar contrato`.

## Tarea 2 Añadir esquema y contrato compatible

**Modificar:** OpenAPI y generadores existentes. **Crear:** migración nueva y pruebas `backend-v4/tests/locations.php`.

- [x] Crear `device_locations` con tenant, UUID de evento, equipo, persona, fecha UTC de captura, recepción, coordenadas/precisión opcionales, medio de red, SSID/BSSID, IP de transporte, resultado, sede, origen de coincidencia e `in_work_hours`.
- [x] Añadir unicidad por tenant, equipo y evento para reenvíos; huella del contenido para detectar un mismo evento con datos distintos.
- [x] Añadir índices por `(tenant_id,user_id,at,id)` y `(tenant_id,device_id,at,id)`; revisar índice de purga según su consulta real. Claves foráneas siempre acotadas al tenant.
- [x] Añadir configuración de reconocimiento asociada a `org_units` de tipo `site`: IPs, Wi-Fi y geocerca. Elegir tablas auxiliares para listas; no crear un catálogo paralelo de sedes.
- [x] Añadir `lost_at`, `lost_by`, `lost_reason` a equipos; referenciar el tipo real de principal que permita representar también al superadmin.
- [x] Definir `LocationPoint`, colección opcional `locations` en sync, `location_acks` opcional y `location_tracking` opcional en política.
- [x] Validar coordenadas emparejadas, precisión no negativa, tamaños, UUID, fechas y límites del lote. Tenant, usuario, IP, clasificación y horario son derivados por el servidor.
- [x] Registrar en OpenAPI las nuevas operaciones administrativas y externas; los nombres de rutas se fijan aquí.
- [x] Regenerar `config/contract.json` y DTO C#; comprobar que regenerar otra vez no produce diferencias.
- [x] Mantener respuestas antiguas sin campos nuevos para clientes que los rechazan, incluidos ACK vacíos y documentos de política.

**Aceptación:** migración en BD vacía y actualizada; contrato valida casos válidos y rechaza inválidos; sync de agente anterior continúa funcionando.

**Commit:** `Ubicación: esquema y contratos compatibles`.

## Tarea 3 Compilar la regla y aplicar contratación

**Modificar:** `Features.php`, `PersonPlans.php` si lo requiere su invalidación, `PolicyCompiler.php`, `PolicyComposer.php`, `EffectiveRules.php`, `AdminApi.php`; pruebas de políticas y funciones.

- [x] Incorporar `location_tracking` con ausencia equivalente a `false`, precedencia empresa/persona/equipo y explicación de la regla ganadora.
- [x] Diferenciar una regla sin valor de una desactivación explícita. Editar otro campo debe conservar el valor existente.
- [x] Permitir asignación solo con `ubicacion` contratada por el cliente; compilación efectiva condicionada también a `PersonPlans::functions`.
- [x] Al retirar la función de cliente o persona, recompilar los equipos afectados y entregar desactivación compatible.
- [x] Emitir el campo únicamente desde la versión compatible; comprobar subida de versión/hash de política al habilitar y deshabilitar.
- [x] Aplicar permisos y alcance reales para editar reglas. No copiar sin revisión la restricción de administrador global de `time_change_lock`.

**Aceptación:** pruebas de precedencia, versión antigua/nueva, plan sin función, retirada de contratación, edición parcial y permisos. Mantener `pending=true` hasta la entrega funcional.

**Commit:** `Ubicación: regla y contratación por persona`.

## Tarea 4 Implementar ingestión y clasificación

**Crear propuesto:** `backend-v4/src/DeviceLocations.php` para ingestión y clasificación. **Modificar:** `Application.php`, `Ingest.php`, `Request.php` y pruebas.

- [ ] Obtener IP del peer o de la cabecera sobrescrita por el proxy de confianza. Comprobar la configuración real de nginx antes del despliegue; ignorar cabeceras enviadas directamente por clientes.
- [x] Ingerir dentro de la transacción y autenticación existentes; identidad de tenant/equipo desde el token y persona correspondiente a la captura según asignaciones disponibles.
- [x] Hacer deduplicación por evento y mantener el punto original en replays. Un fallo de escritura no genera ACK aceptado.
- [x] Clasificar por red válida, luego geocerca con precisión estrictamente menor que el radio, luego `fuera` o `sin_dato`, según las decisiones de la tarea 1.
- [x] Evaluar el horario a la fecha capturada usando zona, horario efectivo, cambios diarios, almuerzo, festivos y ausencias conforme a la productividad. No usar la hora de recepción ni confiar en un booleano del agente.
- [x] Definir ventana de aceptación de puntos atrasados y futuros compatible con retención y equipos perdidos; no heredar sin revisión la ventana de episodios.
- [x] Si la captura ya no está contratada, descartar con ACK terminal conforme al patrón existente para evitar reintentos interminables. Precisar el tratamiento de puntos anteriores a la desactivación.
- [ ] Probar coordenadas en límite de geocerca, precisión igual al radio, IPv4/IPv6, red desconocida, IP histórica y sedes ambiguas.

**Aceptación:** cuatro resultados y sus prioridades cubiertos; aislamiento entre tenants; horas y asignaciones históricas; eventos repetidos y conflicto de contenido; suplantación de cabecera rechazada.

**Commit:** `Ubicación: ingestión y reconocimiento de sedes`.

## Tarea 5 Consultas con alcance y auditoría

**Modificar:** `AdminApi.php`, `AdminAccess.php`, `ExternalApi.php`, `ExternalAuth.php`, `Features.php`, `Audit.php` solo si falta soporte; pruebas admin/externas.

- [x] Añadir consultas paginadas por persona y equipo con fechas, orden estable y último punto visible.
- [x] Requerir `presencia.ubicacion_ver`, contratación y alcance. Filtrar tenant/persona/horario en SQL antes de seleccionar el último punto o construir el resumen.
- [x] Permitir `include_out_of_hours=true` solo a superadmin o acceso completo con permiso; rechazarlo para supervisores.
- [x] Auditar cada consulta ampliada, incluidas páginas sucesivas: actor, persona/equipo, intervalo y request ID. No escribir coordenadas ni redes en auditoría.
- [x] En ficha de equipo reasignado, comprobar autorización de las personas históricas; la asignación actual no concede automáticamente acceso a todo su historial.
- [x] Añadir historial externo sin reemplazar el significado de las marcaciones existentes. Exigir `location:read` e `include_location=true` y la autorización externa definida en tarea 1.
- [x] Revisar la lista blanca del Portal antes de exponer las mismas operaciones por esa puerta.

**Aceptación:** matriz de superadmin, admin completo, supervisor, sin permiso, otra empresa y credencial externa; ningún resumen, contador o «última ubicación» revela puntos ocultos.

**Commit:** `Ubicación: consultas por alcance y auditoría`.

## Tarea 6 Sedes, casos de pérdida y conservación

**Modificar:** operaciones administrativas de sedes/equipos, `RetentionWorker.php`, `TenantPurge.php` y sus pruebas. **Nuevo propuesto:** job de reclasificación si el volumen exige procesarlo fuera de la petición.

- [x] CRUD de redes/geocerca con permiso de edición de organización, alcance de sede y validación de IP, BSSID y radio.
- [x] Sugerir IPs observadas por el servidor para equipos de la sede; no guardar una sugerencia sin acción del administrador.
- [x] Al editar, reclasificar los últimos 7 días del tenant afectado, incluyendo puntos antes asociados a otra sede o sin coincidencia. Mantener captura y horario originales.
- [x] Ejecutar la reclasificación de forma acotada y reanudable si no cabe razonablemente en la petición; evitar resultados antiguos sobrescribiendo configuración nueva.
- [x] Marcar perdido/robado mediante permiso de gestión del equipo y alcance; confirmación escrita y motivo obligatorios. Cerrar caso y auditar ambas transiciones.
- [x] Aplicar purga diaria independiente de los plazos de actividad: `at < ahora - 90 días`, excluyendo todos los puntos de equipos con `lost_at` vigente.
- [x] Resolver explícitamente la interacción con `retention_settings.enforce`: el modo de simulación actual no puede dejar incumplida la retención aprobada sin que el despliegue lo detecte.
- [x] Coordinar purga y apertura de caso para no eliminar puntos de un caso ya abierto. Tras cerrar, los puntos vencidos vuelven a ser elegibles.
- [x] Incluir las tablas nuevas en el borrado integral de tenant respetando las claves foráneas.

**Aceptación:** reclasificación solo de 7 días; purga en lotes a 90 días; excepción por pérdida; cierre de caso; concurrencia; auditoría y borrado integral.

**Commit:** `Ubicación: sedes, casos y retención`.

## Tarea 7 Mantener configuración Windows y restaurarla

**Nuevos propuestos:** módulo `Location` en `Keeper.Agent` y adaptador de configuración Windows. **Modificar:** `Program.cs`, `AgentDataFiles.cs` y ruta de desinstalación/restauración correspondiente.

- [x] Seguir el patrón de módulos y ejecución de prueba. Sin política activa no tocar configuración ni recoger ubicación.
- [x] Persistir valores previos antes de modificar servicio o configuración, conservando también valores originalmente ausentes.
- [x] Cada 2 minutos revisar y reponer únicamente los valores gestionados; reportar `location_restored` cuando realmente se restaure.
- [x] Excluir equipos de dominio con el criterio de `TimeSync`; no sobrescribir políticas administrativas.
- [x] Al desactivar, restaurar estado previo incluso tras reinicio o actualización. Conservar el estado persistido mientras quede una restauración pendiente.
- [x] Integrar restauración al desinstalar y probar fallos parciales sin dejar la configuración marcada falsamente como restaurada.

**Aceptación:** pruebas con adaptadores simulados de activación, reposición, desactivación, reinicio, dominio, ejecución de prueba y error de escritura; validación Windows de tarea 1.

**Commit:** `Ubicación: mantenimiento reversible de configuración Windows`.

## Tarea 8 Capturar y transportar puntos de forma durable

**Nuevos propuestos:** colector Windows en `Keeper.Session/Modules/Location/` y coordinador de muestras en `Keeper.Agent`.

**Modificar:** ambos `Program.cs`, `Modules.cs`, `SessionProtocol.cs`, `SessionBuffer.cs`, `SessionClient.cs`, `SessionSupervisor.cs`, `DurableOutbox.cs`, `SyncClient.cs`.

- [x] Coordinar una muestra por equipo cada 15 minutos y por cambio real de red; evitar duplicación por varias sesiones y eventos repetidos del sistema.
- [x] Pedir posición a la sesión disponible con timeout sin bloquear el intercambio IPC, cuyo timeout actual es de 5 segundos. Captura asíncrona y respuesta en intercambio posterior.
- [x] Registrar timestamp de la medición y limitar antigüedad de posiciones cacheadas. Si no hay lectura válida, emitir coordenadas nulas.
- [x] Recoger SSID/BSSID de la conexión, sin escaneo innecesario, y representar cable/desconocido según contrato.
- [x] Sin sesión, suspensión o ausencia de permiso, conservar la evidencia disponible; no fabricar muestras de periodos en que el equipo estuvo apagado.
- [x] Validar puntos en el borde IPC. Confirmar a sesión solo después de persistir en el outbox.
- [x] Incorporar puntos a selección de lote, límite de bytes, ACK, cuarentena y recuperación de cola. No aplicar la compactación de snapshots diarios a puntos históricos.
- [x] Mantener el cuerpo idéntico en reintentos; integrar corrección de reloj una sola vez conforme al patrón existente.
- [x] Verificar revocación de política con muestras en vuelo y comportamiento de cola al volver a una versión anterior. Definir rollback compatible antes de publicar.

**Aceptación:** pruebas de intervalo, cambio de red, múltiples sesiones, timeout, ausencia de posición, reinicio sin internet, ACK perdido, rechazo parcial, presión de cola y política desactivada.

**Commit:** `Ubicación: captura por sesión y transporte durable`.

## Tarea 9 Integrar el panel

**Modificar:** vistas y controladores existentes de persona, equipo, sede y reglas. **Nuevos propuestos:** módulo compartido `public/assets/location.js` y prueba `backend-v4/tests/location-panel.cjs`.

- [x] Mostrar estado, sede reconocida y hora del último punto visible. Diferenciar «sin dato» de «sin puntos» y de una lectura antigua.
- [x] Historial por día con cambios de estado, razón y selector de fecha de 90 días; paginar sin perder transiciones.
- [x] Enlace Google Maps solo con coordenadas válidas, nueva pestaña y protección `noopener`; mostrar precisión disponible.
- [x] Interruptor de fuera de horario solo para acceso permitido, aviso de auditoría y consulta nueva al cambiarlo.
- [x] Configuración de reconocimiento de sedes e incorporación explícita de IP sugerida.
- [x] Flujo perdido/robado con confirmación escrita y motivo, último punto visible destacado y cierre auditado. La marca no amplía permisos de lectura.
- [x] Regla visible solo con contratación y permiso de edición; mostrar la regla efectiva por equipo.
- [x] Escapar nombres de red, motivo y nombres de sede. No incluir puntos ocultos en HTML, JavaScript ni respuestas.

**Aceptación:** Playwright para persona/equipo/sede/regla, acceso restringido, estados vacíos, cambio de fecha, puntos sin coordenadas, confirmación inválida y cierre de caso.

**Commit:** `Ubicación: historial y administración en el panel`.

## Tarea 10 Verificación integral, piloto y habilitación

- [x] Ejecutar suites PHP y .NET, regeneración estable y pruebas de panel.
- [x] Verificar sync antiguo contra servidor nuevo y agente nuevo con política ausente; documentar que el servidor compatible se despliega antes del agente.
- [ ] Ejecutar flujo completo: activar regla → capturar → desconectar → cambiar red → reconectar → consultar con distintos roles → editar sede → abrir/cerrar caso → purgar.
- [x] Medir tamaño de lote, cola y consultas con unos 2 millones de puntos, orden de magnitud de 90 días a 219 equipos. Validar índices con planes de ejecución.
- [ ] Publicar primero para piloto en portátil Wi-Fi y escritorio con cable; comprobar horario laboral y fuera de horario, reinicio, bloqueo, cierre de sesión y restauración al apagar la regla.
- [ ] Registrar evidencia real de ausencia/presencia de aviso, precisión, permisos y comportamiento de red. Si contradice el diseño, resolver antes de distribuir.
- [ ] Cambiar `Features::CATALOG['ubicacion']['pending']` a `false` al quedar disponible la función y superar el piloto.
- [ ] Preparar despliegue: migración → backend compatible → agente piloto → verificación → distribución. Mantener ubicación desactivada hasta asignar la regla.
- [ ] Probar reversión funcional desactivando la regla y comprobando restauración. No retirar el backend compatible mientras haya agentes o colas con el contrato nuevo.

**Aceptación:** evidencia de suites y piloto; compatibilidad, permisos y retención comprobados. No marcar completa esta tarea solo con pruebas simuladas.

**Commit:** `Ubicación: validar piloto y habilitar la función`.

## Comandos de verificación

Ejecutar desde la raíz salvo indicación contraria. Resolver `php`, `mysqld`, `python`, `node` y `dotnet` contra las instalaciones disponibles; no copiar rutas históricas de otras máquinas.

```powershell
# Exportador PHP: ejecutar desde backend-v4
python tests/export-contract.py
```

```powershell
# Desde la raíz
node client-v4/tools/generate.mjs
dotnet test client-v4/Keeper.sln
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
git diff --check
```

El test Playwright nuevo debe seguir la preparación y ejecución de los `.cjs` existentes; documentar su comando exacto al crearlo. Revisar cualquier cambio en `packages.lock.json` antes de incluirlo: mantener únicamente los requeridos por referencias nuevas comprobadas.

## Orden de ejecución

1. Tarea 1 y decisiones de contrato.
2. Tareas 2 y 3.
3. Tareas 4, 5 y 6.
4. Tareas 7 y 8.
5. Tarea 9.
6. Tarea 10.

La planificación queda completa con este documento. La funcionalidad queda completa únicamente al cumplir los criterios de la tarea 10.

## Registro de implementación y despliegue pendiente

Código inicial: `e5e00ce` (servidor y panel), `0a421e5` (agente, IPC, cola y restauración). `02a8d8c` endurece presión de cola e intervalos de reloj. `b8a6d1e` incorpora recálculo asíncrono, permisos externos y pruebas de volumen.

Decisiones implementadas:

- `location_tracking` está ausente/desactivado por defecto; se compila desde agente `4.0.29`, sujeto a contratación y regla efectiva. Los agentes anteriores conservan contrato y respuesta de sync.
- La IP se obtiene del peer; detrás de un proxy configurado, del último salto de `X-Forwarded-For`, con `X-Real-IP` como alternativa. Antes de desplegar se debe comprobar que nginx sobrescribe/agrega esos campos y que solo ese proxy está autorizado.
- Solo se aplica la IP al punto si el identificador de red capturado coincide con `X-Keeper-Network-ID` del envío y la captura tiene como máximo 120 segundos. Es una correlación del agente, no una certificación de la red. En reintentos el cuerpo permanece idéntico y la cabecera refleja la red actual.
- La API externa nueva es `/ext/v1/members/{id}/locations`, exige `location:read` y `include_location=true`, y siempre filtra horario laboral. Las marcaciones manuales conservan su contrato anterior.
- Horario desconocido: no laboral. Red compartida por varias sedes: en sede sin inventar cuál, salvo geocerca que permita distinguirla. Cable sin IP ni coordenadas: sin dato.
- Tras retirar la contratación o desactivar la regla efectiva, el servidor descarta los puntos nuevos pendientes con ACK terminal, incluso si se capturaron antes. Los ya guardados conservan deduplicación e historial.
- Captura con timeout y coordenadas opcionales. No se solicita consentimiento mediante `RequestAccessAsync`. Los resultados reales de permisos, SSID/BSSID y posición siguen sujetos al piloto.
- La cola conserva puntos entre reinicios. Al alcanzar su cuota, descarta puntos antiguos no enviados con la misma prioridad que episodios; conserva el lote pendiente y resultados de comandos. No garantiza historial ilimitado sin conexión.
- Guardar una sede solo encola el recálculo; no actualiza puntos en la petición. El proceso de fondo confirma lotes de 100 puntos y persiste cursor. Una nueva edición reinicia la ventana de siete días. La retención de ubicación es de 90 días incluso si la retención de actividad está en simulación; se ejecuta después del trabajo de actividad y conserva los casos abiertos.

Orden de despliegue, todavía no ejecutado:

1. Respaldar y aplicar migraciones `0068_device_locations.sql` y `0069_location_reclassification.sql` con el procedimiento existente.
2. Publicar el servidor compatible y comprobar proxy de confianza. Añadir `php config/location-cron.php` cada minuto al cron del servidor, con la misma configuración que los demás trabajos; comprobar salida y errores. Mantener el cron nocturno de retención existente.
3. Construir y firmar el paquete de agente con el procedimiento habitual. El ZIP local de validación es **sin firma**, no un paquete autorizado para distribución.
4. Elegir portátil Wi-Fi y escritorio con cable. Probar usuario estándar, reinicio, bloqueo, cierre de sesión, desconexión/cambio de red, horario, permisos, revocación de regla y restauración exacta. Registrar versión de Windows, avisos del sistema, precisión y limitaciones.
5. Revisar las mediciones locales de volumen y comprobar planes/carga en el entorno de despliegue antes de repartir a toda la flota.
6. Solo después del piloto, habilitar disponibilidad (`pending=false`) y distribuir progresivamente. La regla sigue apagada hasta asignación explícita.

Reversión: desactivar la regla, esperar confirmación de política y restauración de Windows, y dejar drenar las colas. Mantener servidor y tablas compatibles. **No bajar el binario a 4.0.28 con una cola que contenga ubicaciones:** su deserializador estricto no reconoce el campo nuevo. La reversión funcional soportada es apagar la regla manteniendo el agente compatible; cualquier bajada binaria exige verificar previamente que no queden eventos nuevos.

Verificación local de volumen: MySQL aislado, dos millones de puntos repartidos en 90 días y concentrados en una persona/equipo (caso exigente para esas consultas). Historial diario por persona: 499,1 ms; por equipo: 6,7 ms; ambos con acceso por rango indexado. Recálculo de 500 filas: 15.011,1 ms, motivo para mantenerlo fuera de la petición HTTP. Estas mediciones no son una garantía de producción ni una prueba de 219 agentes simultáneos.

Reproducción del ensayo de volumen: definir `KEEPER_TEST_LOCATION_VOLUME=1` solo para el proceso de prueba y ejecutar `backend-v4/tests/mysql-smoke.ps1 -LocationOnly`; el script crea y elimina su propia base aislada. Sin esa variable, la suite no inserta los dos millones de filas.

Verificación final de esta entrega:

| Comprobación | Resultado |
| --- | --- |
| Suite .NET completa | 835 pruebas: 674 agente y 161 instalador |
| Suite PHP completa | 5.046 aserciones aprobadas |
| Ubicación tras retirar el recálculo de HTTP | 75 aserciones aprobadas en base aislada |
| Ensayo de dos millones de puntos | 78 aserciones aprobadas, planes indexados y recálculo reanudable |
| Transporte/IP de proxy | 10 aserciones aprobadas |
| Playwright de ubicación | Historial, mapa, privacidad, pérdida/cierre, regla y sede aprobados |
| Regresión del panel existente | 16 pantallas aprobadas |
| Contrato generado y diferencias | Generación estable y `git diff --check` sin errores |
| Paquete Windows | Compilado; sin firma, solo para validación local |

Durante el cierre aparecieron modificaciones paralelas en `AdminApi.php`, `RoleRules.php`, `tests/smoke.php` y `tests/role-cutoff.php`. Se conservaron fuera de estos commits. Las pruebas anteriores no certifican esas modificaciones posteriores ni su combinación final.

Pendiente para entrega operativa: piloto Windows con equipo Wi-Fi y equipo cableado; firma y publicación del paquete; migraciones, backend, cron y comprobación del proxy en el entorno de destino. No se ejecutó ningún despliegue ni activación de reglas en producción.
