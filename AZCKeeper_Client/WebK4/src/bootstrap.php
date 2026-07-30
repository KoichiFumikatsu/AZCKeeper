<?php
namespace Keeper;

// Config primero: carga el .env del docroot padre.
require_once __DIR__ . '/config.php';
Config::loadEnv(dirname(__DIR__) . '/.env');

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/InputValidator.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/PolicyService.php';
require_once __DIR__ . '/AuthService.php';

// Servicios
require_once __DIR__ . '/Services/TierResolver.php';

// Repos
require_once __DIR__ . '/Repos/SessionRepo.php';
require_once __DIR__ . '/Repos/DeviceRepo.php';
require_once __DIR__ . '/Repos/PolicyRepo.php';
require_once __DIR__ . '/Repos/EpisodeRepo.php';
require_once __DIR__ . '/Repos/ProcessViewRepo.php';

// Endpoints
require_once __DIR__ . '/Endpoints/Health.php';
require_once __DIR__ . '/Endpoints/ClientHandshake.php';
require_once __DIR__ . '/Endpoints/EpisodeBatch.php';
require_once __DIR__ . '/Endpoints/ProcessView.php';
