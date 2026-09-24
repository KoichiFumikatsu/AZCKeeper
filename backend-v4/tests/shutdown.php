<?php
declare(strict_types=1);
$expected = getenv('KEEPER_TEST_DATADIR');
if (!$expected || !str_contains(str_replace('\\', '/', $expected), '/backend-v4/.validation/mysql-')) { exit(1); }
try {
    $db = new PDO(getenv('KEEPER_DB_DSN'), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $actual = $db->query('SELECT @@datadir')->fetchColumn();
    $normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    if ($normalize($actual) !== $normalize($expected)) { exit(1); }
    $db->exec('SHUTDOWN');
} catch (PDOException) { exit(1); }
