# Módulo de Seguridad — Diseño

**Fecha:** 2026-07-29
**Estado:** diseño aprobado. Marco legal revisado por la gerencia jurídica (§11.1). Trabajo en rama `feature/modulo-seguridad`; NO va a producción hasta tener todas las correcciones. Producción vigente: 3.0.3.2
**Rama:** `feature/modulo-seguridad`

---

## 1. Contexto y problema

El área de agentes de servicio maneja información sensible de las firmas clientes. Gerencia solicitó un
endurecimiento de puestos "como en un BPO": bloquear el menú contextual, impedir copiar y pegar,
impedir descargas, subir el UAC y evitar que se almacenen datos localmente.

Población actual: **60 agentes**. Proyección: 100 y luego 200.
Equipos: **Windows Pro**, sin dominio, usuarios estándar sin privilegios de administrador.
Herramientas del turno: navegador (CRM y portales) más telefonía y software específico de línea de negocio.

### Por qué el pedido literal no se puede implementar tal cual

Media lista de controles no existe como política de grupo, o existe pero no hace lo que se espera:

| Pedido | Realidad técnica |
|---|---|
| Bloquear clic derecho | `NoViewContextMenu` afecta únicamente al Explorador de Windows y al escritorio. No toca navegador, Office ni visores de PDF. Es cosmético |
| Quitar copiar y pegar | **No existe** clave de registro ni política de grupo que controle el portapapeles. Solo se corta en la capa de sesión (RDP/AVD/Citrix vía `fDisableClip`) o con DLP sobre contenido etiquetado |
| Impedir descargas | Sí existe y es efectivo: `DownloadRestrictions=3` en política de navegador |
| Subir el UAC | Útil, pero no es el control relevante: los usuarios ya son estándar. Lo que aporta valor es `ConsentPromptBehaviorUser=0` (auto-denegar elevación) |
| Que no se almacenen datos | Es el único de la lista que ataca el riesgo real, y **no se resuelve con políticas de grupo**. Requiere VDI, o bien cierre de destinos de salida |

### Reencuadre

El control efectivo no consiste en deshabilitar gestos visibles, sino en **cerrar los destinos de salida
de la información**. Un agente que copia datos al portapapeles no ha exfiltrado nada si no tiene USB,
ni nube personal, ni correo personal, ni extensiones, ni descargas, ni DevTools.

Se organiza en tres anillos:

```
Anillo 1 — NAVEGADOR   políticas Chrome/Edge/Brave en HKLM     → ~90% del riesgo real
Anillo 2 — SISTEMA     USB, SRP, UAC, nube personal            → cierra los destinos
Anillo 3 — FÍSICO      celulares, marca de agua, turnos        → lo que ningún software cubre
```

El anillo 3 queda fuera del alcance de este módulo pero se documenta porque sin él los otros dos
no cierran: ninguna política de Windows detiene la cámara de un teléfono.

---

## 2. Alcance

**Dentro:**
- Servicio elevado que aplica políticas de seguridad en los equipos del área
- Motor de asignación por persona, con plantilla
- Panel de cobertura y de excepciones
- Auditoría de estado aplicado y bitácora de cambios
- Correcciones al cliente y backend que son prerequisito

**Fuera:**
- VDI (descartado: a 200 puestos son USD 6.000–8.000 mensuales)
- Scope de firma o sede en el motor de políticas (descartado, ver §6)
- Controles de ámbito de usuario en fase inicial (clic derecho, CMD, regedit — ver §7.4)
- Marco legal y de monitoreo de personal (§11, delegado a gerencia jurídica)

---

## 3. Ground truth: cómo funciona hoy el motor de políticas

Verificado leyendo el código en `fa88823` (base de la rama), no asumido.

### 3.1 Resolución

`PolicyRepo::getAllPolicies()` y `ClientHandshake::doHandle()` (líneas 87–115):

```
global  →  user  →  device
```

Tres scopes. `device` gana sobre `user`, que gana sobre `global`. Cada nivel se aplica con
`PolicyService::deepMerge()` sucesivo. **No existen los scopes `sede` ni `firm`.**

La dimensión de firma y sede sí existe en la base — `keeper_user_assignments(keeper_user_id, sede_id, firm_id)` —
pero se usa para asignaciones y reportes, no está cableada al motor de políticas.

