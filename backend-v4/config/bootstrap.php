<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Keeper\\')) {
        $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
date_default_timezone_set('UTC');
Keeper\Config::load(dirname(__DIR__) . '/.env');
