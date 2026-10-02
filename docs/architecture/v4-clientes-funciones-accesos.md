# v4 — Clientes, funciones vendibles y accesos

Diseño aprobado por Koichi el 2026-10-02. Viene de la reunión con el gerente: Keeper es un producto independiente, sus
módulos se encienden y apagan, y los accesos se parametrizan de forma granular. Reemplaza el uso actual de
«módulos», «tiers por empresa» y «alcance en el rol».

## 1. Modelo

```
Plataforma (AZC Keeper, superadmin)
  └─ Cliente (lo que compra Keeper: Grupo AZC, empresa X externa)      = tenant
       ├─ Funciones compradas (catálogo + paquete + ajustes de la venta)
       ├─ Empresas (AZC Legal, BilingueLaw...)                          = org_units kind 'company'
       ├─ Países > ciudades > sedes, áreas, cargos, horarios
       └─ Personas (en una empresa o en "todo el cliente")
            ├─ Plan por persona (Básico, Pro... de la plataforma)
            └─ Acceso al panel = 1 rol + 1 alcance
```

Casos que guían el diseño:

| Persona | Rol | Alcance |
|---|---|---|
| Andrés Zafra (corporativo) | Corporativo | todo |
| IT del corporativo | IT | todo |
| Gerente de BilingueLaw | Gerente | Empresa = BilingueLaw |
| Juan Galviz | Coordinador | Área = Agentes de servicio (todas las sedes) |
| Otro coordinador | Coordinador | Área = Agentes de servicio; Sede = Tequendama, Medellín 808 |
| Cliente externo | Admin del cliente | todo su cliente; nunca superadmin |

## 2. Catálogo de funciones

El catálogo vive en el código (`src/Features.php`), un solo archivo versionado con el código que lo respeta. Las
elecciones viven en la base. Para cada función, el catálogo declara:

- su código y nombre;
- su nivel: `person` o `client`;
- los permisos del panel que incluye;
- las pantallas del menú que habilita;
- los controles del agente que activa.

### Núcleo (siempre incluido, no se vende)

Panel y entrada; organización (empresas, sedes, áreas, cargos); horarios; personas y equipos (ver y registrar);
actualización del agente; inventario; protección contra manipulación.

### Funciones vendibles

| # | Código | Función | Nivel |
|---|---|---|---|
| 1 | `productividad` | Actividad y productividad (apps, foco, ranking, reportes) | persona |
| 2 | `titulos` | Títulos de ventana | persona |
| 3 | `clasificacion` | Clasificación de apps propia | cliente |
| 4 | `presencia` | Presencia y puntualidad | persona |
| 5 | `festivos` | Festivos por país | cliente |
| 6 | `ausencias` | Ausencias de RR. HH. | cliente |
| 7 | `puertas` | Entradas por puerta | persona |
| 8 | `ubicacion` | Ubicación del equipo | persona |
| 9 | `web` | Bloqueo de sitios web | persona |
| 10 | `usb` | Bloqueo de USB | persona |
| 11 | `descargas` | Control de descargas | persona |
| 12 | `instalaciones` | Control de instalación de programas | persona |
| 13 | `comandos` | Comandos remotos (bloquear, desbloquear, reiniciar, apagar) | persona |
| 14 | `borrado` | Borrado remoto | persona |
| 15 | `endurecimiento` | Endurecimiento de Windows (cuentas locales) | persona |
| 16 | `cumplimiento` | Doble empleo y apps sospechosas | persona |
| 17 | `cobertura` | Cobertura de instalación | cliente |
| 18 | `autogestion_personas` | El cliente crea y edita sus personas | cliente |
| 19 | `autogestion_accesos` | El cliente gestiona sus roles y cuentas | cliente |
| 20 | `auditoria` | Auditoría visible para el cliente | cliente |
| 21 | `api` | API para otros sistemas | cliente |
| 22 | `one` | Conexión con AZC One como fuente de datos | cliente |
| 23 | `webhooks` | Avisos automáticos (webhooks) | cliente |

### Solo de la plataforma (nunca se venden)

Versiones del agente, despliegues, migración desde K3, salud del servidor, gestión de clientes, paquetes y planes.

### Paquetes

Los paquetes («Básico», «Pro»…) son atajos: marcan funciones del catálogo. Los define el superadmin en el panel. Un
cliente nace de un paquete y luego se ajusta función por función. Se guarda el paquete de origen.

## 3. Cómo se aplica

Una función de nivel **cliente** está activa si el cliente la compró. Una de nivel **persona** está activa si el
cliente la compró **y** el plan de la persona la incluye.

Se respeta en tres lugares:

1. **API.** Cada permiso pertenece a una función. Si el cliente no la compró, la respuesta es 403 con detalle
   `feature_not_contracted:<código>`, aunque el rol tenga el permiso.
2. **Menú.** `GET /me/access` devuelve, además de los permisos, las funciones activas del cliente. `nav-access.js`
   oculta lo que el cliente no compró.
