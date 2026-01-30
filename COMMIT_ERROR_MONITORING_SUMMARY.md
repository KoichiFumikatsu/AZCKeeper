# Summary: Error Monitoring During Commit/Update Process

## Overview

This PR implements comprehensive error monitoring for the AZCKeeper update/commit process as requested in: "puedes monitorear que errores salen mientras actualizo commits?"

## Changes Implemented

### 1. Enhanced UpdateManager.cs

#### Error Tracking System
- Added `_consecutiveCheckErrors` counter for update verification failures
- Added `_consecutiveDownloadErrors` counter for download/install failures
- Implemented threshold detection (3 consecutive errors) with critical alerts
- Automatic error counter reset on successful operations

#### Categorized Exception Handling
The system now categorizes and tracks different types of errors:

**During Update Checks:**
- `HttpRequestException`: Network connectivity issues
- `JsonException`: Malformed server responses
- Generic exceptions: Unexpected errors

**During Download:**
- `HttpRequestException`: Network failures during download
- `TaskCanceledException`: Download timeout (>5 minutes)
- `IOException`: File system errors while saving

**During Extraction/Installation:**
- `InvalidDataException`: Corrupted ZIP files
- `UnauthorizedAccessException`: Permission issues
- `Win32Exception`: Process launch failures
- `FileNotFoundException`: Missing updater executable

#### Enhanced Logging
Added detailed logging at each critical step:
1. Update check start
2. Version comparison results
3. Download initiation and completion (with file size)
4. Extraction progress
5. Updater verification
6. Updater launch details (arguments, PID)
7. Error context with counter status

### 2. Documentation

Created `Update/ERROR_MONITORING.md` with:
- Feature documentation
- Error categorization guide
- Log examples for different scenarios
- Troubleshooting guide for common problems
- Log file location and filtering instructions

## Technical Details

### Error Counter Logic
- Counters increment only in the outer catch block (no double-counting)
- Counters display as `{current + 1}/{max}` in specific exception logs
- Counters reset only after complete successful operation
- Threshold alerts trigger at 3 consecutive errors

### Resource Management
- Fixed issue where early returns would bypass `finally` block
- Now uses exceptions instead of early returns to ensure `_isDownloading` flag cleanup
- Guarantees future update attempts won't be blocked by stuck flags

### Code Quality
- Removed redundant "ERROR CRÍTICO" text (log level already indicates severity)
- Fixed double-counting of errors in exception handlers
- Proper using directive for `Win32Exception` (System.ComponentModel)
- All exception handlers now properly propagate to outer catch for unified counting

## Security

✅ **CodeQL Security Scan**: No vulnerabilities detected

## Testing Notes

Since this is a Windows Forms application requiring Windows runtime:
- Cannot build/test on Linux environment
- Code review completed successfully
- Manual verification recommended on Windows platform
- Logging can be monitored at: `%APPDATA%\AZCKeeper\Logs\YYYY-MM-DD.log`

## Usage

The error monitoring is automatic and requires no configuration changes. Users can:

1. **Monitor errors in real-time**: Check log files in `%APPDATA%\AZCKeeper\Logs\`
2. **Filter update errors**: `findstr /C:"UpdateManager" %APPDATA%\AZCKeeper\Logs\*.log`
3. **Find critical issues**: Look for "se alcanzó el límite de errores consecutivos"

## Example Log Output

### Successful Update
```
2024-01-30 20:22:00.123 [INFO] UpdateManager: verificando actualizaciones...
2024-01-30 20:22:01.456 [INFO] UpdateManager: versión actual=3.0.0.0, disponible=3.1.0.0, mínima=2.0.0.0
2024-01-30 20:22:01.457 [WARN] UpdateManager: nueva versión 3.1.0.0 disponible. Crítica=False
2024-01-30 20:22:01.500 [INFO] UpdateManager: iniciando descarga de actualización v3.1.0.0...
2024-01-30 20:22:30.789 [INFO] UpdateManager: descarga completada exitosamente (15234KB).
2024-01-30 20:22:35.123 [INFO] UpdateManager: extracción completada en C:\Users\...\v3.1.0.0
2024-01-30 20:22:35.400 [INFO] UpdateManager: updater lanzado exitosamente (PID: 12345)
```

### Error Detection
```
2024-01-30 20:22:00.123 [ERROR] UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: 1/3
2024-01-30 20:25:00.124 [ERROR] UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: 2/3
2024-01-30 20:28:00.125 [ERROR] UpdateManager: error de red al verificar actualizaciones. Errores consecutivos: 3/3
2024-01-30 20:28:00.126 [ERROR] UpdateManager: problemas persistentes de red detectados (3 intentos fallidos).
```

## Files Modified

1. `Update/UpdateManager.cs` - Enhanced error monitoring and logging
2. `Update/ERROR_MONITORING.md` - Comprehensive documentation (new file)

## Benefits

1. **Visibility**: Clear visibility into update process failures
2. **Diagnostics**: Specific error types help identify root causes quickly
3. **Reliability**: Automatic recovery from transient errors
4. **Alerting**: Critical alerts when persistent problems detected
5. **Troubleshooting**: Detailed logs enable efficient support
