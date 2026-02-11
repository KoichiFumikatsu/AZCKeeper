# Optimización del Sistema de Estados de Conexión

## Fecha
11 de Febrero de 2026

## Problema Identificado

Los archivos del panel de administración (`users.php` y `user-dashboard.php`) estaban haciendo llamadas AJAX frecuentes a endpoints (`realtime-status.php` y `realtime-status-all.php`) para obtener el estado de conexión de los usuarios. Esto generaba:

1. **Sobrecarga innecesaria**: Peticiones HTTP cada 5-10 segundos
2. **Problemas de dominio cruzado**: La API está alojada en otro dominio
3. **Ineficiencia**: Los datos ya están en la misma base de datos

## Solución Implementada

### 1. Optimización de `index.php` (Dashboard Principal)

**Cambios realizados:**
- ✅ Agregada función `calculateUserStatus()` para calcular estados desde PHP
- ✅ Nueva sección "Estado de Conexiones en Tiempo Real" con contadores visuales
- ✅ Agregada consulta SQL para obtener `last_seen_at` y `last_event_at` de todos los usuarios
- ✅ Contador de usuarios por estado (Activos, Ausentes, Sin Conexión, Sin Dispositivo)
- ✅ Tabla de usuarios conectados actualmente con estados en tiempo real
- ✅ Indicadores visuales con animación para usuarios activos
- ✅ 0 llamadas AJAX - todo calculado en el servidor

**Antes:**
```php
// Solo mostraba estadísticas generales
// Sin información de estados de conexión
```

**Después:**
```php
// Muestra 4 contadores de estado
🟢 Activos: X usuarios
🟡 Ausentes: X usuarios  
🔴 Sin Conexión: X usuarios
⚫ Sin Dispositivo: X usuarios

// + Tabla con usuarios conectados actualmente
```

### 2. Optimización de `users.php`

**Cambios realizados:**
- ✅ Agregada función `calculateUserStatus()` para calcular estados desde PHP
- ✅ Modificada consulta SQL para incluir `last_seen_at` y `last_event_at`
- ✅ Estados calculados directamente al cargar la página
- ✅ Eliminadas llamadas AJAX automáticas (código comentado disponible si se necesita)
- ✅ Reducción de peticiones HTTP innecesarias

**Antes:**
```javascript
// Llamadas AJAX cada 10 segundos
updateAllUserStatuses();
setInterval(updateAllUserStatuses, 10000);
```

**Después:**
```php
// Estados calculados en PHP al cargar la página
$user['connection_status'] = calculateUserStatus($user['last_seen'], $user['last_event']);
```

### 3. Optimización de `user-dashboard.php`

**Cambios realizados:**
- ✅ Agregada función `calculateUserStatus()` 
- ✅ Estado inicial calculado desde la BD al cargar
- ✅ Eliminadas llamadas AJAX automáticas (disponibles comentadas)
- ✅ Reducción de intervalo de actualización de 5s a 30s (si se reactiva)

**Antes:**
```html
<span class="status-badge status-unknown">Cargando...</span>
```

**Después:**
```php
<span class="status-badge <?= $currentStatusInfo['class'] ?>">
    <?= $currentStatusInfo['text'] ?>
</span>
```

### 4. Documentación de Endpoints

**`realtime-status-all.php` y `realtime-status.php`:**
- ✅ Agregados comentarios explicativos sobre optimización
- ✅ Documentada lógica de estados
- ✅ Sugerencias de índices para mejor rendimiento
- ✅ Confirmado que YA consultan la BD directamente (no APIs externas)

## Lógica de Estados

Los estados se calculan usando dos campos de la base de datos:

### Campos Utilizados

| Campo | Tabla | Descripción |
|-------|-------|-------------|
| `last_seen_at` | `keeper_devices` | Heartbeat del dispositivo (actualizado por el cliente) |
| `last_event_at` | `keeper_activity_day` | Última actividad registrada del día actual |

### Estados Posibles

| Estado | Condición | Descripción |
|--------|-----------|-------------|
| **offline** | Sin `last_seen_at` | Usuario sin dispositivos registrados |
| **inactive** | `last_seen_at` > 15 min | Dispositivo desconectado (PC apagado, sin internet, app cerrada) |
| **away** | `last_seen_at` < 15 min pero `last_event_at` > 2 min | Dispositivo conectado pero usuario ausente |
| **active** | `last_event_at` < 2 min | Usuario activamente trabajando |

### Tiempos de Referencia

```php
// Heartbeat timeout: 900 segundos (15 minutos)
if ($secondsSinceLastSeen >= 900) return 'inactive';

// Actividad reciente: 120 segundos (2 minutos)
if ($secondsSinceLastEvent < 120) return 'active';
```

## Beneficios de la Optimización

