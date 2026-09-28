<?php
// Sin base de datos: php tests/release-offer.php
// Cubre la resolucion de arquitectura para ofrecer releases (Resources::releaseArchitecture).
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Keeper\\')) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php'; }
});

use Keeper\Resources;

$cases = [
    'specs NULL (equipo enrolado por v4)' => [null, 'x64'],
    'specs vacio' => ['', 'x64'],
    'specs sin architecture' => ['{"os":"Windows 11 Pro"}', 'x64'],
    'x64 reportado' => ['{"architecture":"x64"}', 'x64'],
    'arm64 reportado' => ['{"architecture":"arm64"}', 'arm64'],
    'arquitectura desconocida' => ['{"architecture":"x86"}', null],
    'JSON no objeto' => ['"x64"', 'x64'],
];
$failed = 0;
foreach ($cases as $name => [$specs, $expected]) {
    $actual = Resources::releaseArchitecture($specs);
    $ok = $actual === $expected;
    $failed += $ok ? 0 : 1;
    printf("%s %s: %s\n", $ok ? 'OK  ' : 'FAIL', $name, var_export($actual, true));
}
exit($failed === 0 ? 0 : 1);
