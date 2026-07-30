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
require_once __DIR__ . '/Services/Metrics.php';
require_once __DIR__ . '/Services/DualJobDetector.php';

// Repos
require_once __DIR__ . '/Repos/SessionRepo.php';
require_once __DIR__ . '/Repos/DeviceRepo.php';
require_once __DIR__ . '/Repos/UserRepo.php';
require_once __DIR__ . '/Repos/AuditRepo.php';
require_once __DIR__ . '/Repos/PolicyRepo.php';
require_once __DIR__ . '/Repos/EpisodeRepo.php';
require_once __DIR__ . '/Repos/ProcessViewRepo.php';
require_once __DIR__ . '/Repos/DaySummaryRepo.php';
require_once __DIR__ . '/Repos/ModuleStateRepo.php';
require_once __DIR__ . '/Repos/CommandRepo.php';
require_once __DIR__ . '/Repos/SecurityStateRepo.php';
require_once __DIR__ . '/Repos/ScreenshotRepo.php';
require_once __DIR__ . '/Repos/LocationRepo.php';
require_once __DIR__ . '/Repos/FocusRepo.php';
require_once __DIR__ . '/Repos/DualJobRepo.php';

// Endpoints
require_once __DIR__ . '/Endpoints/Health.php';
require_once __DIR__ . '/Endpoints/ClientLogin.php';
require_once __DIR__ . '/Endpoints/ClientHandshake.php';
require_once __DIR__ . '/Endpoints/EpisodeBatch.php';
require_once __DIR__ . '/Endpoints/ActivityDay.php';
require_once __DIR__ . '/Endpoints/ModuleStateReport.php';
require_once __DIR__ . '/Endpoints/ClientCommands.php';
require_once __DIR__ . '/Endpoints/SecurityReport.php';
require_once __DIR__ . '/Endpoints/ScreenshotMeta.php';
require_once __DIR__ . '/Endpoints/LocationReport.php';
require_once __DIR__ . '/Endpoints/ProcessView.php';
require_once __DIR__ . '/Endpoints/AdminCommand.php';
require_once __DIR__ . '/Endpoints/AdminEnrollment.php';
require_once __DIR__ . '/Endpoints/ProductivityCron.php';