### Rendimiento
- ⚡ **Carga inicial más rápida**: Estados disponibles inmediatamente
- 🔄 **Menos peticiones HTTP**: 0 peticiones automáticas (antes: cada 5-10s)
- 💾 **Menos carga en el servidor**: Sin endpoints constantemente consultados
- 📊 **Misma información**: Usando datos ya disponibles en la BD

### Mantenibilidad
- 📝 **Código más limpio**: Lógica centralizada en funciones
- 🔧 **Más fácil de debugear**: Todo en PHP servidor
- 📚 **Mejor documentado**: Comentarios explicativos agregados

### Flexibilidad
- 🔌 **Actualizaciones opcionales**: Código comentado disponible
- ⏱️ **Intervalos ajustables**: De 5s a 30s si se reactiva
- 🎯 **Sin dependencias externas**: No depende de APIs en otros dominios

## Activación de Actualizaciones en Tiempo Real (Opcional)

Si necesitas actualizaciones automáticas, puedes descomentar el código JavaScript en:

### En `users.php`:
```javascript
// Líneas ~280-320 - Busca el bloque comentado /* ... */
// Descomenta y ajusta el intervalo según necesites
```

### En `user-dashboard.php`:
```javascript
// Líneas ~735-788 - Busca el bloque comentado /* ... */
// Descomenta y ajusta el intervalo según necesites
```

**Recomendación:** Si activas las actualizaciones, usa intervalos de 30-60 segundos para no sobrecargar el servidor.

## Índices Recomendados (Opcional)

Para optimizar aún más las consultas, considera agregar estos índices si no existen:

```sql
-- Optimizar búsqueda de dispositivos por usuario
ALTER TABLE keeper_devices 
ADD INDEX idx_user_status_lastseen (user_id, status, last_seen_at);

-- Optimizar búsqueda de actividad por día
ALTER TABLE keeper_activity_day 
ADD INDEX idx_user_date_lastevent (user_id, day_date, last_event_at);
```

## Archivos Modificados

1. ✅ `Web/public/admin/index.php` - Dashboard principal con estados en tiempo real
2. ✅ `Web/public/admin/users.php` - Lista de usuarios con estados optimizados
3. ✅ `Web/public/admin/user-dashboard.php` - Dashboard individual optimizado
4. ✅ `Web/public/admin/realtime-status-all.php` - Documentación agregada
5. ✅ `Web/public/admin/realtime-status.php` - Documentación agregada
6. ✅ `Web/OPTIMIZACION_ESTADOS.md` - Este documento

## Pruebas Recomendadas

1. ✅ Verificar que el dashboard principal (`index.php`) muestre los contadores de estado correctamente
2. ✅ Verificar que la tabla de usuarios conectados aparezca cuando haya usuarios activos/ausentes
3. ✅ Verificar que los estados se muestren correctamente al cargar `users.php`
4. ✅ Verificar que el dashboard individual muestre el estado correcto
5. ✅ Confirmar que no hay errores en la consola del navegador
6. ✅ Verificar que los endpoints aún funcionen si se reactivan las actualizaciones

## Nuevas Características del Dashboard

### Sección de Estado de Conexiones (index.php)

El dashboard principal ahora incluye:

1. **Contadores por Estado**: Cuatro tarjetas que muestran cuántos usuarios están en cada estado
   - 🟢 **Activos**: Usuarios trabajando activamente (última actividad <2 min)
   - 🟡 **Ausentes**: Conectados pero sin actividad reciente
   - 🔴 **Sin Conexión**: Dispositivo desconectado (>15 min sin heartbeat)
   - ⚫ **Sin Dispositivo**: Usuarios sin dispositivos registrados

2. **Tabla de Usuarios Conectados**: Lista dinámica de usuarios actualmente conectados (active o away)
   - Estado visual con indicador de color
   - Cédula (CC)
   - Nombre completo
   - Última actividad registrada
   - Botón para ver dashboard individual

3. **Indicadores Visuales Animados**: Los usuarios activos muestran un punto verde pulsante

4. **Mensaje de Estado Vacío**: Si no hay usuarios conectados, se muestra un mensaje amigable

### Beneficios Adicionales

- **Vista centralizada**: Monitoreo de todos los usuarios desde una sola pantalla
- **Información instantánea**: Estados disponibles sin necesidad de navegar a otras páginas
- **Sin retrasos**: Datos calculados en el servidor sin esperas de carga
- **Actualización manual**: Recarga la página para ver estados actualizados (o reactiva AJAX si lo necesitas)

## Notas Técnicas

- Los endpoints `realtime-status.php` y `realtime-status-all.php` **ya estaban optimizados** consultando la BD
- El problema era el uso excesivo de AJAX desde el frontend
- La solución mantiene la misma información pero la obtiene de forma más eficiente
- El código AJAX original está disponible comentado para reactivación si es necesario

---

**Desarrollado por:** GitHub Copilot  
**Modelo:** Claude Sonnet 4.5  
**Fecha:** 11 de Febrero de 2026
