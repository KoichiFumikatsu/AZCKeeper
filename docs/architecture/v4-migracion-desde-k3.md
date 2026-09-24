# Migración de la flota K3 → v4 desde el propio K3

> Especificación operativa: cómo llevar ~1.000–2.000 equipos ya instalados con K3 a v4,
> creando la cuenta de recuperación `azcadmin` y dejando al empleado como usuario estándar,
> **sin visitar ningún equipo**.
> Fecha: 2026-09-17.

---

## 1. El problema en una frase

Todos los empleados son **administradores locales**, así que cualquier control es reversible por ellos.
Para tener control real hay que dejarlos como **usuarios estándar** — pero antes hay que garantizar que
IT conserve una forma de elevar ese equipo, o quedas fuera de tu propia máquina.

`azcadmin` = cuenta de administrador local, **una por equipo, con contraseña única aleatoria**, creada
ANTES de degradar al empleado, y cuya contraseña se guarda cifrada en Keeper (*escrow*).

---

## 2. Lo que no se puede evitar: una elevación

- K3 corre en **integridad media** (`asInvoker`, sin manifest). Aunque el usuario sea admin, el proceso
  **no está elevado**.
- Crear una cuenta local, añadirla a Administradores e instalar un servicio **requieren elevación**.
- Por tanto: **K3 no puede hacerlo silenciosamente**. Requiere **UNA elevación por equipo, una sola vez**.
- Como el usuario ya es admin y UAC está en modo consentimiento (`ConsentPromptBehaviorAdmin=2`,
  verificado), es **un clic en «¿Permitir?»**, no una contraseña.

> Solo se evitaría el clic si ya existiera un agente corriendo como SYSTEM (un RMM). No lo hay
> (no hay dominio, es WORKGROUP), así que **un clic por equipo es el piso realista**.

---

## 3. Límite de confianza (leer antes de ejecutar)

El updater de K3 **ejecuta el contenido del paquete antes de verificarlo** (hallazgo crítico de la
auditoría). Usar el canal de K3 como transporte **no arregla retroactivamente** esa debilidad.

Por eso la **primera release puente** debe validarse por un medio ya confiable:
- Artefactos firmados con **Authenticode** y sellado de tiempo.
- El usuario ve el **editor esperado** en el diálogo de UAC (validación humana del origen).
- Anclaje del editor y antirrollback dentro del bootstrap.

Si esa confianza no puede establecerse en un equipo, **ese equipo no se degrada**: queda marcado para
recuperación asistida.

---

## 4. Secuencia exacta (el orden es la seguridad)

Ejecutada por el bootstrap **elevado**, con journal reanudable. Si un paso falla → **detenerse, NO degradar**.

| # | Paso | Verificación obligatoria antes de seguir |
|---|---|---|
| 1 | K3 descarga la **release puente firmada** | firma + editor esperado + antirrollback |
| 2 | **Una elevación UAC** (`ShellExecute verb=runas`) | si el usuario cancela: conservar K3, marcar pendiente, **no insistir con prompts** |
| 3 | Instalar **MSI** → servicio `Keeper.Agent` como SYSTEM | servicio activo + ACL endurecida (`sc sdset`: solo SYSTEM/Admins control total) |
| 4 | **Enrolar** el equipo en v4 (identidad propia con prueba de posesión) | `device_token` propio emitido; jamás reutilizar el bearer del usuario |
| 5 | **Crear `azcadmin`** con contraseña aleatoria de 32 caracteres | si el nombre ya existe y no consta que lo creó Keeper: **detener**, no sobrescribir |
| 6 | Añadirla a Administradores **por SID** `S-1-5-32-544` | comprobar pertenencia real y que la cuenta está habilitada |
| 7 | **Probar** que la credencial sirve (logon controlado) | si no se puede probar: **no degradar** |
| 8 | **Escrow**: enviar el sobre cifrado a Keeper (tenant/device/SID) | exigir **ACK de persistencia** y que la bóveda pueda **recuperar** el secreto. Un 200 de «encolado» NO basta |
| 9 | Aplicar política baseline + lanzar `Keeper.Session` | AppLocker/WDAC en **modo auditoría** (no romper apps el día 1) |
| 10 | Revisar **todas** las cuentas administrativas del equipo | si el `Administrator` integrado está habilitado con contraseña conocida/en blanco, degradar no sirve de nada |
| 11 | **Degradar** al empleado: quitarlo de Administradores **por SID**, asegurar `Users` | guardar estado previo para poder revertir |
| 12 | **Cerrar sesión / reiniciar** de forma controlada | el token admin viejo **sobrevive hasta el logoff**: sin esto sigue siendo admin en la sesión actual |
| 13 | Verificar que el usuario ya es **estándar** | comprobar el token, no solo la membresía |
| 14 | **Ocultar `azcadmin`** de la pantalla de inicio | `HKLM\...\Winlogon\SpecialAccounts\UserList` → DWORD `azcadmin = 0` |
| 15 | Reportar **migración completa** a Keeper | el panel muestra el estado por equipo |

