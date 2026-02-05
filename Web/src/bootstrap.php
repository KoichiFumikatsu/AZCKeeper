<?php
namespace Keeper;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/InputValidator.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/PolicyService.php';
require_once __DIR__ . '/AuthService.php';


// Repos
require_once __DIR__ . '/Repos/PolicyRepo.php';
require_once __DIR__ . '/Repos/DeviceRepo.php';
require_once __DIR__ . '/Repos/SessionRepo.php';
require_once __DIR__ . '/Repos/HandshakeRepo.php';
require_once __DIR__ . '/Repos/AuditRepo.php';
require_once __DIR__ . '/Repos/UserRepo.php';
require_once __DIR__ . '/Repos/LegacyAuthRepo.php';
require_once __DIR__ . '/Repos/ReleaseRepo.php';

// Endpoints
require_once __DIR__ . '/Endpoints/Health.php';
require_once __DIR__ . '/Endpoints/ClientHandshake.php';
require_once __DIR__ . '/Endpoints/ClientLogin.php';
require_once __DIR__ . '/Endpoints/ClientVersion.php';
require_once __DIR__ . '/Endpoints/ActivityDay.php';
require_once __DIR__ . '/Endpoints/WindowEpisode.php';
require_once __DIR__ . '/Endpoints/EventIngest.php';
require_once __DIR__ . '/Endpoints/ForceHandshake.php';
require_once __DIR__ . '/Endpoints/DeviceLock.php';

// Load .env
Config::loadEnv(__DIR__ . '/../.env');
