<?php
use Keeper\Endpoints\SecurityReport;
use Keeper\Repos\SecurityStateRepo;

suite('SecurityReport::sanitizeControls (via reflection, es private)');

function sanitizeControls_(array $controls): array {
    $m = new ReflectionMethod(SecurityReport::class, 'sanitizeControls');
    $m->setAccessible(true);
    return $m->invoke(null, $controls);
}

// Un valor de string muy por encima de lo que produce el cliente real (19
// controles con valores cortos) se trunca, no se rechaza el batch entero.
$longValue = str_repeat('A', 5000);
$out = sanitizeControls_(['BitLockerEnabled' => ['present' => true, 'value' => $longValue]]);
assertSame_(512, strlen($out['BitLockerEnabled']['value']), 'valor string se trunca a MAX_VALUE_LEN (512)');

// Clave anomalamente larga tambien se trunca.
$longKey = str_repeat('k', 500);
$out = sanitizeControls_([$longKey => ['present' => false, 'value' => null]]);
$outKeys = array_keys($out);
assertSame_(128, strlen($outKeys[0]), 'clave se trunca a MAX_KEY_LEN (128)');

// Valores dentro del contrato (int|string corto|null) pasan intactos.
$out = sanitizeControls_([
    'DefenderEnabled'      => ['present' => true, 'value' => 1],
    'FirewallProfile'      => ['present' => true, 'value' => 'Domain'],
    'AutoUpdateLastResult' => ['present' => false, 'value' => null],
]);
assertSame_(1, $out['DefenderEnabled']['value'], 'entero corto pasa intacto');
assertSame_('Domain', $out['FirewallProfile']['value'], 'string corto pasa intacto');
assertSame_(null, $out['AutoUpdateLastResult']['value'], 'null pasa intacto');
assertSame_(true, $out['DefenderEnabled']['present'], 'present=true se preserva');
assertSame_(false, $out['AutoUpdateLastResult']['present'], 'present=false se preserva');

// Un valor fuera del contrato int|string|null (array anidado, el vector de
// ataque real: usar las 100 claves permitidas para meter estructuras enormes)
// se descarta a null en vez de persistirse tal cual.
$out = sanitizeControls_(['Weird' => ['present' => true, 'value' => ['nested' => str_repeat('x', 100000)]]]);
assertSame_(null, $out['Weird']['value'], 'array anidado en "value" se descarta a null');

// Un float tampoco esta en el contrato int|string|null.
$out = sanitizeControls_(['Weird2' => ['present' => true, 'value' => 3.14]]);
assertSame_(null, $out['Weird2']['value'], 'float en "value" se descarta a null');

// Entrada que no es ni siquiera un array (p.ej. escalar directo) recibe el
// default seguro en vez de propagar la forma inesperada.
$out = sanitizeControls_(['NotAnObject' => 'oops']);
assertSame_(['present' => false, 'value' => null], $out['NotAnObject'], 'entrada no-array recibe default seguro');

suite('SecurityStateRepo::upsert - json_encode invalido no debe tocar la BD');

/**
 * Doble minimo de PDO: si prepare() llega a invocarse, es que el
 * json_encode() fallido no se detecto ANTES de tocar la base de datos
 * (justo el bug original: hashear/persistir con $json = false).
 */
class UnreachablePdoForSecurityReportTest extends \PDO {
    public function __construct() {}
    public function prepare($query, $options = []): \PDOStatement|false {
        throw new \RuntimeException('prepare() no deberia llamarse: json_encode fallo antes de tocar la BD');
    }
}

// Secuencia de bytes invalida como UTF-8 (continuation byte sin lead byte).
$invalidUtf8 = "\xB1\x31";

$threw = null;
try {
    SecurityStateRepo::upsert(
        new UnreachablePdoForSecurityReportTest(),
        1,
        1,
        false,
        ['SomeControl' => ['present' => true, 'value' => $invalidUtf8]]
    );
} catch (\Throwable $e) {
    $threw = $e;
}

assertSame_(
    \JsonException::class,
    $threw !== null ? get_class($threw) : null,
    'UTF-8 invalido en un valor lanza JsonException antes de llamar a PDO::prepare()'
);
