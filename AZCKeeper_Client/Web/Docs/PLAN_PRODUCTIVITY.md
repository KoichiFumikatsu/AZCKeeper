# Plan: Productividad, Focus Score y Detección Doble Empleo
## Estado: APROBADO — Pendiente de implementación (2026-03-07)

## TL;DR
Implementar 3 sistemas complementarios sobre la infraestructura existente de AZCKeeper:
1. **Productividad avanzada**: Puntualidad, deep work, ranking por equipo, tendencias
2. **Focus Score 0-100**: Métrica compuesta (context switching + deep work + distracción + puntualidad + constancia)
3. **Detección doble empleo**: Alertas automáticas por patrones sospechosos (after-hours, apps ajenas, remote desktop, idle anómalo)

Se muestran en una **nueva pestaña "Productividad"** en el admin panel + **resumen en el dashboard existente (index.php)**.
**Sin cambios al cliente C#** — todo server-side con datos existentes.

---

## Fase 1: Migración de BD — 3 tablas nuevas + settings

### Paso 1.1: Crear migración `add_productivity_focus.sql`
- **`keeper_focus_daily`**: Métricas de foco calculadas server-side por usuario/día
  - id, user_id, device_id, day_date
  - context_switches (INT), deep_work_seconds (INT), deep_work_sessions (INT)
  - distraction_seconds (INT), longest_focus_streak_seconds (INT)
  - focus_score (TINYINT 0-100), productivity_pct (TINYINT 0-100)
  - constancy_pct (TINYINT 0-100)
  - first_activity_time (TIME), scheduled_start (TIME), punctuality_minutes (SMALLINT)
  - created_at, updated_at
  - UNIQUE KEY (user_id, device_id, day_date)

- **`keeper_dual_job_alerts`**: Alertas de posible doble empleo
  - id, user_id, day_date
  - alert_type ENUM('after_hours_pattern', 'foreign_app', 'remote_desktop', 'suspicious_idle')
  - severity ENUM('low', 'medium', 'high')
  - evidence_json (JSON), is_reviewed BOOL, reviewed_by INT NULL, reviewed_at DATETIME NULL, notes TEXT NULL
  - created_at

- **`keeper_suspicious_apps`**: Lista configurable de apps sospechosas
  - id, app_pattern VARCHAR(190), category ENUM('remote_desktop', 'foreign_vpn', 'foreign_workspace', 'vm')
  - description VARCHAR(255), is_active BOOL DEFAULT 1
  - Seeds: TeamViewer, AnyDesk, VirtualBox, VMware, Parsec, Chrome Remote Desktop, RustDesk

### Paso 1.2: Settings en `keeper_panel_settings`
- productivity.deep_work_threshold_minutes: 25
- productivity.focus_weights: JSON con pesos
- dual_job.after_hours_threshold_days: 5
- dual_job.after_hours_min_seconds: 3600
- dual_job.enabled: true

---

## Fase 2: Backend — Cálculo de métricas (PHP)

### Paso 2.1: `src/Services/ProductivityCalculator.php`
Método: `calculateDay($pdo, $userId, $dayDate)`

1. **Context Switches**: Contar cambios de `process_name` entre episodios consecutivos (LAG) en horario laboral. Excluir micro-switches <5s.

2. **Deep Work (con validación de actividad)**:
   - 2a: Agrupar episodios consecutivos de mismo `process_name` (excluir leisure)
   - 2b: Cruzar con ratio de actividad del día (`work_active / (work_active + work_idle)`) para estimar tiempo efectivo
   - 2c: Penalizar títulos sospechosos (YouTube, Reddit, etc. en chrome.exe). Penalizar bloques >2h sin cambio (factor 0.7 después de 2h).
   - 2d: Micro-episodios <5s entre misma app = señal de foco activo, no distracción

3. **Distraction Seconds**: Leisure apps por `process_name` (reutilizar `getLeisureApps()`) + patrones de ocio en `window_title`. Solo horario laboral.

4. **Puntualidad**: `MIN(start_at)` del día vs `work_start_time` del schedule

5. **Constancia**: Dividir horario en bloques de 30 min, contar bloques con ≥1 episodio

6. **Focus Score (0-100)**: 20% context_switches + 25% deep_work + 20% distracción + 15% puntualidad + 20% constancia

7. **Productivity %**: `(work_active - distraction) / (work_active + work_idle) * 100`

### Paso 2.2: `src/Services/DualJobDetector.php`
Método: `analyze($pdo, $userId)` — ventana deslizante 30 días

1. After-Hours Pattern: ≥5 días con >1h after-hours → medium; ≥15 → high
2. Foreign Apps: Matcheo contra `keeper_suspicious_apps` por process_name/window_title
3. Remote Desktop/VMs: >30 min/día en horario laboral, ≥3 días
4. Suspicious Idle: >70% idle en horario + >30 min after-hours, ≥5 días
5. Deduplicación: No crear si existe alerta del mismo tipo en últimos 7 días

### Paso 2.3: `src/Endpoints/ProductivityCron.php`
- Protegido por API key. Cron diario 2AM.
- Recorre usuarios activos en lotes de 50 con pausa 100ms
- Ejecuta calculateDay() para ayer + analyze() para cada usuario
- Timeout 10s por usuario, log de errores y duración total

