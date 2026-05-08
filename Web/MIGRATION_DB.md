# 🚨 MIGRACIÓN URGENTE - Sistema de Base de Datos

## ⚠️ ACCIÓN REQUERIDA

El sistema de base de datos ha sido actualizado para eliminar **credenciales hardcodeadas** (ERROR #27 CRÍTICO).

## 📋 CHECKLIST DE MIGRACIÓN

### ✅ Paso 1: Verificar archivos creados
```bash
cd Web/
ls -la .env*
```

Deberías ver:
- ✅ `.env` (creado automáticamente con credenciales actuales)
- ✅ `.env.example` (plantilla sin credenciales)
- ✅ `.env.backup.example` (plantilla para respaldo)
- ✅ `.gitignore` (excluye archivos sensibles)

### ✅ Paso 2: (PRODUCCIÓN) Actualizar .env
El archivo `.env` ya contiene las credenciales actuales. **NO necesitas hacer nada si usas el servidor actual.**

Para cambiar a otro servidor:
```bash
nano .env
# Editar DB_HOST, DB_NAME, DB_USER, DB_PASS
```

### ✅ Paso 3: (OPCIONAL) Configurar base de datos de respaldo
Si quieres alta disponibilidad con failover automático:

```bash
cp .env.backup.example .env.backup
nano .env.backup
```

Configurar con servidor de respaldo:
```env
DB_HOST=servidor-respaldo.com
DB_NAME=keeper_backup
DB_USER=backup_user
DB_PASS=backup_password_seguro
```

### ✅ Paso 4: Verificar que .gitignore protege credenciales
```bash
cat .gitignore | grep .env
```

Debe mostrar:
```
.env
.env.backup
.env.production
.env.local
```

### ✅ Paso 5: Probar conexión
Visita cualquier página del admin panel. Si carga correctamente, la migración fue exitosa.

Para verificar qué BD está usando:
```php
<?php
require_once 'src/bootstrap.php';
$source = Keeper\Db::getActiveSource();
echo "Conectado a: {$source}"; // "primary" o "backup"
```

## 🔒 CAMBIOS IMPLEMENTADOS

### Antes (INSEGURO ❌):
```php
$pass = Config::get('DB_PASS', 'z3321483Z@!$2024**'); // Hardcoded
```

### Después (SEGURO ✅):
```php
$pass = Config::get('DB_PASS'); // Solo desde .env
if (!$pass) throw new PDOException("Credenciales incompletas");
```

## 🎯 BENEFICIOS

✅ **Seguridad:** CERO credenciales en código fuente  
✅ **Failover:** Cambio automático a BD de respaldo si la principal falla  
✅ **Auditoría:** Logs de todas las conexiones  
✅ **Flexibilidad:** Múltiples entornos (dev/staging/prod) sin modificar código  
✅ **Timeout:** 5 segundos por intento (no bloquea indefinidamente)  

## 🚨 IMPORTANTE - PRODUCCIÓN

1. **Permisos de archivos:**
   ```bash
   chmod 600 .env .env.backup
   ```

2. **Nunca commitear .env:**
   ```bash
   git status  # Verificar que .env NO aparece
   ```

3. **Rotar contraseñas:** Cambiar DB_PASS cada 90 días

4. **Monitorear logs:** Revisar `error_log` para detectar failovers

## 🆘 TROUBLESHOOTING

### "Credenciales incompletas en .env"
**Causa:** Archivo .env no tiene DB_PASS o está vacío  
**Solución:** Copiar de .env.example y completar

### "No se puede conectar a ninguna base de datos"
**Causa:** Tanto .env como .env.backup tienen credenciales incorrectas  
**Solución:** Verificar credenciales, probar conexión manual con MySQL client

### Sistema usa BD de respaldo constantemente
**Causa:** BD primaria está caída o inaccesible  
**Solución:** Revisar logs, verificar servidor primario, corregir credenciales

## 📚 DOCUMENTACIÓN COMPLETA
Ver: `DATABASE_FALLBACK.md`

---
**Fecha de migración:** 2026-02-05  
**Versión:** 2.0  
**Status:** ✅ COMPLETADO
