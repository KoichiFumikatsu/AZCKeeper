<?php
declare(strict_types=1);
namespace Keeper;

// Falla de la fuente (red, HTTP, formato): la vuelta queda 'failed' y no aplica nada.
final class SyncFailure extends \RuntimeException {}
