# Compilador de política v4

`src/PolicyCompiler.php` resuelve una instantánea MySQL, llama al compositor sin reloj ni acceso a BD (`src/PolicyComposer.php`) y publica únicamente en `effective_policies` y `audit_log`. No cambia K3/K4, migraciones, contratos, contadores de tenant/device ni datos de origen.

## Precedencia y conflictos

Orden ascendente: **global → tenant → sede → área → usuario → dispositivo**, conforme a `docs/architecture/v4-api.md`: área es más específica que sede. El scope global solo se lee del tenant plataforma reservado; todos los demás filtros incluyen el tenant del dispositivo. El esquema no ofrece un scope `firm`: empresa se representa por `tenant`; la firma del miembro se usa para los overrides de módulos.

Se usan asignaciones y documentos habilitados, y exactamente la revisión fijada por `policy_assignments.policy_version`, aunque el documento tenga versiones posteriores. Se validan las reglas contra `Rule` de OpenAPI y se exige igualdad entre `policy_versions.document.rules` y las filas `policy_rules` (ignorando orden de filas/targets y targets duplicados). Publicar ambos juntos es responsabilidad del plano administrativo; una discrepancia aborta sin reemplazar la política anterior.

La precedencia es la tupla ascendente `(scope, rule.priority)`; gana el mayor valor. La especificidad domina siempre a las prioridades. La prioridad de asignación y los UUID no deciden qué regla gana. SQL mantiene su restricción de unicidad de prioridad de asignación para un mismo scope/target, independiente de la prioridad explícita de cada regla.

La selección se hace por **(kind, target literal)**: el ganador sustituye efecto y condición de horario para ese target. Los otros targets de una regla heredada permanecen. Targets vacíos no seleccionan nada. No se interpreta shell, ni se expande DNS, glob o identificadores de aplicaciones. La salida ordena primero las reglas más específicas y conserva la prioridad configurada, sin renumerarla. `PolicyComposer::normalizeTargets()` deduplica y ordena con `SORT_STRING` tanto al componer como al comparar publicación y filas SQL. Una excepción más específica sustituye completamente la condición heredada del mismo target, incluso fuera de su horario; no se genera un fallback temporal implícito.

Dos reglas del mismo scope, kind, target y prioridad con efectos opuestos (`deny` frente a `allow` o `require`) provocan `PolicyConflict` con código `policy_conflict` en el mensaje, incluso si una regla de mayor prioridad o scope las ocultaría. El diagnóstico determinista identifica ambas asignaciones/reglas y el SHA-256 del target. Los empates compatibles se conservan y las reglas idénticas se deduplican; el orden canónico solo estabiliza la serialización y los diagnósticos. El compilador confirma `policy.conflict` en auditoría con resultado `denied`, motivo y principal `system`, y después lanza la excepción. No inserta ninguna política ni modifica contadores: GET/sync siguen sirviendo la última versión válida.

`composition` contiene una entrada por nivel que aporta contenido, en orden plataforma → tenant → sede → área → usuario → dispositivo. `global` se representa como `platform`, según OpenAPI. Su `revision` es SHA-256 de la aportación efectiva, no el contador del documento fuente: republicar las mismas reglas o cambiar una regla totalmente sobreescrita no provoca otra versión. La aportación de plataforma incluye la versión del compilador (`azckeeper-policy-2`). `management_hosts` une las listas explícitas de todas las publicaciones aplicables, deduplica, ordena con `SORT_STRING` y conserva los primeros **30** hosts; sin listas entrega `[]`. El criterio de recorte es lexicográfico, independiente del orden SQL. El agente debe respetar esas excepciones de gestión al aplicar controles, según el contrato.

## Tier y miembro aplicable

Se resuelve primero `device_assignments` vigente y después `user_assignments` vigente, con intervalos `[starts_at, ends_at)`. Estas asignaciones prevalecen sobre los campos cacheados de `devices`/`users`. Solo si no hay historial se usan esos campos como fallback. Si hay historial pero ningún intervalo vigente, se rechaza la compilación para no aplicar un miembro u organización vencidos. Las unidades organizativas deben estar activas. Una suscripción debe estar `active`, dentro de su intervalo y apuntar a un tier activo; un usuario inactivo no recibe módulos.

