<?php
declare(strict_types=1);
namespace Keeper;

final class ApiError extends \RuntimeException
{
    /** $extra: campos adicionales del Problem declarados en el contrato (p. ej. relocation en 409 device_relocated). */
    /** $errors: Problem.errors (pointer, code, message), p. ej. que persona y que rol causaron un 403 de delegacion. */
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $headers = [], public readonly ?string $detail = null, public readonly array $errors = [], public readonly array $extra = [])
    {
        parent::__construct($errorCode);
    }
}
