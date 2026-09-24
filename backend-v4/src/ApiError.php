<?php
declare(strict_types=1);
namespace Keeper;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, public readonly array $headers = [], public readonly ?string $detail = null)
    {
        parent::__construct($errorCode);
    }
}
