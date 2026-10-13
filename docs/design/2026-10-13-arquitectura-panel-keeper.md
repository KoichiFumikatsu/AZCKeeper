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