### 3.2 Semántica del merge

`PolicyService.php:5-14` es override: el scope más específico pisa al anterior. Es el comportamiento
correcto y se conserva.

### 3.3 Otros comportamientos verificados

- `ClientHandshake.php:90` — sin política global activa, el handshake devuelve HTTP 500. El equipo deja
  de reportar por completo, no solo de recibir políticas.
- `ClientHandshake.php:120` — `syncIntervalSeconds` tiene piso de 300 segundos.
- `policyApplied.scope` reporta únicamente el scope más alto que aplicó, no la composición.

---

## 4. Hallazgos que bloquean la implementación

### 4.1 `deepMerge` corrompe las listas — BLOQUEANTE

`PolicyService.php:5-14` hace merge recursivo cuando ambos lados son arrays. En PHP una lista también
es un array, de modo que las listas se combinan **por índice numérico**:

```php
global:  domains = ["facebook.com", "instagram.com", "x.com"]   // keys 0,1,2
usuario: domains = ["solo-este.com"]                            // key 0
─────────────────────────────────────────────────────────────
EFECTIVO: ["solo-este.com", "instagram.com", "x.com"]           // hereda 1 y 2
```

Consecuencias:

- El administrador cree que reemplazó la lista; en realidad pisó el primer elemento y heredó el resto.
- **No se puede acortar una lista** desde un scope inferior.
- **No se puede vaciar:** `domains: []` no itera, así que la lista base queda intacta.

Hoy el efecto es casi nulo porque la política es prácticamente solo global. Se vuelve crítico en el
momento en que se creen políticas por persona, que es exactamente el modelo de asignación elegido (§6).

**Corrección:** detectar lista con `array_is_list()` y asignar directo en lugar de recursión.

### 4.2 Ningún mecanismo sin privilegio ha funcionado nunca

Historial de intentos de web-blocking:

| Versión | Mecanismo | Resultado |
|---|---|---|
| ≤ 3.0.2.4 | hosts + proxy de sistema global | Sobre-bloqueaba: rompía Teams, Office y páginas gubernamentales |
| 3.0.2.5 / 3.0.2.6 | `URLBlocklist` en **HKCU** | Nunca escribió. `HKCU\SOFTWARE\Policies` es solo-lectura para el usuario estándar |
| 3.0.2.7 / 3.0.2.8 | PAC blackhole en **HKCU** | No funciona (reportado 2026-07-28) |

La causa es común y estructural: **sin privilegio elevado no hay enforcement posible**. El acceso
elevado no es una facilidad, es la condición que faltaba.

Agravante del PAC: **falla abierto por diseño**. Si el servidor PAC local no responde, WinInet ignora
la configuración y navega directo (verificado en vivo el 2026-07-08). Un control que falla en silencio
es peor que no tener control, porque produce confianza falsa ante gerencia y ante un auditor.

`URLBlocklist` en HKLM invierte esa propiedad: el bloqueo ocurre dentro del navegador, después de
resolver DNS, por lo que sobrevive a VPN y a DoH; y el reporte de estado aplicado (§5.2) hace visible
cualquier fallo.

### 4.3 No existe confirmación de aplicación

El cliente no reporta si la política se aplicó. Es lo que permitió que el fracaso de 3.0.2.5 pasara
semanas inadvertido. Sin este dato no hay control, hay expectativa.

---

## 5. Arquitectura

### 5.1 Componentes

| Capa | Pieza | Estado |
|---|---|---|
| Cliente | `AZCKeeper_Client.exe` — sesión de usuario, tracking, UI, handshake | Existe, cambios menores |
| Cliente | `AZCKeeperAgent` — servicio Windows como LocalSystem | **Nuevo** |
| Backend | `SecurityPolicyRepo` + `POST /client/security/report` | **Nuevo** |
| Datos | `keeper_security_state`, `keeper_security_exceptions` | **Nuevo** |
| Datos | `keeper_policy_assignments`, `keeper_audit_log` | Reuso |
| Panel | `security.php` — plantilla y asignación | **Nuevo** |
| Panel | `security-coverage.php` — cobertura | Clon de `install-coverage.php` |

