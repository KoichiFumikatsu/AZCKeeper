# Propuesta 2 — supervisión, alertas y asistente de IA

29 de octubre de 2026. Responde B1 y B2 de [ENCARGO-2.md](ENCARGO-2.md) y concreta las correcciones A. [Seguro] es evidencia del contrato/código o del verificador; [Suposición] es una decisión propuesta para producto. Las cifras de esta carpeta son sintéticas.

## 1. Dashboard y flujo del supervisor

[Suposición] Arriba mostraría **tres indicadores**: productividad medida hoy, llegadas fuera del margen y personas con alertas. La comparación semanal ocupa una línea dentro del primero. Los cuatro contadores de conexión quedan en una franja compacta. No hacen falta gráficos para decidir a quién abrir.

1. Leer alcance, fecha y cobertura. El superadmin empieza con todos los clientes autorizados; el supervisor, con su área. La maqueta tiene un único cliente y fija el corte en 29 oct, 15:30, Bogotá.
2. Ver si hay alertas altas o un cambio semanal que merezca revisión. La lista ya viene por atención. Buscar a alguien sigue siendo la acción principal de Inicio.
3. Acotar por cliente → empresa → área/sede, o buscar por nombre. KPIs, estados y sugerencias se recalculan para ese conjunto completo, antes de paginar.
4. Abrir una persona o la alerta. La ficha reúne jornada, tiempos, uso, evidencia y equipos. Volver conserva filtros, orden, página y selección del asistente.
5. Abrir el bloque plegado del asistente si se necesita una explicación breve o un informe. «Ver personas» aplica una selección determinista a la lista, limpia el filtro de estado para evitar ocultar miembros y conserva organización/búsqueda. «Generar informe» abre un borrador revisable y descargable como HTML local. «Descartar» oculta esa sugerencia hasta recargar o cambiar el conjunto de personas.

[Seguro] [index.html](index.html) implementa ese recorrido. El bloque del asistente comienza plegado para que no compita con las personas. En móvil los tres KPIs se apilan, los estados usan dos columnas y las filas se convierten en tarjetas. La verificación visual a 1366×768 y 375 px queda para Claude, según el encargo.

### KPIs generales: definición y disponibilidad

Todas las rutas siguientes son claves **existentes** de `docs/architecture/openapi-v4.yaml`, con prefijo de servicio `/v1`. Las nuevas composiciones están marcadas; no se afirma que exista un endpoint de dashboard nuevo.

| Indicador | Definición exacta y tratamiento de faltantes | API actual / trabajo nuevo |
|---|---|---|
| Productividad medida hoy | `min(100, 100 × ΣP / ΣJ)`. P = tiempo productivo dentro de jornada; J = jornada esperada transcurrida, descontando almuerzo, festivos y ausencias aprobadas. Solo personas con medición válida y J > 0. J = 0 no aplica; fallo de medición se excluye y se informa cobertura; día laborable confirmado sin actividad aporta P = 0 y su J. No promediar porcentajes individuales. | EXISTEN `totals.workday_productive_seconds`, `totals.workday_expected_seconds`, `totals.productivity_percent` en `GET /reports/people?from=2026-10-29&to=2026-10-30` (`:11748`, esquema `:17889`, fórmula `:17961`). NUEVA composición ponderada y contrato explícito de cobertura/frescura. |
| Cambio semanal | Misma fórmula, acumulada en jornadas cerradas comparables: lunes–miércoles 26–28 oct contra lunes–miércoles 19–21 oct. Misma cohorte con datos y jornada válida en ambos períodos; filtros actuales fijos. Delta = porcentaje actual − anterior, en **puntos porcentuales**. No comparar jueves incompleto con una semana completa. Sin denominador o cohorte válida: «Sin comparación». | Totales EXISTEN mediante `GET /reports/people?from=2026-10-26&to=2026-10-29` y `?from=2026-10-19&to=2026-10-22`. NUEVA composición para cohortes, cobertura por día y corte reproducible. Cada ventana cabe en el máximo de 31 días. |
| Llegadas fuera del margen | Número de personas con primera actividad posterior al límite de llegada del día; se muestra también cuántas tienen primera actividad y jornada válidas. En el ejemplo: entrada 08:00, límite 08:15; 08:15 es a tiempo, 08:16 tarde. Sin hora no se inventa puntualidad. | EXISTEN `first_activity`, `arrival_deadline_at`, `status` y horario en `GET /reports/presence?day=2026-10-29`, `GET /schedules/settings` (`:5348`, `:414`, `PresenceRow :24889`). NUEVO agregado del alcance. |
| Personas con alertas | Cantidad distinta de personas con ≥1 alerta de la taxonomía de §2, pendiente de revisión según cada fuente; subcifra de personas con ≥1 alerta alta. Una persona puede estar en varios tipos, pero cuenta una sola vez en cada cifra. | EXISTEN fuentes de presencia, seguridad y cumplimiento; NUEVO contrato unificado, deduplicación, fecha, vigencia y estado de revisión. `/dashboard/problems` (`:663`) no garantiza por sí solo las cinco alertas. |
| Conectadas / sin actividad reciente / sin conexión | Última señal de los equipos activos asignados: ≤5 min; >5 y ≤15 min; >15 min. Usar la señal más reciente por persona, no sumar equipos. Sin equipo se incluye en sin conexión con aclaración visible. No representa presencia humana. | EXISTEN equipos y señal en `GET /tenants/{id}/devices` (`:7073`) y resumen en `GET /dashboard` (`:2800`). NUEVA agregación por persona y filtro. «Con alertas» es superpuesto. |

