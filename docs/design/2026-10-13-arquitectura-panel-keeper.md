# Cómo se muestra Keeper — arquitectura del panel (decisión de Koichi, 2026-10-13)

Motivo: el panel actual es contraintuitivo. Se reorganiza en tres niveles: **Inicio → Personas → Administración**, con lo que cada rol puede ver según su alcance. Lo que ya existe (roles, alcances, visibilidad de módulos, organización, administradores) se reutiliza; cambia cómo se presenta.

## 1. Inicio (dashboard)
Métricas generales del alcance de quien entra (propio / equipo / filtros / todo), para HOY por defecto, con selector de período.
- **Problemas reportados**: lista accionable (cada fila lleva a la persona o al equipo). Fuentes: equipos sin conexión en horario, entrada simulada, K3 abierto, controles fallidos (WebEnforcer, USB, etc.), agente degradado, alertas de doble empleo y apps sospechosas sin revisar, ausencias por aprobar, fallo del cálculo de productividad (solo plataforma).
- **Llegadas**: cuántos llegaron a tiempo, tarde y temprano, y sin actividad (desde la presencia/puntualidad contra su horario).
- **Productividad en tiempo real** y **focus en tiempo real** (del día en curso, refrescado cada minuto).
- **Sedes activas**: sedes con al menos una persona en línea / total.
- **Activos / total de trabajadores**.

## 2. Personas
Lista de personas del alcance, una fila por persona:
- Estado en vivo (en línea / ausente / desconectado), productividad, focus, primer ingreso, última vez en uso, tiempo trabajado (período elegido).
- Acciones por fila: **Ver** (abre la ficha) y **Editar**.

**Editar** (formulario): nombre, correo, cédula, contraseña (si la persona tiene cuenta de panel o del Portal), estado laboral (contratado, retirado, licencia, etc.). Si el dato pertenece a otra base (p. ej. AZC One), el campo se muestra bloqueado con su origen y un botón **Actualizar desde <origen>**.

**Ficha de la persona** (su dashboard):
- Métricas de la persona: en uso, inactivo, bloqueado, simulado, productividad, focus, puntualidad, tendencia.
- Datos de su(s) equipo(s): nombre, versión, última conexión, estado de controles, K3, Smart App Control, actualizaciones.
- **Ventanas visitadas**: tabla ordenable por horas en uso, por número de veces visitada, por app o por título.
- **Línea de tiempo**: a qué hora abrió cada ventana (trazabilidad de cómo trabajó en el día).
- **Controles del equipo** desde la misma ficha: bloquear, desbloquear, reiniciar, apagar, borrado remoto (con confirmación escrita), actualizaciones (automáticas / pruebas / pausadas).

## 3. Administración (supervisores y administradores de empresa, según permisos)
- Festivos, horarios laborales.
- Aplicaciones y sitios a bloquear o permitir (reglas), clasificación de apps.
- Si es administrador: qué pueden ver sus colaboradores (visibilidad de módulos), roles del panel, crear y editar usuarios, agregar clientes o firmas, crear áreas y sedes (organización), y **Administradores**: qué persona es admin, gerente, supervisor, etc., y quién puede entrar al Portal.

## 4. Superadministrador
Todo lo anterior más todos los poderes de plataforma que ya existen (empresas, funciones y planes, versiones, mudanzas, integraciones, salud del servidor).

## Datos que hoy NO existen y hay que construir (verificado en el catálogo de la API)
- `GET /dashboard` (contrato sí, implementación no) — en curso por Codex.
- Lectura de ventanas por persona (episodios con app, título, hora, duración; agregados por app/título) — no hay endpoint en el panel; títulos requieren la función `sitios` y una decisión de privacidad.
- Estado laboral de la persona (contratado/retirado/…) y "actualizar desde origen" por persona.
- Feed de "problemas reportados" unificado.

## Decisiones cerradas (Koichi, 2026-10-13)
- **Ventanas**: se muestran solo **app y sitio** (p. ej. «Word», «Gmail»), nunca el título completo, para todos los roles por ahora.
- **Margen de 15 minutos en la hora de entrada**: llegar hasta 15 min después de la hora de entrada cuenta como **a tiempo**; más de 15 min después, **tarde**; más de 15 min antes, **temprano**. Hoy el cálculo marca «tarde» desde el primer segundo de retraso (`ActivityReports.php:176`, `late_seconds>0`): hay que cambiarlo.
- **Problemas reportados**: la lista propuesta (equipos sin conexión en horario, entrada simulada, K3 abierto, controles fallidos, agente degradado, doble empleo y apps sospechosas sin revisar, ausencias por aprobar; fallo del cálculo solo para plataforma).
- **El margen se edita en keep**: en la página de Horarios, una tarjeta «Margen de puntualidad» (minutos, por defecto 15) por empresa. Cambiarlo recalcula los últimos 30 días, igual que la clasificación de apps; los días más viejos conservan el cálculo anterior y la tarjeta lo advierte.

