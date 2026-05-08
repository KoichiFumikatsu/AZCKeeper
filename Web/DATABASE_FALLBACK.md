# ==================================================
# DOCUMENTACIÓN - SISTEMA DE FALLBACK SEGURO
# ==================================================

## 📋 RESUMEN
Sistema de conexión a base de datos con failover automático y sin credenciales hardcodeadas.

## 🔒 ARQUITECTURA DE SEGURIDAD

### Orden de Prioridad:
1. **Base de datos primaria** → Lee credenciales de `.env`
2. **Base de datos de respaldo** → Lee credenciales de `.env.backup`
3. **Sin fallback hardcodeado** → Si ambas fallan, lanza Exception clara

### Ventajas:
✅ CERO credenciales en código fuente
✅ Failover automático transparente
✅ Logs de auditoría de conexiones
✅ Múltiples entornos sin modificar código
✅ Timeout de 5 segundos por intento
✅ Mensajes de error descriptivos

## 📁 ARCHIVOS DE CONFIGURACIÓN

### .env (Base de datos principal)
```env
DB_HOST=mysql.server1872.mylogin.co
DB_NAME=pipezafra_verter
DB_USER=pipezafra_verter
DB_PASS=YOUR_SECURE_PASSWORD

DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

### .env.backup (Base de datos de respaldo)
```env
DB_HOST=localhost
DB_NAME=keeper_backup
DB_USER=root
DB_PASS=YOUR_BACKUP_PASSWORD

DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

## 🚀 CONFIGURACIÓN INICIAL

### 1. Crear archivo de configuración principal
```bash
cp .env.example .env
# Editar .env con credenciales reales
```

### 2. (Opcional) Crear archivo de respaldo
```bash
cp .env.backup.example .env.backup
# Editar .env.backup con servidor de respaldo
```

### 3. Verificar que .gitignore excluye archivos sensibles
```gitignore
.env
.env.backup
.env.production
```

## 🔍 COMPORTAMIENTO DEL SISTEMA

### Escenario 1: Todo OK
```
✅ Conecta a BD principal (.env)
📝 Log: "DB Connection [primary]: SUCCESS"
```

### Escenario 2: BD Principal caída
```
⚠️ Falla conexión a BD principal
📝 Log: "DB Connection [primary]: FAILED - Error: ..."
✅ Conecta automáticamente a BD de respaldo
📝 Log: "DB Connection [backup]: SUCCESS"
⚠️ Error log: "KEEPER WARNING: Usando BD de RESPALDO"
```

### Escenario 3: Ambas BDs caídas
```
❌ Falla conexión primaria
❌ Falla conexión de respaldo
💥 Exception: "KEEPER CRITICAL: No se puede conectar..."
   - Mensaje incluye errores de ambas conexiones
   - Sistema no continúa (fail-safe)
```

## 📊 MONITOREO Y DEBUGGING

### Verificar origen de conexión activa
```php
$source = Keeper\Db::getActiveSource();
// Retorna: 'primary', 'backup', o null
```

### Logs de auditoría (error_log de PHP)
```
[2026-02-05 10:30:15] DB Connection [primary]: SUCCESS
[2026-02-05 12:45:20] DB Connection [primary]: FAILED - Error: Connection timed out
[2026-02-05 12:45:25] DB Connection [backup]: SUCCESS
```

## 🛡️ MEJORES PRÁCTICAS

### ✅ HACER:
- Usar diferentes credenciales para primaria y respaldo
- Rotar contraseñas periódicamente
- Monitorear logs para detectar failovers frecuentes
- Tener backup en servidor diferente (alta disponibilidad)
- Mantener .env.example actualizado (sin credenciales)

### ❌ NO HACER:
- Commitear archivos .env al repositorio
- Usar mismas credenciales en primaria y respaldo
- Hardcodear credenciales en código
- Compartir .env por email/chat
- Dejar archivos .env.backup.example con credenciales

## 🔧 MIGRACIÓN DESDE SISTEMA ANTERIOR

### Antes (INSEGURO):
```php
$pass = Config::get('DB_PASS', 'z3321483Z@!$2024**'); // ❌ Hardcoded
```

### Después (SEGURO):
```php
$pass = Config::get('DB_PASS'); // ✅ Solo desde .env
if (!$pass) throw new PDOException("Credenciales incompletas");
```

## 🚨 TROUBLESHOOTING

### Error: "Credenciales incompletas en .env"
**Solución:** Verificar que .env contiene DB_HOST, DB_NAME, DB_USER, DB_PASS

### Error: "Archivo .env.backup no existe"
**Solución:** Si no necesitas respaldo, el sistema lanzará la exception de la BD primaria

### Sistema siempre usa BD de respaldo
**Solución:** Revisar logs, probablemente la primaria está caída o tiene credenciales incorrectas

## 📝 EJEMPLO DE USO

```php
<?php
require_once __DIR__ . '/src/bootstrap.php';

try {
    $pdo = Keeper\Db::pdo();
    
    // Verificar origen de conexión
    $source = Keeper\Db::getActiveSource();
    echo "Conectado a BD: {$source}\n";
    
    // Usar normalmente
    $stmt = $pdo->query("SELECT VERSION()");
    echo "MySQL: " . $stmt->fetchColumn() . "\n";
    
} catch (Exception $e) {
    echo "Error crítico: " . $e->getMessage() . "\n";
    // Aquí podrías enviar alerta a Discord, email, etc.
}
```

## 🔐 SEGURIDAD ADICIONAL

### Recomendaciones de producción:
1. **Permisos de archivos:**
   ```bash
   chmod 600 .env .env.backup
   ```

2. **Usuario MySQL dedicado:**
   ```sql
   CREATE USER 'keeper_app'@'%' IDENTIFIED BY 'strong_password';
   GRANT SELECT, INSERT, UPDATE ON keeper_db.* TO 'keeper_app'@'%';
   ```

3. **SSL/TLS en conexiones:**
   ```php
   PDO::MYSQL_ATTR_SSL_CA => '/path/to/ca-cert.pem'
   ```

4. **Variables de entorno del sistema (alternativa):**
   ```bash
   export DB_PASS="secure_password"
   ```

## 📚 REFERENCIAS
- PHP PDO: https://www.php.net/manual/en/book.pdo.php
- MySQL Best Practices: https://dev.mysql.com/doc/
- OWASP Database Security: https://owasp.org/

---
**Última actualización:** 2026-02-05  
**Mantenedor:** AZCKeeper Team