**Por qué un servicio separado y no ampliar el cliente:** separación de privilegios. El cliente corre en
sesión de usuario y ya tiene superficie de ataque (red, interfaz, actualizador). Otorgarle SYSTEM
convertiría cualquier defecto suyo en escalada local. El servicio es código mínimo: lee política de
disco, escribe registro, reporta resultado.

### 5.2 Flujo

```
1. Cliente hace handshake              → recibe effectiveConfig
2. Cliente escribe policy.json          → C:\ProgramData\AZCKeeper\
                                           ACL: escritura solo SYSTEM + Administradores
3. Servicio lee y compara versión
4. Servicio respalda estado previo      → rollback.json
5. Servicio aplica a HKLM\SOFTWARE\Policies
6. Servicio escribe applied.json        → qué se aplicó, qué falló, timestamp
7. Cliente lo envía en el siguiente handshake
```

El paso 6 es el que hoy no existe y es el centro de la capacidad de auditoría (§9).

### 5.3 Anti-manipulación

- El servicio re-aplica en cada ciclo aunque la política no haya cambiado. Mismo patrón que
  `WebBlockingManager.Reassert()`, ya probado en producción.
- Registro en el SCM con recuperación automática ante terminación.
- Las claves quedan bajo ACL que el usuario estándar no puede modificar.

Un agente sin privilegios de administrador no tiene vector para revertirlo.

### 5.4 Regla de despliegue

> **Una sola visita elevada por equipo. Jamás dos.**

A 60 puestos el despliegue manual es incómodo pero viable; a 200 es el mismo muro de 16 horas que hizo
abortar el intento de junio de 2026. Una vez instalado, el servicio se convierte en el canal de
despliegue de todo lo demás, incluidas sus propias actualizaciones. La visita de instalación debe
resolver todo lo que requiere privilegio de por vida.

---

## 6. Modelo de asignación

### 6.1 La unidad es la persona

**Las firmas mezclan agentes y abogados.** El candado aplica a agentes, no a abogados. Por lo tanto la
firma no es una unidad válida de agrupación: heredar por firma pondría candado a abogados.

Esto descarta definitivamente agregar los scopes `firm` y `sede` al motor. La decisión de no tocar el
motor de scopes es correcta, y lo es por esta razón — no por costo.

### 6.2 Piezas

| Pieza | Función |
|---|---|
| **Plantilla de endurecimiento** | Define el conjunto de bloqueos una vez. IT la aplica a una persona con un clic, sin editar JSON |
| **Asignación** | Por persona, scope `user`. Sin herencia |
| **Panel de cobertura** | Cruza `employee` por cargo y área contra las políticas asignadas |
| **Excepción** | Nota obligatoria más `is_exempt` y `expires_at`. Visible, no perdida |

### 6.3 Por qué esto no se degrada

La aplicación es manual. Lo que deja de ser manual es **darse cuenta**. El panel responde dos preguntas:

- *¿Quién debería tener candado y no lo tiene?* → cargo de agente, sin política asignada. Accionable.
- *¿Quién tiene candado y ya no debería?* → cambió de cargo, o `employee.role='retirado'`, y conserva
  política. Limpieza.

**La fuente de verdad ya existe.** `install-coverage.php:152-157` y `241-242` confirman que la base mapea
área, cargo, sede y firma por empleado (`employee.{area_id, position_id, sede_id, company}` →
`keeper_areas` / `keeper_cargos`). Y `keeper_install_coverage_notes` ya implementa el patrón de
excepción con nota y `is_exempt`.

Precedente que justifica este diseño: de 251 dispositivos activos, 51 llevaban más de 30 días sin
reportar y nadie los revocó. La gestión manual de altas y bajas no ocurre por sí sola; la
reconciliación automática es lo que la hace ocurrir.

Lecciones heredadas de `install-coverage.php` que se conservan:
- Clave única por `legacy_employee_id`, no por `keeper_user_id`, para que el registro sobreviva a
  personal aún no enrolado.
- El chip de excepción arranca apagado, para que IT solo vea lo accionable.

---

## 7. Catálogo de controles

Columna clave: **ámbito**. `HKLM` lo escribe el servicio directamente. `HKCU` es política de usuario y
exige que el servicio cargue el hive del perfil activo.

