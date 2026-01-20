<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
 
class ClientVersion
{
    public static function handle(): void
    {        
        // Ruta donde subes las actualizaciones
        $latestVersion = '3.1.0.0';
        $downloadUrl = 'https://github.com/KoichiFumikatsu/AZCKeeper/releases/download/v3.0.0/AZCKeeper-3.0.0-win-x64.zip';
        
        // Verificar si el archivo existe
        $updatePath = __DIR__ . '/../../../updates/AZCKeeper_v3.1.0.zip';
        $fileExists = file_exists($updatePath);
         Http::json(200, [
            'ok' => true,
            'latestVersion' => $latestVersion,
            'downloadUrl' => $downloadUrl,
            'fileSize' => $fileExists ? filesize($updatePath) : 0,
            'releaseNotes' => 'Mejoras de estabilidad, cola offline y sincronización de tiempo',
            'forceUpdate' => false,
            'minimumVersion' => '3.0.0.0', // Versiones menores deben actualizar obligatorio
            'releaseDate' => '2026-01-20'
        ]);
    }
}