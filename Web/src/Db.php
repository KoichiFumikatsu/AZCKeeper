<?php
namespace Keeper;

use PDO;
use PDOException;
use Exception;

class Db {
  private static ?PDO $pdo = null;
  private static ?string $activeSource = null;
  private static ?PDO $legacyPdo = null;
  /** @var array<int,PDO> Cache de conexiones por firma_id */
  private static array $firmConnections = [];

  /**
   * Obtiene conexión PDO con sistema de fallback automático
   * 
   * Orden de prioridad:
   * 1. .env (base de datos principal)
   * 2. .env.backup (base de datos de respaldo)
   * 3. Exception si ambas fallan
   * 
   * @return PDO
   * @throws Exception Si no puede conectar a ninguna base de datos
   */
  public static function pdo(): PDO {
    if (self::$pdo) return self::$pdo;

    // Intentar conexión primaria
    try {
      self::$pdo = self::connectPrimary();
      self::$activeSource = 'primary';
      // No loguear SUCCESS en cada request — con 100+ usuarios genera miles de
      // líneas/min en error_log y mata el I/O del servidor.
      return self::$pdo;
    } catch (PDOException $primaryEx) {
      self::logConnection('primary', false, $primaryEx->getMessage());
      
      // Intentar conexión de respaldo
      try {
        self::$pdo = self::connectBackup();
        self::$activeSource = 'backup';
        // Solo loguear fallback, no success normal
        self::logConnection('backup', true, 'FALLBACK ACTIVO');
        error_log("[KEEPER WARNING] Usando BD de RESPALDO - Primaria no disponible");
        return self::$pdo;
      } catch (PDOException $backupEx) {
        self::logConnection('backup', false, $backupEx->getMessage());
        
        // Ambas conexiones fallaron
        throw new Exception(
          "KEEPER CRITICAL: No se puede conectar a ninguna base de datos.\n" .
          "Primaria: {$primaryEx->getMessage()}\n" .
          "Respaldo: {$backupEx->getMessage()}\n" .
          "Verifique archivos .env y .env.backup"
        );
      }
    }
  }

  /**
   * Conecta a la base de datos principal desde .env
   */
  private static function connectPrimary(): PDO {
    $host = Config::get('DB_HOST');
    $db   = Config::get('DB_NAME');
    $user = Config::get('DB_USER');
    $pass = Config::get('DB_PASS');

    if (!$host || !$db || !$user || !$pass) {
      throw new PDOException("Credenciales incompletas en .env");
    }

    return self::createConnection($host, $db, $user, $pass);
  }

  /**
   * Conecta a la base de datos de respaldo desde .env.backup
   */
  private static function connectBackup(): PDO {
    // Cargar configuración de respaldo
    $backupEnvPath = __DIR__ . '/../.env.backup';
    if (!file_exists($backupEnvPath)) {
      throw new PDOException("Archivo .env.backup no existe");
    }

    // Cargar variables de respaldo en contexto temporal
    $backupConfig = self::loadBackupEnv($backupEnvPath);
    
    if (!isset($backupConfig['DB_HOST'], $backupConfig['DB_NAME'], 
               $backupConfig['DB_USER'], $backupConfig['DB_PASS'])) {
      throw new PDOException("Credenciales incompletas en .env.backup");
    }

    return self::createConnection(
      $backupConfig['DB_HOST'],
      $backupConfig['DB_NAME'],
      $backupConfig['DB_USER'],
      $backupConfig['DB_PASS']
    );
  }

