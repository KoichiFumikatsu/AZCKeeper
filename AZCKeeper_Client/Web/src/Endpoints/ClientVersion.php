<?php
namespace Keeper\Endpoints;
 
use Keeper\Http;
use Keeper\Repos\ReleaseRepo;
 
class ClientVersion
{
    public static function handle(): void
    {
        // Check if client wants beta versions (from query param or JSON body)
        $allowBeta = false;
        
        if (isset($_GET['allowBeta'])) {
            $allowBeta = filter_var($_GET['allowBeta'], FILTER_VALIDATE_BOOLEAN);
        } else {
            $body = file_get_contents('php://input');
            if ($body) {
                $data = json_decode($body, true);
                $allowBeta = $data['allowBetaVersions'] ?? false;
            }
        }
        
        // Get latest release from database
        $release = ReleaseRepo::getLatestRelease($allowBeta);
        
        if (!$release) {
            Http::json(500, [
                'ok' => false,
                'error' => 'No releases available'
            ]);
            return;
        }
        
        Http::json(200, [
            'ok' => true,
            'latestVersion' => $release['version'],
            'downloadUrl' => $release['download_url'],
            'fileSize' => (int)$release['file_size'], 
            'releaseNotes' => $release['release_notes'] ?? '',
            'forceUpdate' => (bool)$release['force_update'],
            'minimumVersion' => $release['minimum_version'] ?? '3.0.0.0',
            'releaseDate' => $release['release_date'] ?? date('Y-m-d')
        ]);
    }
}

