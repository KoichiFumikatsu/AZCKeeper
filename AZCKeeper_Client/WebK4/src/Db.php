<?php
namespace Keeper;

use PDO;
use PDOException;

/**
 * Conexion a la base de Keeper 4. Version reducida frente a la de Keeper 3:
 * sin fallback a .env.backup, sin multi-tenant sourceFor() ni cripto de
 * credenciales de terceros. Eso vuelve cuando se cablee el import multi-tenant
 * (keeper_source), con su propia pieza de custodia de credenciales.
 */
class Db {
  private static ?PDO $pdo = null;

  public static function pdo(): PDO {
    if (self::$pdo) return self::$pdo;

    $host    = Config::get('DB_HOST', '127.0.0.1');
    $name    = Config::get('DB_NAME', '');
    $user    = Config::get('DB_USER', '');
    $pass    = Config::get('DB_PASS', '');
    $charset = Config::get('DB_CHARSET', 'utf8mb4');

    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    self::$pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return self::$pdo;
  }
}