Los únicos módulos del catálogo semilla son `devices`, `activity`, `productivity`, `presence`, `policies`, `integrations`. Ni `Rule` ni `policy_rules` tienen `module_code`, y el contrato efectivo no tiene un campo `modules`. El mapeo explícito `PolicyComposer::RULE_MODULES` vincula los siete controles (`web`, `download`, `installation`, `schedule`, `os`, `usb`, `encryption`) con el módulo real **`policies`**. No se inventan entitlements por nombre de tier ni reglas de módulos inexistentes. Ampliar ese mapeo requiere una decisión de esquema/contrato cuando existan nuevos controles.

Un control entra solo si existe `tier_module` para la suscripción del miembro y el override de su firma no lo deshabilita. Un override positivo **no habilita un módulo ausente del tier**, conforme al requisito de este compilador; esto acota la afirmación histórica de `migrations/README.md` de que el override prevalece sobre tier. Entitlements no conceden RBAC.

## Horarios

`schedules[0]` es el horario laboral de la asignación vigente del miembro, o el de `users` cuando no existe historial. Es contexto laboral básico y se conserva incluso sin el módulo de controles `policies`. Después se incluyen, sin duplicados y ordenados por tenant/UUID, los horarios referenciados por reglas ganadoras. Un horario referenciado por política global se carga del tenant plataforma; nunca se busca un horario local usando un UUID global. Se rechazan UUID de horario ambiguos entre plataforma y tenant porque `Rule.schedule_id` no incluye tenant.

La unión se limita a **100** entradas: se reserva primero el horario laboral y se conservan los primeros 99 horarios restantes por tenant/UUID (`SORT_STRING`); sin horario laboral se conservan 100. El exceso no aborta la compilación y no se truncan reglas. Por ello, si hay más horarios referenciados que cupos, algunas referencias de reglas no tendrán definición en `schedules`; el contrato actual no especifica cómo debe tratarlas el consumidor.

Se copian nombre, tenant, UUID, zona, días ISO 1–7 ordenados, inicio/fin locales y versión. Fin ≤ inicio conserva la semántica de cruce de medianoche. El compilador valida la zona y rechaza segundos distintos de cero: SQL admite segundos, pero OpenAPI exige `HH:mm`; no se truncan silenciosamente. Un cambio en `schedule_days` afecta el hash aunque el contador del horario no cambie.

## Hash, versión y publicación

Cada dispositivo se compila en una transacción propia con `REPEATABLE READ`. Antes de leer la instantánea se bloquea su fila de tenant con `SELECT ... FOR UPDATE`; los compiladores del mismo tenant se serializan. El instante UTC se captura una vez para resolver intervalos y **no se incorpora al JSON**. Para pruebas de límites temporales, `recompile` admite un tercer parámetro `DateTimeImmutable` explícito.

1. Se canonicaliza el documento efectivo ordenando claves de objetos y las colecciones cuya semántica es un conjunto.
2. `composition_hash = SHA-256(documento sin version ni etag)`. Incluye la revisión del compilador a través de `composition`.
3. Si coincide con la última fila del dispositivo, se devuelve su entero y `changed=false`, sin INSERT ni auditoría.
4. Si cambia, se asigna `1 + max(MAX(effective_policies.policy_version del tenant), tenants.policy_version, devices.policy_version reportada)`. Se conserva el historial. El contador de tenant solo se lee como piso, **no se actualiza**; esta implementación sustituye la propuesta histórica de incrementarlo, para cumplir la restricción de efectos colaterales. El primer número normalmente es 2 porque el esquema inicia el piso en 1. Las versiones pueden tener huecos entre dispositivos y nunca se reinician al reasignar un miembro. Se rechaza desbordamiento del entero PHP de 64 bits.
5. Se añaden versión y ETag, se valida `EffectivePolicy`, se comprueba el máximo de 1 MiB y se inserta una fila inmutable. `content_hash` es SHA-256 del JSON canónico completo, incluyendo versión/ETag. SQL también aplica su límite de tamaño JSON.
6. En la misma transacción se registra `policy.compiled` con principal `system` del tenant. Ese principal debe existir (los tenants semilla ya lo tienen); el aprovisionamiento administrativo de nuevos tenants debe crearlo. El compilador no crea identidades como efecto secundario. Un fallo de auditoría también revierte la publicación.

El historial efectivo es el contador persistente: no se debe borrar su última revisión ni resetearla durante mantenimiento. Los escritores administrativos deben confirmar juntos documento/reglas y sus asignaciones antes de recompilar. Si una edición coincide con una compilación en curso, su invocación posterior al commit publica la instantánea más reciente.