### Paso 2.4: `src/Repos/ProductivityRepo.php`
- getDailyMetrics(), getTeamRanking(), getTrends(), getAlerts(), reviewAlert()

---

## Fase 3: Frontend

### Paso 3.1: `public/admin/productivity.php` — NUEVO
4 secciones: KPIs globales (gauge 0-100, productividad, puntualidad, deep work, switches) + Ranking equipos (tabla por área) + Ranking individual (paginado con tendencias ↑↓→) + Tendencias (SVG 4 semanas)

### Paso 3.2: `public/admin/dual-job-alerts.php` — NUEVO
Tabla alertas con filtros, badges severidad, modal detalle con evidencia visual, botón revisado + notas, KPIs pendientes

### Paso 3.3: Actualizar `public/admin/index.php`
Reemplazar gauge actual (productividad/10) por Focus Score 0-100 de keeper_focus_daily. Top/Bottom 5. Badge alertas dual-job.

### Paso 3.4: Actualizar `public/admin/user-dashboard.php`
Card Focus Score 0-100 gauge, breakdown 5 componentes, gráfica evolución 7 días, banner alertas dual-job.

### Paso 3.5: Actualizar `layout_header.php`
Nav: "Productividad" (básica) + "Alertas Doble Empleo" (avanzada)

---

## Fase 4: Permisos
- productivity.can_view, productivity.can_export, dual_job.can_view, dual_job.can_review
- CRUD de keeper_suspicious_apps en configuración
- Umbrales y pesos configurables

---

## Archivos

### Existentes a modificar
- Web/public/admin/index.php — Dashboard (gauge focus, badge alertas)
- Web/public/admin/user-dashboard.php — Dashboard individual (breakdown focus)
- Web/public/admin/layout_header.php — Sidebar nav
- Web/public/admin/panel-settings.php — Settings nuevos
- Web/public/admin/roles.php — Módulos permisos nuevos

### Nuevos a crear
- Web/migrations/add_productivity_focus.sql
- Web/src/Services/ProductivityCalculator.php
- Web/src/Services/DualJobDetector.php
- Web/src/Repos/ProductivityRepo.php
- Web/src/Endpoints/ProductivityCron.php
- Web/public/admin/productivity.php
- Web/public/admin/dual-job-alerts.php

---

## Verificación
1. Migración SQL crea 3 tablas + settings sin errores
2. calculateDay() llena keeper_focus_daily con valores 0-100 coherentes
3. Focus Score en rango con datos extremos (0 episodios, solo leisure, solo deep work)
4. DualJobDetector con datos de prueba (10 días after-hours >1h) crea alerta medium
5. productivity.php renderiza 4 secciones con datos reales
6. dual-job-alerts.php tabla + modal + marcar como revisada
7. index.php gauge muestra nuevo Focus Score 0-100
8. user-dashboard.php breakdown focus correcto
9. Permisos: viewer sin dual_job.can_view → acceso denegado

---

## Decisiones
- Server-side via cron nocturno (2AM), no client-side
- Focus Score 0-100 reemplaza actual 0-10
- Dual-job = alertas para revisión humana, no bloqueos
- keeper_suspicious_apps configurable por admin
- Sin cambios al cliente C#
- Validación de foco: ratio actividad × duración ventana, penalización >2h, títulos sospechosos, constancia 30min bloques

## Dependencias
- Fase 1 (BD) → bloquea todo
- Fase 2 (Backend) → depende de F1; 2.1-2.2 paralelo; 2.3 depende de ambos
- Fase 3 (Frontend) → depende de F2; 3.1-3.5 paralelo entre sí
- Fase 4 (Permisos) → paralelo con F3

---

## Rendimiento e Infraestructura

### Servidor
- Xeon E3-1260L V5 (4c/8t), 32GB RAM (22GB libres), 2×SSD RAID, Hepsia panel
- MySQL Buffer Pool: 2GB actual → SUBIR a 6-8GB
- Load ~4.3 (al límite por core)

### Tuning MySQL recomendado
- innodb_buffer_pool_size = 6G (actualmente 2G)
- innodb_buffer_pool_instances = 4 (actualmente 2)
- innodb_log_file_size = 256M
- max_connections = 300

### Escala: 500 usuarios
- ~65-90 queries/seg
- keeper_window_episode: 1.5M-7.5M filas/mes, 4.5M-22.5M/trimestre

### BD partida
- BD Activa: último trimestre (lectura/escritura)
- BD Archivo: >3 meses (read-only, migración mensual, mismo servidor)
- keeper_focus_daily y keeper_dual_job_alerts incluidos en migración

### Impacto
- Cron nocturno: ~2-5 min para 500 usuarios (bajo)
- Vistas admin: leen keeper_focus_daily pre-calculada (más rápido que actual)
- Dashboard index.php: MEJORA rendimiento (quita cálculo en vivo)

### Multi-tenant futuro
- Múltiples clientes (~50 empleados c/u) en BDs separadas
- 10-30 clientes cómodo en este servidor
- 30-50 clientes → evaluar segundo servidor
