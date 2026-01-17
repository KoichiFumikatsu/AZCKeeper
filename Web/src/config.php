<?php
namespace Keeper;

class Config {
  private static array $env = [];

  public static function loadEnv(string $envPath): void {
    if (!file_exists($envPath)) return;
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) continue;
      $pos = strpos($line, '=');
      if ($pos === false) continue;
      $k = trim(substr($line, 0, $pos));
      $v = trim(substr($line, $pos + 1));
      if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
        $v = substr($v, 1, -1);
      }
      self::$env[$k] = $v;
    }
  }

  public static function get(string $key, $default=null) {
    if (array_key_exists($key, self::$env)) return self::$env[$key];
    $g = getenv($key);
    return $g !== false ? $g : $default;
  }
}