[Suposición] No añadiría una tendencia semanal de conexión ni de protección ahora: se necesitarían snapshots históricos y la API citada ofrece el último estado. Puntualidad histórica puede componerse desde presencia, pero no hace falta una cuarta tarjeta arriba. El total activo del equipo es calculable, aunque el tamaño del equipo lo hace poco útil como titular.

[Seguro] Los contadores y KPIs siguen búsqueda y filtros de organización. Estado y selección del asistente restringen **solo la lista**, y se explica debajo. Al fallar la carga, se muestran guiones y no hay sugerencias. Con conjunto vacío, conteos cero pero productividad y tendencia sin datos. En la ficha se mantiene «Sin jornada» distinto de «Sin datos».

### KPIs por persona

| Lugar / dato | Definición | API existente y límites |
|---|---|---|
| Lista: estado ahora y señal | Estado por última señal, con aclaración si no tiene equipo. | `GET /tenants/{id}/devices`; composición descrita arriba. |
| Lista y ficha: llegada / horario | Primera actividad observada frente al horario efectivo y su límite. No es registro de asistencia. | `GET /reports/presence`, `GET /schedules/settings`, `GET /users/{id}`, `GET /schedules` (`:7760`, `:3555`). |
| Lista y ficha: activo | Tiempo activo consolidado entre equipos durante el día consultado; no suma ingenua de sesiones simultáneas. | `GET /reports/people` para lista; `GET /users/{id}/activity?from=2026-10-29&to=2026-10-30` para ficha (`:8023`). |
| Lista y ficha: productividad | `min(100, 100 × P/J)` para esa persona; mostrar P y J en ficha. Igual tratamiento de jornada cero/medición faltante que arriba. | Mismas rutas; `ActivityReport :24692`, fórmula `:24767`, P/J `:24873` y `:24879`. |
| Lista: alerta prioritaria + «y N más» | Orden alta antes que media; a igual severidad, tipo estable. No sumar severidad como puntuación de la persona. | NUEVA composición. Enlace directo a sección de la ficha; todas las alertas allí. |
| Ficha: inactivo y bloqueado | `idle_seconds` y `locked_seconds` separados; no sumar llamadas otra vez al activo ni deducir inactividad de huecos de cronología. | `GET /users/{id}/activity`. |
| Ficha: apps/sitios y tiempo por uso | Lista descendente de `active_seconds`; clasificación efectiva para esa persona. «Más de 1 h no productiva» = suma de uso activo clasificado no productivo > 3.600 s, excluyendo neutro/desconocido. | `GET /users/{id}/windows` (`:530`) contiene proceso/sitio/tiempos, pero NO la categoría efectiva. NUEVA unión con `/app-classification`, `/app-classification/overrides`, `/app-sites` (`:15003`, `:15121`, `:15242`), resolución de reglas y consolidación temporal. `/reports/apps` (`:5230`) mezcla activo e inactivo en su porcentaje: no reutilizarlo como uso activo. |
| Ficha: contexto y evidencia | Cronología del día; ausencias aprobadas; equipos y protección **actuales**, incluso al consultar ayer. | `GET /users/{id}/timeline`, `GET /absences`, `GET /devices/{id}/security` (`:593`, `:14446`, `:7263`). No prometer historial de protección. |