### 7.1 Navegador — Chrome, Edge y Brave

Ruta: `HKLM\SOFTWARE\Policies\<vendor>\<browser>`. Aquí está el ~90% del control real.

| Control | Clave | Valor |
|---|---|---|
| Bloquear descargas | `DownloadRestrictions` | `3` |
| Bloquear dominios | `URLBlocklist\1..n` | dominios |
| Permitir el CRM pese al bloqueo | `URLAllowlist\1..n` | dominios |
| Bloquear todas las extensiones | `ExtensionInstallBlocklist\1` | `*` |
| Permitir solo lo aprobado | `ExtensionInstallAllowlist\1..n` | IDs |
| Bloquear DevTools | `DeveloperToolsAvailability` | `2` |
| Bloquear cuenta personal | `BrowserSignin` | `0` |
| Bloquear sincronización | `SyncDisabled` | `1` |
| Bloquear incógnito | `IncognitoModeAvailability` | `1` |
| Bloquear impresión | `PrintingEnabled` | `0` |
| No guardar contraseñas | `PasswordManagerEnabled` | `0` |
| Bloquear diálogos de archivo | `AllowFileSelectionDialogs` | `0` — **no entra en la plantilla base** |

Notas:

- `ExtensionInstallBlocklist=*` cierra el pendiente histórico de VPN por extensión (Urban VPN), abierto
  desde 2026-06-18.
- `DeveloperToolsAvailability` no figuraba en el pedido original y es de los más importantes: sin él un
  agente abre F12 y extrae de la interfaz del CRM todo lo que esta le muestra, sin descargar nada.
- `AllowFileSelectionDialogs=0` es el más potente y el más riesgoso: impide subir *y* guardar archivos.
  **Queda fuera de la plantilla base.** Solo se evalúa como activación opcional después de la Fase 2,
  y únicamente si el inventario confirma que ni el softphone ni el CRM requieren diálogos de archivo.

### 7.2 Sistema

Todo `HKLM`. Lo aplica el servicio sin fricción.

| Control | Clave | Valor |
|---|---|---|
| Bloquear USB | `SYSTEM\CurrentControlSet\Services\USBSTOR\Start` | `4` |
| Denegar todo almacenamiento extraíble | `...\Policies\Microsoft\Windows\RemovableStorageDevices\Deny_All` | `1` |
| Bloquear OneDrive personal | `...\Policies\Microsoft\OneDrive\DisablePersonalSync` | `1` |
| Auto-denegar elevación al usuario estándar | `...\Policies\System\ConsentPromptBehaviorUser` | `0` |
| UAC en escritorio seguro | `...\Policies\System\PromptOnSecureDesktop` | `1` |
| UAC activo | `...\Policies\System\EnableLUA` | `1` |

Nube personal de terceros (Drive, Dropbox, WeTransfer) se cubre por `URLBlocklist`, no por registro.

### 7.3 SRP — control de ejecución

Ruta: `HKLM\SOFTWARE\Policies\Microsoft\Windows\Safer\CodeIdentifiers`.

Modo lista negra (`DefaultLevel=262144`), bloqueando ejecución desde `%TEMP%`, `%LOCALAPPDATA%` y
`Downloads`. Es lo que impide navegadores y clientes VPN portables.

> **Excepción obligatoria:** `%LOCALAPPDATA%\AZCKeeper\app`. Sin esa regla, SRP mata al propio cliente y
> se pierde el canal de gestión.
>
> **Alternativa preferida:** mover la instalación a `%ProgramFiles%`, que además la protege del usuario.
> El servicio elevado hace viable esa migración por primera vez.

Es el módulo con mayor riesgo operativo del catálogo. Va último y solo contra el inventario de la Fase 2.

### 7.4 Ámbito de usuario

| Control | Clave | Efectividad |
|---|---|---|
| Clic derecho | `...\Policies\Explorer\NoViewContextMenu` | **Cosmético.** Solo Explorador |
| CMD | `...\Policies\Microsoft\Windows\System\DisableCMD=2` | Media |
| Regedit | `...\Policies\System\DisableRegistryTools=1` | Media |
| Administrador de tareas | `...\Policies\System\DisableTaskMgr=1` | Media-baja |

