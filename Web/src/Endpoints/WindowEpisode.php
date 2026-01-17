<?php
namespace Keeper\Endpoints;

use Keeper\Http;

class WindowEpisode {
  public static function handle(): void {
    Http::json(501, ['ok' => false, 'error' => 'Not implemented yet']);
  }
}
