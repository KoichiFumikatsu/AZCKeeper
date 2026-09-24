<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require dirname(__DIR__) . '/migrations/run.php';
try { migrate(connectDatabase()); }
catch (Throwable $e) { fwrite(STDERR, 'Migration failed: ' . get_class($e) . " (check database configuration and migration state)\n"); exit(1); }