## 2. Correcciones A: alertas y orden

[Seguro] Se reemplazó la etiqueta de conexión por **Sin actividad reciente** en contadores, filtro, insignia y explicación. Una ausencia laboral aprobada sigue siendo un concepto separado. Los nombres mantienen 300 combinaciones únicas con apellidos intercalados, incluido el caso principal Valeria Montes.

| Alerta | Severidad propuesta | Texto y criterio | Destino operativo en maqueta |
|---|---|---|---|
| Llegada tarde | Media | «Llegada fuera del margen». Primera actividad > límite de entrada + 15 min en este ejemplo. | `persona.html?id=…#day-form`: consultar jornada y horario. |
| Protección fallida | Alta | «Protección fallida en un equipo». Un control esperado reportó fallo; no confundir falta de reporte con fallo. | `persona.html?id=…#device-section`: equipo, control y hora del reporte. |
| Equipo sin conexión en horario | Media | «Equipo sin conexión en horario». Un equipo asignado lleva >15 min sin señal en horario efectivo, sin ausencia aprobada. Puede ser secundario aunque la persona siga conectada en otro. | `persona.html?id=…#device-section`. Producción debe limitarlo a equipos que deban estar activos. |
| Posible doble empleo | Media | «Posible doble empleo · señal por revisar». Coincidencia del catálogo, nunca afirmación de otro empleo. | `persona.html?id=…#alert-dual`: evidencia local ficticia, fecha y revisión pendiente. |
| App sospechosa | Alta | «App sospechosa detectada». Coincidencia con el catálogo; prioriza validación por posible riesgo de seguridad, no gravedad de una conducta laboral. | `persona.html?id=…#alert-suspicious`: proceso, fecha y contexto de la señal. |

[Suposición] Estas severidades son una política de presentación propuesta. `DualJobAlert.severity` ya acepta low/medium/high (`:18858`); antes de integrar se debe acordar la correspondencia con el catálogo existente, conservando la severidad original en evidencia. `SuspiciousDetection` (`:18903`) no expone esa severidad. No se cambia backend ni se reinterpretan silenciosamente sus valores.

[Seguro] La maqueta clasifica por atención: personas con alertas altas, con alertas medias, llegadas tarde sin alerta ya representada, sin actividad reciente/sin conexión durante jornada y resto. Actualmente toda llegada fuera del margen genera alerta media, por eso el tercer grupo es residual. Dentro de grupos empata por nombre y luego ID. Alternativas: nombre A–Z, productividad descendente o activo descendente; sin datos/sin jornada van al final de las métricas. La lista usa el mismo orden de severidad que la ficha.

[Suposición] Producción debe identificar alertas por cliente + persona + tipo + equipo/evidencia + día, consolidar duplicados y cerrar por revisión o resolución según fuente. Sin datos o sin equipo siguen siendo estados de cobertura, **no una sexta alerta**. Si falta permiso `cumplimiento.ver`, no deben consultarse ni filtrarse datos de cumplimiento; el resumen debe indicar cobertura incompleta, no «sin alertas» como certeza.

## 3. Asistente: utilidad y límites

[Suposición] Sí es útil para resumir cambios y redactar informes breves a partir de mediciones trazables. No aporta valor calculando porcentajes ni determinando quién entra en una lista. Acepto la restricción de Claude: **cifras, umbrales, cohortes, orden y permisos pertenecen al servidor; la IA solo redacta**.

