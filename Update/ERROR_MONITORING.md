# Error Monitoring en UpdateManager

## Resumen

Se ha implementado un sistema completo de monitoreo de errores para el proceso de actualización/commits del cliente AZCKeeper. Este sistema permite rastrear, categorizar y reportar errores que ocurren durante la verificación y descarga de actualizaciones.

## Características Implementadas

### 1. Contadores de Errores Consecutivos

Se agregaron dos contadores que rastrean errores consecutivos:

- **`_consecutiveCheckErrors`**: Rastrea errores al verificar si hay actualizaciones disponibles
- **`_consecutiveDownloadErrors`**: Rastrea errores durante la descarga e instalación

Cuando se alcanzan **3 errores consecutivos**, se genera un log de ERROR crítico para alertar sobre problemas persistentes.

### 2. Categorización de Errores

Los errores se categorizan por tipo específico:

#### Durante la Verificación de Actualizaciones:
- **HttpRequestException**: Problemas de red/conectividad
- **JsonException**: Respuesta del servidor mal formateada
- **Exception genérica**: Otros errores inesperados

#### Durante la Descarga:
- **HttpRequestException**: Fallas de red al descargar
- **TaskCanceledException**: Timeout (más de 5 minutos)
- **IOException**: Problemas al guardar el archivo ZIP

#### Durante la Extracción e Instalación:
- **InvalidDataException**: Archivo ZIP corrupto
- **UnauthorizedAccessException**: Problemas de permisos
- **Win32Exception**: Errores al lanzar el updater

### 3. Logging Mejorado

Se agregaron logs detallados en cada paso crítico:

- ✅ Inicio de verificación de actualizaciones
- ✅ Confirmación de versión actual vs disponible
- ✅ Inicio de descarga con URL
- ✅ Tamaño del archivo descargado
- ✅ Progreso de extracción
- ✅ Verificación del updater
- ✅ Argumentos del updater
- ✅ PID del proceso updater lanzado

### 4. Recuperación Automática

El sistema resetea automáticamente los contadores de error cuando:
- Se completa exitosamente una verificación después de errores
- Se completa exitosamente una descarga después de errores

Esto permite que el sistema se recupere de problemas temporales.

### 5. Información Contextual

Cada mensaje de error incluye:
- Tipo específico del error
- Contexto de la operación (URL, ruta de archivo, etc.)
- Contador de errores consecutivos
- Límite máximo de errores permitidos

## Ejemplos de Logs

### Verificación Exitosa
```
2024-01-30 20:22:00.123 [INFO] UpdateManager: verificando actualizaciones...
2024-01-30 20:22:01.456 [INFO] UpdateManager: versión actual=3.0.0.0, disponible=3.0.0.0, mínima=2.0.0.0
2024-01-30 20:22:01.457 [INFO] UpdateManager: la aplicación está actualizada.
```

### Error de Red Temporal
```
2024-01-30 20:22:00.123 [ERROR] UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: 1/3
System.Net.Http.HttpRequestException: No connection could be made...
```

### Error Crítico (3 errores consecutivos)
```
2024-01-30 20:25:00.123 [ERROR] UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: 3/3
2024-01-30 20:25:00.124 [ERROR] UpdateManager: problemas persistentes de red detectados (3 intentos fallidos).
```

### Descarga Exitosa
```
2024-01-30 20:22:00.123 [INFO] UpdateManager: iniciando descarga de actualización v3.1.0.0 desde https://...
2024-01-30 20:22:05.456 [INFO] UpdateManager: descargando archivo a C:\Users\...\AZCKeeper_v3.1.0.0.zip...
2024-01-30 20:22:30.789 [INFO] UpdateManager: descarga completada exitosamente (15234KB).
2024-01-30 20:22:31.000 [INFO] UpdateManager: extrayendo actualización...
2024-01-30 20:22:35.123 [INFO] UpdateManager: extracción completada en C:\Users\...\v3.1.0.0
2024-01-30 20:22:35.200 [INFO] UpdateManager: updater verificado en C:\Users\...\v3.1.0.0\AZCKeeperUpdater.exe
2024-01-30 20:22:35.300 [INFO] UpdateManager: lanzando updater con argumentos: "C:\Program Files\AZCKeeper" "C:\Users\...\v3.1.0.0" "C:\Program Files\AZCKeeper\AZCKeeper.exe"
2024-01-30 20:22:35.400 [INFO] UpdateManager: updater lanzado exitosamente (PID: 12345). El cliente se cerrará en 1 segundo...
```

## Monitoreo de Errores

### Ubicación de Logs

Los logs se guardan en:
```
%APPDATA%\AZCKeeper\Logs\YYYY-MM-DD.log
```

### Filtrado de Errores

Para ver solo errores relacionados con actualizaciones:
```
findstr /C:"UpdateManager" %APPDATA%\AZCKeeper\Logs\*.log
```

Para ver solo errores críticos:
```
findstr /C:"[ERROR]" %APPDATA%\AZCKeeper\Logs\*.log | findstr /C:"UpdateManager"
```

## Resolución de Problemas Comunes

### Errores Persistentes de Red
**Síntoma**: `HttpRequestException` con 3/3 errores consecutivos
**Solución**: 
- Verificar conexión a Internet
- Verificar que el servidor API esté accesible
- Revisar configuración de firewall/proxy

### Archivo ZIP Corrupto
**Síntoma**: `InvalidDataException` al extraer
**Solución**:
- La descarga se reintentará automáticamente
- Verificar espacio en disco
- Revisar conexión de red (puede ser descarga parcial)

### Problemas de Permisos
**Síntoma**: `UnauthorizedAccessException`
**Solución**:
- Ejecutar como administrador
- Verificar permisos en `%LOCALAPPDATA%\AZCKeeper\Updates`

### Updater No Encontrado
**Síntoma**: "ERROR CRÍTICO - updater no encontrado"
**Solución**:
- El paquete de actualización está incompleto
- Contactar al administrador del servidor

## Configuración

El límite de errores consecutivos está definido en:
```csharp
private const int MaxConsecutiveErrors = 3;
```

Este valor puede ajustarse según las necesidades del entorno de producción.

## Notas Técnicas

- Los contadores de error se mantienen durante toda la vida del proceso
- Los contadores NO se resetean al cambiar el intervalo de verificación
- Los contadores se resetean solo tras una operación exitosa
- El sistema utiliza logging sanitizado para evitar exponer información sensible
