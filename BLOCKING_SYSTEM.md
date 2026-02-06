# Sistema de Bloqueo Remoto - Documentación

## Arquitectura

### Componentes

1. **Backend PHP**:
   - `DeviceLock.php` - Endpoints para bloqueo/desbloqueo
   - `ClientHandshake.php` - Configuración inicial (cada 5 min)
   - `keeper_device_locks` - Tabla para registrar desbloqueos manuales

2. **Cliente C#**:
   - `KeyBlocker.cs` - Módulo de bloqueo de pantallas
   - `CoreService.cs` - Timer de 30s para verificar estado
   - `config.json` - Configuración local (incluye PIN)

### Flujo de Bloqueo

1. **Admin activa bloqueo** (user-config.php):
   - Marca `blocking.enableDeviceLock = true` en la política
   - NO crea registro en `keeper_device_locks` todavía

2. **Cliente detecta bloqueo**:
   - Handshake (cada 5 min) o Timer de lock status (cada 30s)
   - Recibe `enableDeviceLock=true`
   - Bloquea todas las pantallas con KeyBlocker

3. **Usuario desbloquea con PIN**:
   - Valida PIN localmente contra `config.json`
   - Llama a `/device-lock/unlock`
   - Backend crea registro en `keeper_device_locks` con `unlocked_at = NOW()`

4. **Sistema respeta desbloqueo manual**:
   - Handshake y getStatus() consultan `keeper_device_locks`
   - Si encuentran `unlocked_at != NULL` → NO bloquean
   - Sobrescriben la política: `enableDeviceLock = false`

### Configuración en Panel Admin

#### Módulos > "Módulo de Bloqueo"
- Campo: `modules.enableBlocking`
- Función: **Habilita la funcionalidad** de bloqueo
- Efecto: Crea instancia de `KeyBlocker` en el cliente
- **Debe estar activado** para que el bloqueo funcione

#### Bloqueo Remoto > "BLOQUEAR EQUIPOS"
- Campo: `blocking.enableDeviceLock`
- Función: **Activa el bloqueo** inmediato
- Efecto: Bloquea las pantallas del usuario en ~30 segundos

**Diferencia clave**:
- `modules.enableBlocking` = Encender el coche (motor)
- `blocking.enableDeviceLock` = Pisar el acelerador (acción)

### Tabla keeper_device_locks

```sql
CREATE TABLE keeper_device_locks (
  id bigint PRIMARY KEY,
  device_id bigint NOT NULL,
  user_id bigint NOT NULL,
  lock_reason varchar(500),
  locked_at timestamp NOT NULL,
  unlocked_at timestamp NULL,          -- NULL=bloqueado, NOT NULL=desbloqueado
  is_active tinyint(1) DEFAULT 1
);
```

**Estados**:
- No existe registro → usar política normal
- Existe con `unlocked_at=NULL` → forzar bloqueo (bloqueo manual del admin)
- Existe con `unlocked_at!=NULL` → forzar desbloqueo (usuario desbloqueó con PIN)

### Lógica de Decisión

```php
$policyEnableLock = $effective['blocking']['enableDeviceLock'] ?? false;
$wasManuallyUnlocked = PolicyRepo::isManuallyUnlocked($pdo, $deviceId);

// Si fue desbloqueado manualmente, respetar ese estado
$shouldBeLocked = $wasManuallyUnlocked ? false : $policyEnableLock;
```

### Casos de Uso

#### Caso 1: Bloqueo por política
1. Admin marca "BLOQUEAR EQUIPOS" ✅
2. Cliente bloquea en 30s
3. Usuario ingresa PIN correcto
4. Se crea registro con `unlocked_at=NOW()`
5. Sistema NO vuelve a bloquear (respeta desbloqueo manual)

#### Caso 2: Admin desactiva bloqueo
1. Admin desmarca "BLOQUEAR EQUIPOS" ❌
2. Cliente recibe `enableDeviceLock=false`
3. Desbloquea automáticamente
4. No necesita crear registro en `keeper_device_locks`

#### Caso 3: Varios intentos de PIN
1. Usuario intenta PIN incorrecto → validación local falla
2. No se comunica con servidor
3. Intenta PIN correcto → desbloquea localmente
4. Notifica servidor → marca como desbloqueado
5. No se vuelve a bloquear

### Mantenimiento

Ejecutar periódicamente (cron job):
```sql
-- Limpiar registros antiguos (7 días)
UPDATE keeper_device_locks 
SET is_active = 0 
WHERE unlocked_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND is_active = 1;
```

### Debugging

**Logs del cliente** (`%APPDATA%\AZCKeeper\`):
```
CoreService.CheckDeviceLockStatus(): shouldBeLocked=true, isCurrentlyLocked=false
CoreService: BLOQUEANDO dispositivo. PIN='1234'
KeyBlocker: 3 pantallas bloqueadas de 3 detectadas.
TryUnlock: PIN validado correctamente
TryUnlock: Servidor notificado exitosamente del desbloqueo.
CoreService: DESBLOQUEANDO dispositivo desde timer.
```

**Verificar estado en BD**:
```sql
SELECT 
  d.device_name,
  u.display_name,
  l.locked_at,
  l.unlocked_at,
  CASE 
    WHEN l.unlocked_at IS NOT NULL THEN 'Desbloqueado'
    WHEN l.unlocked_at IS NULL THEN 'BLOQUEADO'
    ELSE 'Sin bloqueo manual'
  END as estado
FROM keeper_devices d
LEFT JOIN keeper_users u ON d.user_id = u.id
LEFT JOIN keeper_device_locks l ON d.id = l.device_id AND l.is_active = 1
ORDER BY d.last_seen_at DESC;
```

### Troubleshooting

#### Problema: Se re-bloquea cada 30 segundos
**Causa**: Handshake no está respetando desbloqueo manual  
**Solución**: ✅ Ya implementado con `isManuallyUnlocked()`

#### Problema: No bloquea aunque la política dice que sí
**Causa**: `modules.enableBlocking` está desactivado  
**Solución**: Activar "Módulo de Bloqueo" en Módulos

#### Problema: Error HTTP al desbloquear
**Causa**: Timeout o pérdida de conexión  
**Solución**: ✅ Ya implementado - desbloquea localmente de todas formas

#### Problema: Bucle bloqueo/desbloqueo
**Causa**: Conflicto entre handshake y timer de lock status  
**Solución**: ✅ Ya implementado - ambos consultan `keeper_device_locks`
