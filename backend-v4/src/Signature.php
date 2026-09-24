<?php
declare(strict_types=1);
namespace Keeper;

final class Signature
{
    public static function thumbprint(object $jwk): string
    {
        self::pem($jwk);
        return hash('sha256', Util::json(['crv' => 'P-256', 'kty' => 'EC', 'x' => $jwk->x, 'y' => $jwk->y]), true);
    }
    public static function pem(object $jwk): string
    {
        $x = Util::unb64($jwk->x); $y = Util::unb64($jwk->y);
        if ($jwk->kty !== 'EC' || $jwk->crv !== 'P-256' || strlen($x) !== 32 || strlen($y) !== 32) { throw new ApiError(401, 'invalid_signature'); }
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d03010703420004') . $x . $y;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        if (!openssl_pkey_get_public($pem)) { throw new ApiError(401, 'invalid_signature'); }
        return $pem;
    }
    public static function der(string $raw): string
    {
        if (strlen($raw) !== 64) { throw new ApiError(401, 'invalid_signature'); }
        $enc = static function (string $v): string {
            $v = ltrim($v, "\0"); if ($v === '') { $v = "\0"; }
            if (ord($v[0]) & 128) { $v = "\0" . $v; }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $v = $enc(substr($raw, 0, 32)) . $enc(substr($raw, 32));
        return "\x30" . chr(strlen($v)) . $v;
    }
    public function verify(Request $r, object $jwk): string
    {
        $input = $r->header('signature-input');
        if (strlen($input) > 4096 || !preg_match('/^sig1=(\((?:"[a-z@-]+" ?)+\))((?:;[a-z]+=(?:"[A-Za-z0-9_@.\/:=-]+"|[0-9]+))+)$/D', $input, $m)) { throw new ApiError(401, 'invalid_signature'); }
        preg_match_all('/"([a-z@-]+)"/', $m[1], $components);
        $components = $components[1];
        if (count(array_unique($components)) !== count($components)) { throw new ApiError(401, 'invalid_signature'); }
        preg_match_all('/;([a-z]+)=("[^"]+"|[0-9]+)/', $m[2], $params, PREG_SET_ORDER);
        $p = [];
        foreach ($params as $param) {
            if (isset($p[$param[1]]) || !in_array($param[1], ['created', 'expires', 'nonce', 'keyid', 'alg'], true)) { throw new ApiError(401, 'invalid_signature'); }
            $numeric = in_array($param[1], ['created', 'expires'], true);
            if ($numeric === str_starts_with($param[2], '"')) { throw new ApiError(401, 'invalid_signature'); }
            $p[$param[1]] = trim($param[2], '"');
        }
        foreach (['created', 'expires', 'nonce', 'keyid'] as $required) { if (!isset($p[$required])) { throw new ApiError(401, 'invalid_signature'); } }
        $created = (int) $p['created']; $expires = (int) $p['expires']; $now = time();
        if (($p['alg'] ?? 'ecdsa-p256-sha256') !== 'ecdsa-p256-sha256' || $expires <= $created || $expires - $created > 60 || abs($now - $created) > 60 || $expires < $now - 60 || strlen($p['nonce']) > 160 || strlen(Util::unb64($p['nonce'])) < 16 || !hash_equals(Util::b64(self::thumbprint($jwk)), $p['keyid'])) { throw new ApiError(401, 'invalid_signature'); }
        $required = ['@method', '@target-uri'];
        if ($r->header('authorization') !== '') { $required[] = 'authorization'; }
        if ($r->method === 'POST') {
            $required[] = 'content-digest'; $required[] = 'content-type';
            $expected = 'sha-256=:' . base64_encode(hash('sha256', $r->raw, true)) . ':';
            if (!hash_equals($expected, $r->header('content-digest'))) { throw new ApiError(401, 'invalid_signature'); }
        }
        if ($r->header('idempotency-key') !== '') { $required[] = 'idempotency-key'; }
        if (array_diff($required, $components)) { throw new ApiError(401, 'invalid_signature'); }
        $lines = [];
        foreach ($components as $component) {
            $value = match ($component) { '@method' => $r->method, '@target-uri' => $r->target, default => $r->header($component) };
            if ($value === '' || (str_starts_with($component, '@') && !in_array($component, ['@method', '@target-uri'], true))) { throw new ApiError(401, 'invalid_signature'); }
            $lines[] = '"' . $component . '": ' . $value;
        }
        $lines[] = '"@signature-params": ' . substr($input, 5);
        if (!preg_match('/^sig1=:([A-Za-z0-9+\/]+={0,2}):$/D', $r->header('signature'), $sig)) { throw new ApiError(401, 'invalid_signature'); }
        $raw = base64_decode($sig[1], true);
        if ($raw === false || openssl_verify(implode("\n", $lines), self::der($raw), self::pem($jwk), OPENSSL_ALGO_SHA256) !== 1) { throw new ApiError(401, 'invalid_signature'); }
        return $p['nonce'];
    }
}