  /**
   * Crea conexión PDO con configuración estándar
   */
  private static function createConnection(string $host, string $db, string $user, string $pass): PDO {
    $charset = Config::get('DB_CHARSET', 'utf8mb4');
    $collation = Config::get('DB_COLLATION', 'utf8mb4_unicode_ci');
    
    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    
    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 5 // Timeout de 5 segundos
    ]);
    
    // Forzar charset/collation en la conexión
    $pdo->exec("SET NAMES {$charset} COLLATE {$collation}");
    
    return $pdo;
  }

  /**
   * Carga variables de .env.backup sin modificar Config global
   */
  private static function loadBackupEnv(string $path): array {
    $config = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      
      $pos = strpos($line, '=');
      if ($pos === false) continue;
      
      $key = trim(substr($line, 0, $pos));
      $value = trim(substr($line, $pos + 1));
      
      // Remover comillas
      if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || 
          (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
        $value = substr($value, 1, -1);
      }
      
      $config[$key] = $value;
    }
    
    return $config;
  }

  /**
   * Registra eventos de conexión para auditoría
   */
  private static function logConnection(string $source, bool $success, string $error = ''): void {
    $timestamp = date('Y-m-d H:i:s');
    $status = $success ? 'SUCCESS' : 'FAILED';
    $message = "[{$timestamp}] DB Connection [{$source}]: {$status}";
    
    if ($error) {
      $message .= " - Error: {$error}";
    }
    
    error_log($message);
  }

  /**
   * Obtiene el origen de la conexión activa (primary/backup)
   */
  public static function getActiveSource(): ?string {
    return self::$activeSource;
  }

  /**
   * Conexión a la BD legacy (employee, etc.)
   * Lee LEGACY_DB_* del .env. Si no están definidos, usa los mismos
   * valores de DB_* (modo single-DB para migración gradual).
   */
  public static function legacyPdo(): PDO {
    if (self::$legacyPdo) return self::$legacyPdo;

    $host = Config::get('LEGACY_DB_HOST', Config::get('DB_HOST'));
    $db   = Config::get('LEGACY_DB_NAME');
    $user = Config::get('LEGACY_DB_USER');
    $pass = Config::get('LEGACY_DB_PASS');

    // Fallback: si no hay LEGACY_DB_* configurados, usar la conexión principal
    // Esto permite migración gradual sin romper nada
    if (!$db || !$user || !$pass) {
      self::$legacyPdo = self::pdo();
      return self::$legacyPdo;
    }

    self::$legacyPdo = self::createConnection($host, $db, $user, $pass);
    return self::$legacyPdo;
  }

  /**
   * Conexión a la fuente de datos de una firma específica.
   * Busca en keeper_data_sources; si no hay registro, usa legacyPdo().
   */
  public static function sourceFor(int $firmaId): PDO {
    if (isset(self::$firmConnections[$firmaId])) {
      return self::$firmConnections[$firmaId];
    }

    $keeperPdo = self::pdo();
    $st = $keeperPdo->prepare("
      SELECT db_host, db_port, db_name, db_user, db_pass, source_type
      FROM keeper_data_sources
      WHERE firma_id = :fid AND is_active = 1
      LIMIT 1
    ");
    $st->execute(['fid' => $firmaId]);
    $ds = $st->fetch(PDO::FETCH_ASSOC);

    if (!$ds || $ds['source_type'] !== 'mysql') {
      // Sin fuente custom o tipo no-mysql → fallback a legacy global
      self::$firmConnections[$firmaId] = self::legacyPdo();
      return self::$firmConnections[$firmaId];
    }

    $dbPass = self::decryptCredential($ds['db_pass']);
    $host = $ds['db_host'] ?: Config::get('LEGACY_DB_HOST', Config::get('DB_HOST'));
    $port = (int)($ds['db_port'] ?: 3306);

    $conn = self::createConnection($host, $ds['db_name'], $ds['db_user'], $dbPass);
    self::$firmConnections[$firmaId] = $conn;
    return $conn;
  }

  /**
   * Cifra una credencial con AES-256-CBC usando APP_KEY del .env.
   * @return string Base64 del IV + ciphertext
   */
  public static function encryptCredential(string $plaintext): string {
    $key = self::getEncryptionKey();
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($encrypted === false) {
      throw new Exception('Encryption failed');
    }
    return base64_encode($iv . $encrypted);
  }

  /**
   * Descifra una credencial cifrada con encryptCredential().
   */
  public static function decryptCredential(?string $ciphertext): string {
    if ($ciphertext === null || $ciphertext === '') return '';
    $key = self::getEncryptionKey();
    $raw = base64_decode($ciphertext, true);
    if ($raw === false || strlen($raw) < 17) {
      throw new Exception('Invalid encrypted credential');
    }
    $iv = substr($raw, 0, 16);
    $encrypted = substr($raw, 16);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
    if ($decrypted === false) {
      throw new Exception('Decryption failed — check APP_KEY');
    }
    return $decrypted;
  }

  private static function getEncryptionKey(): string {
    $hex = Config::get('APP_KEY', '');
    if ($hex === '') {
      throw new Exception('APP_KEY no está configurado en .env. Genere uno con: php -r "echo bin2hex(random_bytes(32));"');
    }
    return hex2bin($hex);
  }

  /**
   * Resetea la conexión (útil para testing)
   */
  public static function reset(): void {
    self::$pdo = null;
    self::$activeSource = null;
    self::$legacyPdo = null;
    self::$firmConnections = [];
  }
}
