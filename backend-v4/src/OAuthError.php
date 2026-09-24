<?php
declare(strict_types=1);
namespace Keeper;

final class OAuthError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode) { parent::__construct($errorCode); }
}
