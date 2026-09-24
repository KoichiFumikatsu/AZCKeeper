<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/migrations/run.php';
if (getenv('KEEPER_TEST_ALLOW_FIXTURES') !== '1') { exit(1); }
if (getenv('KEEPER_ESCROW_KEY_FILE')) { file_put_contents(getenv('KEEPER_ESCROW_KEY_FILE'),sodium_crypto_box_keypair()); }
$dsn = getenv('KEEPER_DB_DSN');
if (!str_contains($dsn, 'dbname=keeper_v4_agent_test;')) { exit(1); }
$dsn = str_replace('dbname=keeper_v4_agent_test;', '', $dsn);
for ($attempt = 0; $attempt < 80; $attempt++) {
    try {
        $db = new PDO($dsn, getenv('KEEPER_DB_USER'), '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $collation=migrationCollation($db);
        $db->exec('CREATE DATABASE keeper_v4_agent_test CHARACTER SET utf8mb4 COLLATE '.$collation);
        echo 'Isolated MySQL ready: ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
        exit(0);
    } catch (PDOException) { usleep(250000); }
}
fwrite(STDERR, "MySQL did not become ready\n");
exit(1);
