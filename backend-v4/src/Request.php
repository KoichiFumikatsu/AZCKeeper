<?php
declare(strict_types=1);
namespace Keeper;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly string $target;
    public readonly string $raw;
    public readonly mixed $body;
    public readonly string $requestId;
    public readonly array $headers;
    public readonly array $operation;
    public readonly ?string $resourceId;
    public readonly string $route;

    public function __construct(Validator $validator)
    {
        $this->requestId = Util::uuid();
        $this->method = $_SERVER['REQUEST_METHOD'];
        $uri = $_SERVER['REQUEST_URI'];
        if (!str_starts_with($uri, '/') || str_starts_with($uri, '//') || preg_match('/[\x00-\x20\x7f#]/', $uri)) { throw new ApiError(400, 'invalid_header'); }
        $this->path = explode('?', $uri, 2)[0];
        $headers = [];
        foreach (getallheaders() as $k => $v) { $headers[strtolower($k)] = trim($v); }
        $this->headers = $headers;
        if (isset($headers['x-tenant-id']) && (str_starts_with($this->path, '/v1/client/') || str_starts_with($this->path, '/ext/') || $this->path === '/v1/oauth/token')) { throw new ApiError(400, 'invalid_header'); }
        $origin = rtrim(Config::get('ORIGIN'), '/');
        $parts = parse_url($origin);
        if (!$parts || isset($parts['path']) || isset($parts['query']) || isset($parts['user']) || isset($parts['fragment'])) { throw new \RuntimeException('Configure canonical origin'); }
        if (($parts['scheme'] ?? '') !== 'https' && !(Config::get('ALLOW_HTTP_LOCAL') === '1' && ($parts['scheme'] ?? '') === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost', '::1'], true))) { throw new \RuntimeException('HTTPS origin required'); }
        if ($parts['scheme'] === 'https') {
            $tls = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off';
            $proxies = array_filter(array_map('trim', explode(',', Config::get('TRUSTED_PROXY_IPS'))));
            $trustedTls = in_array($_SERVER['REMOTE_ADDR'] ?? '', $proxies, true) && ($headers['x-forwarded-proto'] ?? '') === 'https';
            if (!$tls && !$trustedTls) { throw new ApiError(403, 'permission_denied'); }
        }
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (strcasecmp($headers['host'] ?? '', $authority) !== 0) { throw new ApiError(400, 'invalid_header'); }
        // Use configured external origin; forwarded headers never select the signature authority.
        $this->target = $origin . $uri;
        $external = str_starts_with($this->path, '/ext/v1/');
        $route = $external ? $this->path : substr($this->path, 3);
        $resource = null;
        foreach (isset($validator->contract['paths'][$route]) ? [] : $validator->contract['paths'] as $template => $operations) {
            if (!str_contains($template, '{')) { continue; }
            $pattern = str_replace(['\{id\}', '\{code\}'], ['([^/]+)', '([^/]+)'], preg_quote($template, '~'));
            if (preg_match('~^' . $pattern . '$~D', $route, $m)) {
                $resource = $m[1];
                if (str_contains($template, '{id}')) { $validator->check(['type' => 'string', 'format' => 'uuid'], $resource); $resource = strtolower($resource); }
                $route = $template; break;
            }
        }
        if ((!$external && !str_starts_with($this->path, '/v1/')) || (!$external && str_starts_with($route, '/ext/')) || !isset($validator->contract['paths'][$route][strtolower($this->method)])) { throw new ApiError(404, 'resource_not_found'); }
        $this->route = $route;
        $this->resourceId = $resource;
        $this->operation = $validator->contract['paths'][$route][strtolower($this->method)];
        if (isset($headers['x-tenant-id'])) { $validator->check(['type'=>'string','format'=>'uuid'],$headers['x-tenant-id']); }
        $allowedQuery = [];
        foreach ($this->operation['parameters'] ?? [] as $parameter) {
            $parameter = isset($parameter['$ref']) ? $validator->contract['parameters'][basename($parameter['$ref'])] : $parameter;
            if (str_starts_with($route, '/client/') && $parameter['in'] === 'header') { continue; }
            if ($parameter['in'] === 'query') { $allowedQuery[] = $parameter['name']; }
            if (!in_array($parameter['in'], ['query', 'header'], true)) { continue; }
            $value = $parameter['in'] === 'query' ? ($_GET[$parameter['name']] ?? null) : ($headers[strtolower($parameter['name'])] ?? null);
            if ($value === null) {
                if ($parameter['required'] ?? false) { throw new ApiError($parameter['name'] === 'If-Match' ? 428 : 422, $parameter['name'] === 'If-Match' ? 'precondition_required' : 'validation_failed'); }
                continue;
            }
            if (!is_string($value)) { throw new ApiError(422, 'validation_failed'); }
            if (($parameter['schema']['type'] ?? '') === 'integer' && ctype_digit($value)) { $value = (int) $value; }
            if (($parameter['schema']['type'] ?? '') === 'boolean') { $value = match ($value) { 'true' => true, 'false' => false, default => $value }; }
            $validator->check($parameter['schema'], $value);
        }
        foreach ($_GET as $key => $v) { if (!in_array($key, $allowedQuery, true) || !is_string($v)) { throw new ApiError(422, 'validation_failed'); } }
        $max = $this->operation['x-max-body-bytes'] ?? 0;
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $max) { throw new ApiError(413, 'payload_too_large'); }
        $stream = fopen('php://input', 'rb');
        $this->raw = stream_get_contents($stream, $max + 1);
        fclose($stream);
        if (strlen($this->raw) > $max) { throw new ApiError(413, 'payload_too_large'); }
        if ($route === '/oauth/token') {
            if (!preg_match('~^application/x-www-form-urlencoded(?:\s*;\s*charset=utf-8)?$~iD', $headers['content-type'] ?? '')) { throw new ApiError(415, 'unsupported_media_type'); }
            $values = [];
            foreach (explode('&', $this->raw) as $pair) {
                $parts = explode('=', $pair, 2);
                $name = urldecode($parts[0]);
                if (count($parts) !== 2 || !in_array($name, ['grant_type', 'scope'], true) || isset($values[$name]) || preg_match('/%(?![a-f0-9]{2})/i', $pair)) { throw new OAuthError(400, 'invalid_request'); }
                $values[$name] = urldecode($parts[1]);
            }
            if (!isset($values['grant_type'])) { throw new OAuthError(400, 'invalid_request'); }
            if ($values['grant_type'] !== 'client_credentials') { throw new OAuthError(400, 'unsupported_grant_type'); }
            try { $validator->named('OAuthRequest', (object) $values); }
            catch (ApiError) { throw new OAuthError(400, 'invalid_request'); }
            $this->body = (object) $values;
        } elseif (isset($this->operation['requestBody'])) {
            if (!preg_match('~^application/json(?:\s*;\s*charset=utf-8)?$~iD', $headers['content-type'] ?? '')) { throw new ApiError(415, 'unsupported_media_type'); }
            try { $body = json_decode($this->raw, false, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new ApiError(400, 'invalid_json'); }
            $validator->check($this->operation['requestBody']['content']['application/json']['schema'], $body);
            $this->body = $body;
        } else { $this->body = null; }
        foreach ($this->operation['parameters'] ?? [] as $param) {
            if (($param['$ref'] ?? '') === '#/components/parameters/Idempotency') {
                $validator->check(['type' => 'string', 'format' => 'uuid'], $this->header('idempotency-key'));
            }
        }
    }
    public function header(string $name): string { return $this->headers[$name] ?? ''; }
}