**Quedan fuera de la implementación inicial.** Exigen que el servicio cargue el hive del usuario activo,
y con navegador cerrado, USB bloqueado y SRP activo, un CMD abierto ya no tiene por dónde sacar datos.
Priorizarlos sería gastar esfuerzo en lo visible en vez de en lo efectivo. El módulo los soportará;
la decisión de activarlos se pospone.

---

## 8. Comportamiento ante fallos

### 8.1 Pérdida de contacto con el backend: fail-closed

El servicio conserva la última política conocida y la sigue aplicando indefinidamente.

Esto no es teórico: el 2026-07-08 el firewall CSF del hosting compartido baneó la IP de salida de la
oficina y `keep.azclegal.com` quedó inalcanzable para toda la sede, con 110 dispositivos afectados. Es
reincidente — más de 100 clientes tras un NAT único disparan el anti-DDoS. **El backend actual no es
confiable**, de modo que el servicio debe operar de forma autónoma.

Mantener el candado cuando cae la red no impide trabajar; solo conserva las restricciones vigentes. El
comportamiento inverso sería un agujero que cualquiera puede provocar tirando la red.

### 8.2 Kill-switch

Una clave en la política global (`hardening.enabled=false`) hace que el servicio revierta todo desde
`rollback.json`.

**Debe existir y estar probado antes del primer despliegue**, no después. Es la salida de emergencia si
el candado tumba la operación un lunes a las 7 de la mañana.

### 8.3 Modo auditoría

El servicio soporta un modo en que reporta qué bloquearía sin bloquear. Es el estado de la Fase 1 y el
mecanismo para validar cualquier módulo nuevo antes de activarlo.

---

## 9. Auditoría

Son dos capacidades distintas y el módulo las provee por separado.

**Auditoría de estado.** Qué está aplicado realmente en cada equipo, no qué se ordenó. Es `applied.json`
reconciliado contra la política asignada, persistido en `keeper_security_state`.
Responde: *¿los 60 agentes están efectivamente protegidos?*

**Bitácora.** Quién cambió qué política, cuándo y con qué justificación; y qué excepciones se otorgaron.
Va sobre `keeper_audit_log`, que ya existe.
Responde: *¿quién le quitó el candado a esta persona y por qué?*

La primera es la que hoy no existe y la que convirtió el fracaso de 3.0.2.5 en semanas de falsa
confianza.

**Beneficio adicional:** si cada firma es un cliente con manejo propio, la auditoría de estado permite
demostrar, ante un requerimiento contractual, exactamente qué controles protegen la información de esa
firma y sobre qué personas están vigentes.

---

## 10. Fases de implementación

Aplica la regla vigente desde 2026-05-08: *cada parche a AZCKeeper debe tener prueba de escritorio que
confirme flujo, datos enviados y recibidos; ningún hallazgo se cierra sin esa verificación.*

### Fase 0 — Correcciones de backend y cliente (sin tocar equipos)

| # | Corrección | Dónde | Por qué |
|---|---|---|---|
| 1 | Listas se reemplazan, no se mezclan por índice | `PolicyService.php` | **Bloqueante** (§4.1) |
| 2 | Retirar el stack PAC | `WebBlockingManager`, `SystemProxyManager`, `LocalPacServer` | No funciona y falla abierto (§4.2) |
| 3 | Reportar estado aplicado | Cliente + endpoint nuevo | Elimina la falla silenciosa (§4.3) |
| 4 | Composición completa en `policyApplied` | `ClientHandshake.php` | Auditoría por persona |
| 5 | Minors ya documentados en `progress.md` | varios | Los de `LocalPacServer` y `SaveCacheToDisk` se resuelven por eliminación al retirar el PAC |

**Entregable propio de la fase:** auditoría de **solo lectura**. El cliente lee
`HKLM\SOFTWARE\Policies` —leer no requiere privilegio— y reporta qué controles existen hoy en cada
equipo. Da inventario real de la flota antes de aplicar nada.

**Criterio de salida:** tests xUnit en verde en `AZCKeeper.Tests` cubriendo el merge de listas, política
de usuario probada contra DEV sin heredar restos de la global, y al menos un equipo reportando estado en
`keeper_security_state`. Cero equipos con política aplicada.

