<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$path = dirname(__DIR__) . '/.env';
if (!is_file($path)) { fwrite(STDERR, "Copy .env.example to .env first\n"); exit(1); }
$env = file_get_contents($path);
if (!preg_match('/^KEEPER_RESPONSE_KEY=[ \t]*\r?$/m', $env)) { fwrite(STDERR, "Expected one empty KEEPER_RESPONSE_KEY; existing keys are never overwritten\n"); exit(1); }
$value = base64_encode(random_bytes(32));
$updated = preg_replace_callback('/^KEEPER_RESPONSE_KEY=[ \t]*\r?$/m', static fn () => 'KEEPER_RESPONSE_KEY=' . $value, $env, 1);
if (file_put_contents($path, $updated, LOCK_EX) === false) { exit(1); }
echo "Response key written to .env without displaying it\n";
