<?php
/**
 * Runner de tests sin dependencias. El backend no tiene composer.
 * Uso: php AZCKeeper_Client/Web/tests/run.php
 */

$GLOBALS['__tests_passed'] = 0;
$GLOBALS['__tests_failed'] = 0;

function assertSame_($expected, $actual, string $label): void {
    if ($expected === $actual) {
        $GLOBALS['__tests_passed']++;
        echo "  PASS  {$label}\n";
        return;
    }
    $GLOBALS['__tests_failed']++;
    echo "  FAIL  {$label}\n";
    echo "        esperado: " . json_encode($expected, JSON_UNESCAPED_SLASHES) . "\n";
    echo "        obtenido: " . json_encode($actual, JSON_UNESCAPED_SLASHES) . "\n";
}

function suite(string $name): void {
    echo "\n{$name}\n";
}

require_once __DIR__ . '/../src/PolicyService.php';
require_once __DIR__ . '/PolicyServiceTest.php';

echo "\n----------------------------------------\n";
echo "PASS: {$GLOBALS['__tests_passed']}  FAIL: {$GLOBALS['__tests_failed']}\n";
exit($GLOBALS['__tests_failed'] > 0 ? 1 : 0);
