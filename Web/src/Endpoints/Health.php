<?php
namespace Keeper\Endpoints;

use Keeper\Http;

class Health {
  public static function handle(): void {
    Http::json(200, [
      'ok' => true,
      'service' => 'keeper-api',
      'serverTimeUtc' => Http::nowUtcIso()
    ]);
  }
}
