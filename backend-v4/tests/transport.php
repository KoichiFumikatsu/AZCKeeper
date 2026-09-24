<?php
declare(strict_types=1);
namespace Keeper;

require dirname(__DIR__) . '/config/bootstrap.php';

function getallheaders(): array { return $GLOBALS['testHeaders']; }

if (PHP_SAPI !== 'cli') { exit(1); }
$_GET = [];
$_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/v1/client/policy', 'REMOTE_ADDR' => '192.0.2.10'];
$GLOBALS['testHeaders'] = ['Host' => 'keeper.example.test'];
putenv('KEEPER_ORIGIN=https://keeper.example.test');
putenv('KEEPER_TRUSTED_PROXY_IPS=127.0.0.1');
$validator = new Validator();
$checks = 0;
$expect = static function (int $status) use ($validator, &$checks): void {
    try { new Request($validator); $actual = 200; }
    catch (ApiError $e) { $actual = $e->status; }
    if ($actual !== $status) { throw new \RuntimeException('Transport expected ' . $status . ', received ' . $actual); }
    $checks++;
};
$expect(403);
$GLOBALS['testHeaders']['X-Forwarded-Proto'] = 'https';
$expect(403);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$expect(200);
$GLOBALS['testHeaders']['X-Forwarded-Host'] = 'attacker.example.test';
$request = new Request($validator);
if ($request->target !== 'https://keeper.example.test/v1/client/policy') { throw new \RuntimeException('Forwarded authority was trusted'); }
$checks++;
$_SERVER['REMOTE_ADDR'] = '192.0.2.10'; $_SERVER['HTTPS'] = 'on';
$expect(200);
$GLOBALS['testHeaders']['Host'] = 'attacker.example.test';
$expect(400);
echo "TRANSPORT PASS: $checks assertions\n";