## Integración con sync y OpenAPI

No se amplía el wire schema. `SyncRequest/SyncResponse.policy_version` y SQL usan **entero**; el OpenAPI actual todavía exige **string decimal** en `EffectivePolicy.version`. Se conserva ese string solo dentro del documento; ETag es `"<entero>"`, nunca el hash. Esto concilia el contrato vigente con la decisión final de ETag de `v4-api.md`; el párrafo anterior sobre ETag opaco de `v4-client-agent.md` queda superado.

`Resources::policy()` valida que IDs, versión y ETag persistidos correspondan a la fila seleccionada y devuelve el documento materializado; ya no rellena ni reescribe metadatos omitidos. Una fila inconsistente devuelve 503. El compilador produce todos los campos requeridos.

`POST /client/sync` entrega la política si el entero conocido es menor; si coincide, `policy=null`. Una versión conocida superior tampoco provoca downgrade. No existe `GET /client/sync` (router actual: 404). `GET /client/policy` devuelve el documento y admite ETag/304. Se mantiene el límite inline de sync de 256 KiB: anuncia la nueva versión con `policy=null` cuando corresponde y el agente recupera el documento por GET.

## Invocación y afectados

```php
$compiler = new Keeper\PolicyCompiler($db);
$compiler->recompile($deviceUuid);                  // UUID textual; exige identidad inequívoca
$compiler->recompile($deviceUuid, $tenantUuid);     // recomendado al conocer el tenant
$compiler->recompileForTenant($tenantUuid);
$compiler->recompileAll();
```

Invocar **después del commit** de una edición; se rechazan llamadas dentro de otra transacción. Los resultados incluyen `tenant_id`, `device_id`, `policy_version` entero, `changed` y `hash` hexadecimal. Métodos por tenant/todos seleccionan dispositivos activos; una llamada explícita puede materializar un dispositivo inactivo. UUID compartido por varios tenants requiere selector de tenant explícito.

| Cambio confirmado | Invocación |
|---|---|
| Política o asignación global; versión del compilador | `recompileAll()` |
| Política/asignación local, tier, `tier_module`, override de firma, suscripción, horario/días, organización o asignación de usuario | `recompileForTenant(tenantUuid)` |
| Alta o asignación de dispositivo | `recompile(deviceUuid, tenantUuid)` |

Recompilar el tenant completo es la selección conservadora de afectados y cubre también quienes **pierden** una asignación o entitlement. Los hashes evitan publicar cambios en los demás. En cambios entre tenants, invocar ambos tenants. No se instala cron ni triggers SQL ni compilación dentro del polling. Los flujos administrativos que aún no existen deben llamar a estas funciones; para inicios/finales futuros de asignaciones o suscripciones, invocarlas al alcanzar la frontera temporal.

```powershell
php backend-v4/config/compile.php                          # todos
php backend-v4/config/compile.php --all
php backend-v4/config/compile.php --tenant=UUID
php backend-v4/config/compile.php --device=UUID
php backend-v4/config/compile.php --tenant=UUID --device=UUID
```

Usa la configuración DB existente del bootstrap; no necesita clave AEAD, servidor HTTP ni dependencias adicionales. Emite JSON por dispositivo y un conteo final; error devuelve exit 1. Los lotes confirman por dispositivo, se detienen ante un error y pueden reintentarse sin incrementar los resultados ya idénticos.

## Validación

`tests/policy-compiler.php`, integrado en el smoke, cubre los seis niveles (área gana a sede), orden de composición, prioridad explícita conservada, conflictos deterministas con auditoría y política previa intacta, empates compatibles, límites de 30 hosts/100 horarios, conservación de targets, tier ausente/inactivo, overrides positivos/negativos, suscripción cancelada/vencida, horario laboral/nocturno y condiciones, igualdad de hashes, republicación idéntica, rechazo de publicación inconsistente y aislamiento por tenant. También ejercita compilación → POST sync inicial → POST sin cambios → GET condicional, reasignación temporal de dispositivo, compositor con entradas reordenadas, dos procesos CLI concurrentes y CLI `--all`.

Ejecutar el lint y `tests/mysql-smoke.ps1 -DebugResponses`; el harness usa MySQL real aislado y elimina procesos, archivos temporales y datadir en `finally`.