El bloque de Inicio tiene la etiqueta «Sugerencias generadas por IA, revísalas». En esta entrega son **plantillas de texto fijo**, con cifras deterministas de `data.js`, sin invocación de modelo. No se presenta una generación real. Muestra hasta tres observaciones: cambio semanal, aplicación con más uso activo y personas con más de una hora no productiva. Cada una muestra su numerador/denominador o tiempo y población de origen.

Flujo de producción propuesto:

1. El servidor valida cliente, permisos y filtros; fija corte, zona horaria y versión de reglas. Genera hechos y grupos de personas, con identificadores opacos temporales. No mezcla clientes en una solicitud externa: en vista multicliente se calcula y autoriza cada cliente por separado, y se compone la presentación dentro de Keeper.
2. Serializa un conjunto permitido de agregados y etiquetas de aplicación aprobadas. Excluye nombres, cédulas, hostnames, UUID reales, títulos de ventanas, URLs completas, documentos y evidencia textual de cumplimiento. Los IDs opacos representan grupos; el proveedor no necesita filas individuales. Keeper conserva la relación grupo → personas.
3. Añade reglas de redacción fijas e instrucciones revisadas de plataforma. La IA devuelve texto asociado a IDs de evidencia válidos. Los valores se insertan desde el cálculo servidor o se validan contra él; se rechazan cantidades, causas, IDs o afirmaciones nuevas. Tratar toda salida como texto, sin HTML ejecutable.
4. «Ver personas» consulta la cohorte del servidor para ese corte y vuelve a validar permisos. Nunca acepta IDs inventados por el modelo. El informe incluye tablas y cifras del servidor; nombres solo al renderizar dentro de Keeper para el usuario autorizado. La redacción es borrador revisable.
5. Si la IA falla, conservar indicadores y listas y ofrecer informe determinista. No retrasar la pantalla esperando al proveedor. Descartar una sugerencia no marca las alertas personales como revisadas.

### Riesgos y mitigaciones

| Riesgo | Mitigación propuesta |
|---|---|
| Datos personales a un tercero | Seudonimización no equivale a anonimato. Enviar agregados mínimos, suprimir cohortes de menos de 5 personas y prevenir reconstrucción por diferencias de filtros; bajo ese umbral, usar texto local. Revisión de contexto libre y etiquetas para retirar identificadores antes del envío. Activación por cliente apagada inicialmente; proveedor/retención evaluados antes de habilitar producción. |
| Conclusiones injustas | No inferir esfuerzo, intención, fraude ni doble empleo a partir de actividad o conectividad. Presentar cobertura, horario, clasificación y evidencia; pedir revisión humana. Sin puntuación de personas, sanciones ni acciones automáticas. |
| Cifras alucinadas o causas inventadas | Hechos y grupos deterministas con versión/corte. Texto vinculado a evidencia y validación de salida; si falla, plantilla local. Ninguna instrucción libre puede modificar las reglas fijas ni las cohortes. |
| Instrucciones maliciosas en texto | No enviar títulos de ventanas ni notas libres de casos. Los nombres de apps permitidos son datos, no instrucciones. El asistente no tiene herramientas para consultar BD, ampliar alcance o ejecutar órdenes. |
| Costo | Máximo tres observaciones por alcance; generación bajo demanda, caché por cliente/alcance/corte/contexto/modelo, presupuesto por cliente y límite de tokens. Registrar consumo sin secretos ni payload personal. No se prometen tarifas ni gratuidad. |
| Latencia / caída | Sugerencias asíncronas, timeout acotado, reintento limitado y fecha visible. No recalcular en cada tecla; reutilizar hechos cacheados. El informe determinista sigue disponible. |
| Claves e instrucciones | Clave solo de escritura; almacenar cifrada en servidor, nunca devolverla, ponerla en URL, logs o navegador persistente. Permitir reemplazo; conservar solo estado configurada/no configurada. Contexto versionado con autor y auditoría, sin nombres ni asuntos jurídicos. |

