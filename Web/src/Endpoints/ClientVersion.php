<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
 
class ClientVersion
{
    public static function handle(): void
    {        
        $latestVersion = '3.0.0.1';
        $downloadUrl = 'https://github.com/KoichiFumikatsu/AZCKeeper/releases/download/v3.0.0.1/AZCKeeper_v3.0.0.1.zip';
        
    Http::json(200, [
            'ok' => true,
            'latestVersion' => $latestVersion,
            'downloadUrl' => $downloadUrl,
            'fileSize' => 0, 
            'releaseNotes' => 'Ensayo de la nueva versión 3.0.0.1 con mejoras en estabilidad y rendimiento.',
            'forceUpdate' => false,
            'minimumVersion' => '3.0.0.0',
            'releaseDate' => '2026-01-21'
        ]);
    }
}

