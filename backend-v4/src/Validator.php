<?php
declare(strict_types=1);
namespace Keeper;

final class Validator
{
    public readonly array $contract;
    public function __construct() { $this->contract = json_decode(file_get_contents(dirname(__DIR__) . '/config/contract.json'), true, 64, JSON_THROW_ON_ERROR); }
    public function named(string $name, mixed $value): void { $this->check($this->contract['schemas'][$name], $value); }
    public function check(array $s, mixed $v): void
    {
        if (isset($s['$ref'])) { $this->named(basename($s['$ref']), $v); return; }
        foreach (['oneOf', 'anyOf'] as $union) {
            if (isset($s[$union])) {
                $valid = 0;
                foreach ($s[$union] as $sub) { try { $this->check($sub, $v); $valid++; } catch (ApiError) {} }
                if ($valid === 0 || ($union === 'oneOf' && $valid !== 1)) { $this->fail(); }
            }
        }
        if (isset($s['not'])) {
            $matches = true;
            try { $this->check($s['not'], $v); } catch (ApiError) { $matches = false; }
            if ($matches) { $this->fail(); }
        }
        if (array_key_exists('const', $s) && $s['const'] !== $v) { $this->fail(); }
        if (isset($s['enum']) && !in_array($v, $s['enum'], true)) { $this->fail(); }
        $valid = match ($s['type'] ?? null) {
            'object' => $v instanceof \stdClass, 'array' => is_array($v), 'string' => is_string($v),
            'integer' => is_int($v), 'number' => is_int($v) || is_float($v), 'boolean' => is_bool($v), 'null' => $v === null, default => true,
        };
        if (!$valid) { $this->fail(); }
        if ($v instanceof \stdClass) {
            foreach ($s['required'] ?? [] as $key) { if (!property_exists($v, $key)) { $this->fail(); } }
            foreach (get_object_vars($v) as $key => $value) {
                if (isset($s['properties'][$key])) { $this->check($s['properties'][$key], $value); }
                elseif (($s['additionalProperties'] ?? true) === false) { $this->fail(); }
            }
        }
        if (is_array($v)) {
            if (count($v) < ($s['minItems'] ?? 0) || count($v) > ($s['maxItems'] ?? PHP_INT_MAX)) { $this->fail(); }
            foreach ($v as $value) { if (isset($s['items'])) { $this->check($s['items'], $value); } }
            if (($s['uniqueItems'] ?? false) && count(array_unique(array_map([Util::class, 'json'], $v))) !== count($v)) { $this->fail(); }
        }
        if (is_int($v) || is_float($v)) {
            if ($v < ($s['minimum'] ?? -INF) || $v > ($s['maximum'] ?? INF)) { $this->fail(); }
        }
        if (is_string($v)) {
            if (mb_strlen($v, 'UTF-8') > ($s['maxLength'] ?? PHP_INT_MAX) || mb_strlen($v, 'UTF-8') < ($s['minLength'] ?? 0)) { $this->fail(); }
            if (isset($s['pattern']) && !preg_match('~' . str_replace('~', '\\~', $s['pattern']) . '~uD', $v)) { $this->fail(); }
            switch ($s['format'] ?? '') {
                case 'email': if (filter_var($v, FILTER_VALIDATE_EMAIL) === false) { $this->fail(); } break;
                case 'uuid': if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $v)) { $this->fail(); } break;
                case 'date': case 'date-time':
                    $pattern = $s['format'] === 'date' ? '/^\d{4}-\d{2}-\d{2}$/D' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';
                    if (!preg_match($pattern, $v)) { $this->fail(); }
                    try { new \DateTimeImmutable($v); } catch (\Exception) { $this->fail(); }
                    $errors = \DateTimeImmutable::getLastErrors();
                    if ($errors && ($errors['warning_count'] || $errors['error_count'])) { $this->fail(); }
                    break;
                case 'uri': if (filter_var($v, FILTER_VALIDATE_URL) === false) { $this->fail(); } break;
                case 'uri-reference': if (!$this->uriReference($v)) { $this->fail(); } break;
            }
        }
    }
    private function uriReference(string $value): bool
    {
        $pattern = <<<'REGEX'
~\A (?: [a-z][a-z0-9+.-]* : (?&hier) | (?&relative) ) (?: \? (?&query) )? (?: \# (?&query) )? \z
(?(DEFINE)
    (?<char> [a-z0-9._\~-] | %[a-f0-9]{2} | [!$&'()*+,;=] )
    (?<pchar> (?&char) | [:@] )
    (?<authority> (?: (?: (?&char) | : )* @ )? (?: \[ (?: [a-f0-9:.]+ | v[a-f0-9]+\.[a-z0-9._\~!$&'()*+,;=:-]+ ) \] | (?&char)* ) (?: :[0-9]* )? )
    (?<absolute> / (?: (?&pchar)+ (?: / (?&pchar)* )* )? )
    (?<hier> // (?&authority) (?: / (?&pchar)* )* | (?&absolute) | (?&pchar)+ (?: / (?&pchar)* )* | )
    (?<relative> // (?&authority) (?: / (?&pchar)* )* | (?&absolute) | (?: (?&char) | @ )+ (?: / (?&pchar)* )* | )
    (?<query> (?: (?&pchar) | [/?] )* )
)
~ixD
REGEX;
        if (preg_match($pattern, $value) !== 1) { return false; }
        if (preg_match('~^(?:[a-z][a-z0-9+.-]*:)?//(?:[^/?#@]*@)?\[([^\]]+)\]~i', $value, $host) && !str_starts_with(strtolower($host[1]), 'v')) {
            return filter_var($host[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        return true;
    }
    private function fail(): never { throw new ApiError(422, 'validation_failed'); }
}
