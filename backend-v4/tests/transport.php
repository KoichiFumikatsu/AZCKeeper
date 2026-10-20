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
$GLOBALS['testHeaders']['Host'] = 'keeper.example.test';
$GLOBALS['testHeaders']['X-Real-IP'] = '8.8.8.8';
$GLOBALS['testHeaders']['X-Forwarded-For'] = '8.8.8.8, 1.1.1.1';
$_SERVER['REMOTE_ADDR'] = '9.9.9.9';
if (DeviceLocations::publicIp(new Request($validator)) !== '9.9.9.9') { throw new \RuntimeException('Untrusted location header'); }
$checks++;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (DeviceLocations::publicIp(new Request($validator)) !== '1.1.1.1') { throw new \RuntimeException('Wrong trusted location hop'); }
$checks++;
unset($GLOBALS['testHeaders']['X-Forwarded-For']);
if (DeviceLocations::publicIp(new Request($validator)) !== '8.8.8.8') { throw new \RuntimeException('Missing trusted real IP'); }
$checks++;
$GLOBALS['testHeaders']['X-Real-IP'] = '192.168.1.1';
if (DeviceLocations::publicIp(new Request($validator)) !== null) { throw new \RuntimeException('Private location IP'); }
$checks++;
echo "TRANSPORT PASS: $checks assertions\n";