3. **Agente.** `PolicyCompiler` solo emite los controles de las funciones activas para la persona del equipo.

Apagar una función no borra datos: se ocultan y vuelven a verse al encenderla.

### Planes por persona

- Un catálogo único de la plataforma (Básico, Pro…), cada plan con sus funciones de nivel persona.
- Sin cupos. La ficha del cliente muestra el conteo por plan para facturar.
- Asignar un plan a una persona exige el permiso `planes.asignar`, que el superadmin da o no a los roles del cliente.

## 4. Accesos: 1 rol + 1 alcance por persona

- **Rol.** Define qué puede hacer: un conjunto de permisos.
  - Los roles base vienen de plantillas de la plataforma: Corporativo, Gerente, Coordinador, IT, Colaborador.
  - El cliente solo edita roles o crea roles propios si compró `autogestion_accesos`.
  - Un rol nunca incluye permisos de funciones que el cliente no compró.
- **Alcance.** Define qué datos ve. Va en la persona, no en el rol.
  - `filters`: Empresa, Ciudad, Sede y Área. Cada filtro es «todas» o una lista. Dentro de un filtro, los valores
    se suman (O); entre filtros, se cruzan (Y).
  - `team`: quienes le reportan, directa o indirectamente.
  - `self`: solo él. **Es el valor por defecto.**
- **Delegación segura.** Nadie da un rol con permisos que no tiene, ni un alcance más amplio que el propio.
- **Asignación automática por cargo.** Propone el rol, no el alcance.
- **Una persona, un acceso.** Si un caso no encaja, se amplía su alcance; no se le dan dos roles.

## 5. Panel

- **Ficha del cliente** (superadmin). Reemplaza «Empresas». Tiene cuatro pestañas:
  - Datos y marca;
  - Funciones: paquete + interruptores;
  - Planes en uso: conteos;
  - Cuentas.

  «Crear cliente» pide nombre, paquete y primer administrador.
- **Puesta en marcha** (cliente). Pasos ordenados con su estado y un botón para hacer cada uno:
  1. Empresas
  2. Países, ciudades y sedes
  3. Áreas y cargos
  4. Horarios
  5. Personas
  6. Accesos
  7. Clave de registro
  8. Instalar Keeper

  Los pasos que no aplican según lo comprado se marcan «no aplica».
- **«+ Nuevo» dentro de los formularios.** Crea empresa, sede, área, cargo u horario sin salir del formulario de
  persona, reglas o accesos.
- **Accesos en la ficha de la persona.** Rol + alcance, resumidos en una frase legible.
- **Vocabulario.** Cliente → Empresas → personas. «Grupos empresariales entre clientes» sale de la vista; el
  backend `company_groups` se conserva.

## 6. Migración

- **AZCLegal (Grupo AZC)** recibe las 23 funciones encendidas.
- **Tiers por cliente → planes de la plataforma.** Las suscripciones conservan a sus personas. Si dos clientes
  tienen un tier con el mismo nombre y funciones distintas, se crean planes separados y se reportan.
- **Alcance de los roles → alcance de cada persona.** El alcance de área o sede de un rol se copia a cada persona
  que lo tiene; nadie gana ni pierde visibilidad.
- **Varios roles → uno.** Queda el de mayor alcance y el caso se reporta (hoy no hay ninguno).
- **Los 6 `modules` internos** se absorben en el catálogo.

## 7. Errores (en español en el panel)

| Código | Mensaje en el panel |
|---|---|
| `feature_not_contracted:<f>` | La función no está contratada por el cliente. |
| `scope_denied` | Tu alcance no incluye esa empresa, sede o área. |
| `delegation_exceeds_own_access` | No puedes dar un acceso más amplio que el tuyo. |

## 8. Pruebas

**Guardias del catálogo:**
- todo permiso pertenece a una función o al núcleo;
- toda pantalla del menú depende de una función o del núcleo;
- todo control del agente depende de una función;
- ninguna función queda sin uso.

**Casos:**
- Zafra ve todo.
- La gerente de BilingueLaw solo ve BilingueLaw.
- Juan solo ve Agentes de servicio, en todas las sedes.
- El coordinador de dos sedes solo ve esas dos.
- Nadie amplía su propio acceso.
- Una persona Básico no recibe USB aunque el cliente lo tenga para Pro.
- Apagar y encender una función conserva los datos.

## 9. Entrega por fases (cada una desplegable sola)

1. **Funciones por cliente.** Catálogo, paquetes, funciones por cliente y ficha del cliente; aplicación en API y
   menú.
2. **Planes por persona.** Planes de la plataforma por persona y aplicación en el agente.
3. **Accesos.** 1 rol + alcance por persona, con los cuatro filtros y delegación segura.
4. **Flujo guiado.** Puesta en marcha, «+ Nuevo» en formularios y vocabulario Cliente → Empresas.

## Fuera de alcance

- Talento Humano. Descartado del catálogo por ahora.
- Portal coordinador Keeper + One.
- Automatización de subdominios.
