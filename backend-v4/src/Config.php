<?php
declare(strict_types=1);
namespace Keeper;

final class Config
{
    public static function load(string $file): void
    {
        if (!is_file($file)) { return; }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) { continue; }
            if (!preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', $line, $m)) {
                throw new \RuntimeException('Invalid environment configuration');
            }
            $value = trim($m[2]);
            if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($m[1]) === false) { putenv($m[1] . '=' . $value); }
        }
    }

    public static function get(string $name, string $default = ''): string
    {
        $v = getenv('KEEPER_' . $name);
        return $v === false || $v === '' ? $default : $v;
    }

    public static function number(string $name, int $default): int
    {
        $v = self::get($name, (string) $default);
        if (!ctype_digit($v) || (int) $v < 1) { throw new \RuntimeException('Invalid numeric configuration'); }
        return (int) $v;
    }

    public static function key(): string
    {
        static $decoded = null;
        if ($decoded !== null) { return $decoded; }
        $key = base64_decode(self::get('RESPONSE_KEY'), true);
        if ($key === false || strlen($key) !== 32) { throw new \RuntimeException('Configure KEEPER_RESPONSE_KEY'); }
        return $decoded = $key;
    }
}
