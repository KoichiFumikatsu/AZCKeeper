<?php
declare(strict_types=1);
namespace Keeper;

final class Util
{
    public static function uuid(): string
    {
        $v = random_bytes(16);
        $v[6] = chr((ord($v[6]) & 15) | 64);
        $v[8] = chr((ord($v[8]) & 63) | 128);
        return self::id($v);
    }
    public static function bin(string $id): string { return hex2bin(str_replace('-', '', $id)); }
    public static function id(string $b): string
    {
        $h = bin2hex($b);
        return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20);
    }
    public static function b64(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
    public static function unb64(string $v): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $v)) { throw new ApiError(401, 'invalid_signature'); }
        $b = base64_decode(strtr($v, '-_', '+/'), true);
        if ($b === false || self::b64($b) !== $v) { throw new ApiError(401, 'invalid_signature'); }
        return $b;
    }
    public static function json(mixed $v): string { return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    public static function canonical(mixed $v): string
    {
        $sort = static function (mixed $x) use (&$sort): mixed {
            if ($x instanceof \stdClass) { $a = get_object_vars($x); ksort($a, SORT_STRING); return (object) array_map($sort, $a); }
            if (is_array($x)) { return array_map($sort, $x); }
            return $x;
        };
        return self::json($sort($v));
    }
    public static function hash(mixed $v): string { return hash('sha256', self::canonical($v), true); }
    public static function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }
    public static function sqlTime(string $s): string { return (new \DateTimeImmutable($s))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    public static function time(string $s): string { return (new \DateTimeImmutable($s, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'); }
}