> **Corrección al alcance (2026-07-29, al escribir el plan):** mover la instalación a `%ProgramFiles%`
> figuraba en esta fase y es incorrecto. `install.bat:22-25` instala en `%LOCALAPPDATA%\AZCKeeper\app` y
> `AZCKeeperUpdater` copia ahí sin privilegio; mover la ruta rompe el auto-update de los 251 equipos hasta
> que exista el servicio elevado que pueda escribir en `%ProgramFiles%`. **Se traslada a Fase 1**, donde
> el servicio la habilita.

### Fase 1 — Piloto técnico (2–3 equipos, modo auditoría)

Servicio instalado sin bloquear nada: solo reporta qué bloquearía. Se usa el patrón conocido de
auto-update selectivo (activo solo para los equipos del piloto).

**Criterio de salida:** los equipos reportan `applied.json` completo y el panel muestra su estado real.
Se valida que el servicio sobrevive a reinicio, cierre de sesión y actualización del cliente.

### Fase 2 — Inventario de ejecutables

Consulta a `keeper_window_episode.process_name` filtrada por los agentes del área, últimos 90 días. Sale
el catálogo real de software en uso. **No se recorre ningún puesto.**

**Criterio de salida:** lista blanca de ejecutables aprobada por el supervisor del área. Es el insumo de
SRP; sin ella SRP no se diseña.

### Fase 3 — Enforce del navegador en el piloto

Solo el anillo 1. Es donde está el 90% del riesgo y el que menos rompe.

**Criterio de salida:** un agente del piloto trabaja un turno completo sin incidente, y se verifica en
`chrome://policy` que las claves están activas — la verificación que faltó en 3.0.2.5.

### Fase 4 — Ola por grupos hasta los 60

Grupos de 10–15, con 48 horas de observación entre olas. El kill-switch probado antes de la primera ola.

**Criterio de salida por ola:** cero tickets de bloqueo indebido durante 48 horas antes de habilitar la
siguiente.

### Fase 5 — Sistema y SRP

USB, nube personal, UAC y finalmente SRP contra la lista blanca de la Fase 2. La migración de la
instalación a `%ProgramFiles%` debe estar hecha en Fase 1 antes de llegar aquí; de lo contrario SRP
mata al propio cliente.

**Criterio de salida:** cobertura al 100% en `security-coverage.php`, con las excepciones justificadas y
con vencimiento.

---

## 11. Fuera de alcance de este spec

### 11.1 Marco legal — REVISADO Y CONSIDERADO POR LA GERENCIA JURÍDICA (2026-07-29)

El marco legal fue revisado y considerado por la gerencia jurídica. **No es un bloqueante para la
implementación.**

Lo que sigue es un recordatorio de verificación, no un dictamen: quien redacta este spec es el área de
TI, no la jurídica. Sirve para contrastar que ningún frente quedó por fuera y para que el módulo se
construya alineado a lo ya definido.

#### Protección de datos personales — Ley 1581 de 2012 y Decreto 1074 de 2015

| Asunto | Qué verificar |
|---|---|
| Autorización del titular | Previa, expresa e informada (art. 9). Cubre tanto al empleado monitoreado como a los titulares cuyos datos tratan los agentes |
| Principio de finalidad | Los datos solo para lo informado (art. 4 lit. b y c). El módulo no debe habilitar usos nuevos no declarados |
| Proporcionalidad y necesidad | El control debe ser el mínimo idóneo para el fin. Es el criterio que sostiene el endurecimiento ante una eventual queja |
| Aviso de privacidad y política de tratamiento | Vigentes y publicados |
| Derechos del titular | Conocer, actualizar, rectificar y revocar (art. 8): canal operativo disponible |
| Registro Nacional de Bases de Datos | Verificar si AZC supera el umbral que obliga al registro ante la SIC |
| Temporalidad y supresión | No conservar más allá de la finalidad. Aplica a `keeper_security_state` y a `keeper_window_episode`, que hoy acumula ~6,9 millones de filas **sin política de retención definida** |
| Principio de seguridad | Art. 4 lit. g y arts. 17–18. Este módulo **apoya** el cumplimiento: es argumento a favor, no en contra |
| Reporte de incidentes | Deber de informar a la SIC violaciones a los códigos de seguridad (art. 17 lit. n, art. 18 lit. k) |

