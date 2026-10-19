<?php
declare(strict_types=1);
use Keeper\Database;

// 2026-10-19: los choques entre escrituras (deadlock 1213) llegaban al agente como 503. Database::transaction reintenta
// solo esos, hasta 3 veces, y deja pasar cualquier otro error al primer intento.
function deadlockRetryTests(Database $db): void
{
    $deadlock = static function (): PDOException { $e = new PDOException('Deadlock found when trying to get lock', 40001); $e->errorInfo = ['40001', 1213, 'Deadlock']; return $e; };
    $db->run('CREATE TEMPORARY TABLE IF NOT EXISTS deadlock_probe (id INT PRIMARY KEY)');
    $db->run('DELETE FROM deadlock_probe');

    $calls = 0;
    $v = $db->transaction(function () use ($db, &$calls, $deadlock) {
        $calls++; $db->run('INSERT INTO deadlock_probe (id) VALUES (?)', [$calls]);
        if ($calls === 1) { throw $deadlock(); }
        return 'ok';
    });
    check($v === 'ok' && $calls === 2, 'un deadlock se reintenta y la segunda vez se confirma');
    check((int) $db->one('SELECT COUNT(*) n FROM deadlock_probe')['n'] === 1, 'el intento fallido se deshizo: solo queda la fila del intento bueno');

    $calls = 0;
    try { $db->transaction(function () use (&$calls, $deadlock) { $calls++; throw $deadlock(); }); check(false, 'tres deadlocks seguidos deben fallar'); }
    catch (PDOException $e) { check($calls === 3 && Database::deadlock($e), 'tras 3 deadlocks se rinde y devuelve el error'); }

    $calls = 0;
    try { $db->transaction(function () use (&$calls) { $calls++; throw new RuntimeException('otro error'); }); check(false, 'otro error debe propagarse'); }
    catch (RuntimeException) { check($calls === 1, 'cualquier otro error no se reintenta'); }
    $db->run('DROP TEMPORARY TABLE IF EXISTS deadlock_probe');
}