[Seguro, documentación consultada] Groq documenta retención excepcional de entradas/salidas por confiabilidad o abuso y controles de Zero Data Retention. Por tanto, no asumir «no retiene nada» por usar inferencia. Antes de habilitar un cliente se deben verificar sus controles efectivos. Fuente primaria: [Your Data in GroqCloud](https://console.groq.com/docs/your-data). No se invocó su API ni se proporcionaron datos del repositorio.

### Administrar → Asistente de IA

[Seguro] [administrar.html](administrar.html) incorpora proveedor Groq u otro compatible con OpenAI, dirección base HTTPS para el segundo, modelo, campo password solo de escritura, instrucciones/contexto, reglas fijas visibles, interruptor de Grupo AZC inicialmente apagado y vista previa exacta del **cuerpo de la solicitud de ejemplo**. También indica destino/método y que la autenticación se omite por ser secreta.

La vista previa usa exactamente `aiPayload()` con las mismas funciones de agregación de Inicio. Incluye modelo, reglas fijas, contexto y hechos. No incluye personas individuales; usa `grupo_a7f2` y `grupo_c9e4` como IDs opacos ficticios. En producción serán aleatorios por solicitud, sin un mapeo público. El ejemplo es del cliente completo, no de los filtros de Inicio. En cada envío real habría que previsualizar y validar el alcance exacto, el umbral de grupo y el contenido libre.

[Seguro] Guardar es una simulación local: la página conserva los campos y confirma el estado hasta recargar; al guardar borra el valor de la clave y conserva solo un booleano. No hay localStorage, sesión de servidor ni llamadas de red. Cambiar campos deja un borrador; recargar o restablecer vuelve a apagado. El modelo `modelo-de-prueba` es un identificador ilustrativo, no una promesa de disponibilidad. No ingresar credenciales reales.

## 4. Qué requiere API nueva

| Capacidad | Reutilización | NUEVO / por diseñar |
|---|---|---|
| Resumen y lista por atención | Personas, presencia, actividad, dispositivos y reportes existentes. | Agregado con alcance, corte, cobertura, alertas deduplicadas, paginación después del orden y errores parciales multicliente. |
| Tendencia comparable | Dos consultas de `/reports/people`. | Cohorte común con calidad/cobertura por día, reglas históricas y snapshot reproducible. |
| Uso no productivo y aplicación más usada | Ventanas y clasificación existentes. | Clasificación efectiva y unión consolidada por persona; consulta determinista de umbral y grupo con snapshot. |
| Configuración IA | No se encontró contrato específico en OpenAPI. | Proveedor/modelo/destino permitido, escritura de clave cifrada, contexto versionado y activación por cliente apagada inicialmente. |
| Sugerencias | Los hechos proceden de fuentes existentes. | Sanitización, seudónimos, umbral de grupo, trabajo asíncrono, validación de redacción, caché, presupuesto y trazabilidad. |
| Informes y selección «ver personas» | Métricas e identidades autorizadas existentes. | Referencia de cohorte del servidor, revalidación de permisos, borrador/exportación y trazabilidad de evidencia/corte. |

No se inventan nombres de rutas para estas capacidades ni se implementan contratos en esta carpeta. Las sugerencias locales no prueban seguridad, rendimiento ni integración con un proveedor.

## 5. Decisiones pendientes antes de producto

- Correspondencia entre severidad propuesta y catálogo actual; reglas de revisión/cierre y qué equipos deben estar conectados en cada jornada.
- Permisos de un supervisor para seguridad y cumplimiento, y presentación de cobertura cuando no puede ver alguna fuente.
- Vigencia máxima de reportes de protección, controles esperados y tratamiento histórico del margen horario.
- Proveedor/modelo disponibles, retención efectiva, presupuesto, timeout y autorización por cliente; umbral mínimo de cohorte propuesto de 5 y prevención de inferencia por filtros.
- Alcance de instrucciones: esta maqueta propone contexto común de plataforma y activación por cliente. Las excepciones de contexto por cliente requerirían otra decisión.

Estos puntos no bloquean la maqueta solicitada. El nombre Sin actividad reciente y las cinco clases de alertas ya fueron aprobados; no se vuelven a presentar como dudas.