## Horarios en Grupo AZC (medido 2026-10-13)
- El modelo ya soporta un horario distinto por día de la semana y sábados de medio tiempo (`schedule_days`), y hay 15 horarios: de 07:00 a 10:00 de entrada, con viernes cortos y sábados. El margen de puntualidad se mide contra la hora de entrada de CADA persona en CADA día, así que un solo margen por empresa sirve aunque los horarios varíen.
- **98 de 314 personas están «Sin horario»**: para ellas no hay jornada esperada, ni puntualidad, ni productividad sobre jornada. Hay que asignarles horario antes de que esas métricas signifiquen algo.
- Hoy el horario se asigna persona por persona; las firmas/clientes (`org_units`) no tienen horario por defecto. Propuesta: horario por defecto en la firma/cliente o el área, heredado por sus personas salvo que la persona tenga uno propio.
- La pantalla Horarios debe permitir crear horarios como «L-J 10:30-19:00 · V 10:30-17:30 · S 08:00-12:00» sin escribir códigos.
- **Horario predeterminado de la empresa (Koichi, 2026-10-13): lunes a jueves 08:00-17:30, viernes 08:00-17:00 (Bogotá).** Ya existe como «L-J 08:00-17:30 · V 08:00-17:00 (Bogota)» (112 personas). Se configura en Horarios como predeterminado de la empresa; toda persona sin horario propio usa ese (hoy 98 «Sin horario»: 68 Cali, 26 Medellín, 4 sin sede). La firma/área podrá tener su propio horario por defecto después, si hace falta.

## Portal y planes (Koichi, 2026-10-13)
- **Todo lo que ve el colaborador/supervisor en el panel debe existir también como API para el Portal.** Decisión de diseño: una sola implementación por dato y dos puertas de entrada — la sesión del panel (keep) y un token por persona del Portal (`/portal/v1`) — con el MISMO alcance (propio/equipo/filtros/todo, `AdminAccess`). El Portal solo llega a una lista blanca de operaciones de lectura (Inicio, Personas, ficha, ventanas, línea de tiempo, productividad, puntualidad, ausencias) más las escrituras propias (registrar ausencia, cambiar contraseña). Nada de duplicar endpoints.
- **Los planes activan o desactivan funciones por persona**, y el panel del cliente es donde se asignan. Funciones ligadas a plan: comandos remotos (bloquear, desbloquear, reiniciar, apagar), borrado remoto, **mensajes en pantalla** (no existe aún: comando nuevo del agente), y en un plan superior **capturas de pantalla** y lo que venga después.
- Verificado: el catálogo `Features.php` ya tiene `comandos` y `borrado` a nivel persona, pero `createDeviceCommand` solo exige el permiso del administrador (`AdminApi.php:19-28`), no que el plan del DUEÑO del equipo incluya la función. Hay que exigirlo (403 `feature_not_in_plan`) y ocultar/deshabilitar el botón en el panel con el motivo.

## Superadministrador: mismo poder, sin confusión (Koichi, 2026-10-13; va DESPUÉS del encargo 2)
- Conserva todo lo que existe (empresas, funciones y paquetes, planes por persona, versiones y anillos, mudanzas, integraciones y fuentes, roles y permisos, ajustes de Windows, PIN, endurecimiento, salud). Lo granular se queda; lo que cambia es cómo se encuentra y se entiende.
- Método: (1) recorrido de cada pantalla de plataforma como un usuario sin experiencia, con capturas, anotando dónde se pierde, qué palabra no entiende y cuántos clics cuesta cada tarea; (2) reorganizar por TAREAS en lenguaje llano («Dar de alta un cliente», «Elegir qué funciones tiene un cliente», «Asignar un plan a personas», «Publicar una versión a prueba», «Configurar seguridad de Windows»), cada una con su pantalla guiada, valores por defecto y explicación de una línea por opción; (3) validar con el dueño antes de construir.
