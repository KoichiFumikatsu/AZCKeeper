<?php
declare(strict_types=1);
namespace Keeper;

final class Database
{
    public readonly \PDO $pdo;
    public function __construct()
    {
        $file = Config::get('DB_PASSWORD_FILE');
        $password = $file !== '' ? rtrim(file_get_contents($file), "\r\n") : Config::get('DB_PASSWORD');
        $dsn = Config::get('DB_DSN');
        if (!str_starts_with($dsn, 'mysql:') || !str_contains($dsn, 'dbname=')) { throw new \RuntimeException('Configure MySQL DSN'); }
        $this->pdo = new \PDO($dsn, Config::get('DB_USER'), $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, \PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
        $this->run("SET time_zone = '+00:00'");
        $this->run("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY'");
        $this->run('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    public function run(string $sql, array $args = []): \PDOStatement
    {
        $q = $this->pdo->prepare($sql);
        foreach (array_values($args) as $i => $value) {
            $q->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
        $q->execute();
        return $q;
    }
    public function one(string $sql, array $args = []): ?array { return $this->run($sql, $args)->fetch() ?: null; }
    /**
     * Un choque entre escrituras (deadlock, SQLSTATE 40001 / error 1213) deshace la transaccion entera, asi que repetirla
     * es seguro: hasta 3 intentos con una espera corta y aleatoria. Antes, cada choque llegaba al agente como 503
     * (2026-10-19: el calculo de productividad y los envios de los equipos chocaban al marcar dias a recalcular).
     */
    public function transaction(callable $fn): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $this->pdo->beginTransaction();
            try { $v = $fn(); $this->pdo->commit(); return $v; }
            catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
                if ($attempt >= 3 || !self::deadlock($e)) { throw $e; }
                usleep(random_int(20000, 120000) * $attempt);
            }
        }
    }

    public static function deadlock(\Throwable $e): bool
    {
        return $e instanceof \PDOException && ((int) ($e->errorInfo[1] ?? 0) === 1213 || $e->getCode() === '40001');
    }
}
