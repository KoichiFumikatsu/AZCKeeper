<?php
namespace Keeper;

use PDO;

class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (self::$pdo) return self::$pdo;

    $host = Config::get('DB_HOST', 'mysql.server1872.mylogin.co');
    $db   = Config::get('DB_NAME', 'pipezafra_verter');
    $user = Config::get('DB_USER', 'pipezafra_verter');
    $pass = Config::get('DB_PASS', 'z3321483Z@!$2024**');

    $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
    self::$pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false
    ]);
    
    // Forzar utf8mb4 en la conexión para evitar errores de collation
    self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    
    return self::$pdo;
  }
}
