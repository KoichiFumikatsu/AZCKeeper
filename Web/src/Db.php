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
   * Obtiene conexión PDO usando exclusivamente Web/.env.
   *
   * No usa .env.backup ni otras fuentes automáticas.
   * 
   * @return PDO
   * @throws Exception Si no puede conectar a la base configurada en .env
   */
  public static function pdo(): PDO {
    if (self::$pdo) return self::$pdo;

    try {
      self::$pdo = self::connectPrimary();
      self::$activeSource = 'primary';
      return self::$pdo;
    } catch (PDOException $ex) {
      self::logConnection('primary', false, $ex->getMessage());
      throw new Exception(
        "KEEPER CRITICAL: No se puede conectar a la base configurada en Web/.env.\n" .
        "Error: {$ex->getMessage()}\n" .
        "Verifique DB_HOST, DB_NAME, DB_USER y DB_PASS en .env"
      );
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

    if (!$host || !$db || !$user || $pass === null) {
      throw new PDOException("Credenciales incompletas en .env");
    }

    if ($db !== 'azckeeper_local') {
      throw new PDOException("DB_NAME debe ser exactamente 'azckeeper_local' en .env. Actual={$db}");
    }

    return self::createConnection($host, $db, $user, $pass);
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
    if (!$db || !$user || $pass === null) {
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