#### Rol frente a las firmas clientes

AZC probablemente actúa como **Encargado del tratamiento** y cada firma como **Responsable**. Eso exige
contrato de transmisión de datos con las cláusulas del art. 25 del Decreto 1377 de 2013 (hoy compilado
en el Decreto 1074 de 2015). La auditoría de estado del módulo (§9) es evidencia útil para sustentar
ante cada firma qué controles protegen su información.

#### Monitoreo laboral

| Asunto | Referencia |
|---|---|
| Poder subordinante del empleador | Art. 23 CST — habilita el control, no lo vuelve ilimitado |
| Reglamento Interno de Trabajo | Arts. 104–125 CST: el monitoreo debe estar contemplado |
| Información previa al trabajador | Debe saber qué se monitorea, cómo y para qué, antes de que ocurra |
| Intimidad y correspondencia | Art. 15 C.P. Comunicaciones privadas y correo personal tienen protección reforzada; la línea jurisprudencial constitucional exige que el control sea previamente informado y proporcional |

#### Los tres que suelen pasarse por alto

**1. Secreto profesional del abogado.** Art. 74 C.P. y Ley 1123 de 2007. Los agentes manejan información
de clientes de firmas de abogados, y `keeper_window_episode.window_title` captura títulos de ventana que
pueden contener nombres de casos, contrapartes o clientes. Es información potencialmente amparada por
secreto profesional almacenada en una base de datos operativa. Conviene confirmar el tratamiento
específico de ese campo.

**2. Transferencia internacional de datos.** Art. 26 Ley 1581. El backend de producción corre hoy en
hosting compartido de un tercero (`server1872.mylogin.co`); si la infraestructura está fuera de Colombia,
aplica el régimen de transferencia internacional. Verificar la ubicación real y si el país cuenta con
nivel adecuado según la SIC, o si se requiere autorización o cláusulas contractuales.

**3. Datos que el módulo crea y hoy no existen.** `keeper_security_state` registrará, por persona y por
equipo, qué controles están aplicados y desde cuándo. Es información nueva sobre el empleado.
Confirmado como considerado por la gerencia jurídica el 2026-07-29.

### 11.2 Controles físicos

Ningún control de software cubre la cámara de un teléfono. El anillo 3 —política de dispositivos
personales en el piso, marca de agua con identificación del agente en la aplicación de trabajo, y
supervisión presencial— es responsabilidad del área, no del módulo. Se documenta aquí porque sin él
los anillos 1 y 2 no cierran el riesgo de exfiltración visual.

---

## 12. Decisiones tomadas

| Decisión | Motivo |
|---|---|
| Servicio elevado separado del cliente | Separación de privilegios; el cliente ya tiene superficie de ataque |
| Asignación por persona, sin scopes nuevos | Las firmas mezclan agentes y abogados (§6.1) |
| Se conserva la semántica override del merge | Es la que ya existe y es la esperada por el equipo |
| Fail-closed ante pérdida de backend | El backend actual no es confiable (§8.1) |
| VDI descartado | USD 6.000–8.000 mensuales a 200 puestos |
| PAC retirado | No funciona y falla abierto (§4.2) |
| Controles de ámbito de usuario pospuestos | Cosméticos o de baja efectividad frente a su costo (§7.4) |
| SRP al final de todas las fases | Único módulo capaz de tumbar la operación |

---

## 13. Referencias de código

| Qué | Dónde |
|---|---|
| Resolución de políticas | `AZCKeeper_Client/Web/src/Repos/PolicyRepo.php:30-79` |
| Merge (bug §4.1) | `AZCKeeper_Client/Web/src/PolicyService.php:5-14` |
| Composición de política efectiva | `AZCKeeper_Client/Web/src/Endpoints/ClientHandshake.php:87-123` |
| Patrón de cobertura a clonar | `AZCKeeper_Client/Web/public/admin/install-coverage.php:152-157, 241-242` |
| Patrón de re-aserción | `AZCKeeper_Client/Blocking/WebBlockingManager.cs` |
| Diseño previo de web-blocking | `docs/superpowers/specs/2026-07-10-webblock-pac-y-debug-window-design.md` |