**Regla de oro:** los pasos 5→8 ocurren **antes** del 11. Nunca degradar sin escrow confirmado y
recuperación probada.

---

## 5. Detalles de implementación

**Contraseña de `azcadmin`:**
- 32 caracteres, aleatoriedad criptográfica. **Nunca** derivada del GUID, serial, empresa ni patrón común.
- **Nunca** en línea de comandos, logs, ni propiedades del MSI (se leen en texto plano).
- Solo en memoria protegida y en el sobre cifrado del escrow.

**Grupos siempre por SID, no por nombre** (el Windows en español usa «Administradores»/«Usuarios»):
- Administradores = `S-1-5-32-544` · Usuarios = `S-1-5-32-545`

**Ocultar la cuenta es cosmético**, no seguridad: sigue usable por `runas`/«otro usuario». La protección
real la da que el empleado sea estándar.

---

## 6. Lo que falta construir para que esto sea automático

| Pieza | Dónde | Estado |
|---|---|---|
| `Bootstrap.ps1` fases Plan/Install/ProvisionRecovery/Demote/Logoff/Verify/Report/All + journal | cliente | Implementado schema 2, lease, reanudación y cola CAS; pruebas sin privilegios. Journals antiguos/corruptos requieren asistencia. |
| ACL endurecida del servicio (`sc sdset`) | cliente | Conservada; propietario y DACL protegidos también en staging/tarea/estado. Falta ensayo en VM. |
| Crear `azcadmin` sin sobrescribir + degradar por SID | cliente | Implementado; 32 caracteres criptográficos, prueba NETWORK, sobre cifrado, ACK y recuperación/logon obligatorios antes de degradar. Depende del canal IT autorizado. |
| Auditoría de administradores (paso 10) | cliente | Implementada: RID 500 habilitado, miembros no declarados, contraseñas vacías y comprobaciones indeterminadas bloquean. |
| Logoff y verificación del token (pasos 12–13) | cliente | Implementados con aviso, WTS/SYSTEM y rechazo del SID Administradores incluso deny-only; pendiente de ensayo real consola/RDP. |
| **Release puente entregada por el auto-update de K3** | cliente | Lanzador compatible y empaquetador implementados. Sin release publicada: faltan artefactos/adaptador IT; el receptor K3 original sigue ejecutando antes de verificar y requiere confianza inicial independiente. |
| **Flujo de la elevación única (UAC)** | cliente | Implementado runas único, intento persistente y tarea SYSTEM reanudable. Cancelación conserva K3 y registra excepción local; enviar esa excepción al panel requiere el canal IT. |
| **Ocultar `azcadmin`** (paso 14) | cliente | Implementado DWORD UserList tras verificar tokens; cosmético, no seguridad. |
| **Endpoint de enrollment de migración** (autorización de un solo uso por tenant/device) | backend + cliente | Backend existente verificado en código; cliente PoP implementado. Emisión/validación requieren adminSession/CSRF mediante intermediario IT, nunca cookie administrativa en el bootstrap. |
| **Endpoint de escrow con ACK de persistencia + recuperación verificable** | backend + cliente | Backend existente; cliente implementa sobre/ACK/verify/recover/logon. Falta canal autorizado IT y provisión de libsodium en la release. |
| **Estado de migración por equipo en el panel** | backend + panel | GET/PUT existentes; cliente reporta cada transición con CAS y cola persistente a través del adaptador. Integración del canal y visualización del panel no verificadas en esta fase. |
| Baseline/Session (paso 9) | cliente | Gate obligatorio; integración verificable de baseline audit/IPC pendiente. No se sustituye por switches manuales. |
| **Certificado de code-signing + MSI firmado** | provisión | Scripts de certificado autofirmado, firma/timestamp y pin implementados; sin generar certificado/MSI aquí. Primer UAC: editor desconocido; raíz se instala durante esa elevación. Límites y mitigaciones en client-v4/installer/Keeper.Installer/README.md. |

---

## 7. Cómo se ve para ti (el objetivo)

1. IT publica la **release puente** en Keeper y elige el anillo (piloto → sedes → resto).
2. Los equipos la reciben por el auto-update que **ya existe**.
3. Cada empleado ve **un** diálogo: «AZCKeeper se está actualizando… ¿Permitir?» → **un clic**.
4. El panel muestra el avance **equipo por equipo**: instalado / enrolado / escrow OK / degradado /
   pendiente de reinicio / completo / **excepción** (requiere recuperación asistida).
5. IT solo atiende las excepciones.

**Piloto obligatorio de 5–10 equipos durante una semana antes de la flota.**
