<?php
namespace Keeper;

class PolicyService {
  /**
   * Merge de politicas por scope (global -> user -> device).
   *
   * Los mapas asociativos se mergean en profundidad. Las LISTAS se reemplazan
   * completas: antes se combinaban por indice numerico, asi que una politica de
   * usuario con lista mas corta heredaba en silencio la cola de la global.
   */
  public static function deepMerge(array $base, array $override): array {
    foreach ($override as $k => $v) {
      if (is_array($v) && isset($base[$k]) && is_array($base[$k])
          && !self::isList($v) && !self::isList($base[$k])) {
        $base[$k] = self::deepMerge($base[$k], $v);
      } else {
        $base[$k] = $v;
      }
    }
    return $base;
  }

  /** array_is_list() es PHP 8.1+; produccion puede ser 8.0. */
  private static function isList(array $a): bool {
    return $a === [] || $a === array_values($a);
  }
}
