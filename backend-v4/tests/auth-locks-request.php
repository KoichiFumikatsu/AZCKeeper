<?php
declare(strict_types=1);

/** Request de prueba con solo la cabecera Authorization (lo unico que lee Auth::authenticate sin prueba de firma). */
function authLockRequest(string $token): Keeper\Request
{
    $r = (new ReflectionClass(Keeper\Request::class))->newInstanceWithoutConstructor();
    Closure::bind(function () use ($token): void { $this->headers = ['authorization' => 'Bearer ' . $token]; }, $r, Keeper\Request::class)();
    return $r;
}
