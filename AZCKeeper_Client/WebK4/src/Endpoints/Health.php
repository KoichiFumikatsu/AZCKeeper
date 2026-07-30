<?php
namespace Keeper\Endpoints;

use Keeper\Http;

class Health
{
    public static function handle(): void
    {
        Http::json(200, [
            'ok'            => true,
            'service'       => 'keeper4-api',
            'serverTimeUtc' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }
}
