<?php
namespace Keeper;

class PolicyService {
  public static function deepMerge(array $base, array $override): array {
    foreach ($override as $k => $v) {
      if (is_array($v) && isset($base[$k]) && is_array($base[$k])) {
        $base[$k] = self::deepMerge($base[$k], $v);
      } else {
        $base[$k] = $v;
      }
    }
    return $base;
  }
}
