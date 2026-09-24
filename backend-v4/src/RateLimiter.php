<?php
declare(strict_types=1);
namespace Keeper;

final class RateLimiter
{
    public array $headers = [];
    public function external(array $c, bool $token = false): void
    {
        $buckets = [
            ['integration', 'external:'.bin2hex($c['tenant_id'].$c['integration_id']), Config::number('EXTERNAL_RATE',120), Config::number('EXTERNAL_BURST',30)],
            ['tenant', 'external:'.bin2hex($c['tenant_id']), Config::number('EXTERNAL_TENANT_RATE',600), Config::number('EXTERNAL_TENANT_BURST',100)],
        ];
        if ($token) { $buckets[]=['integration','oauth:'.bin2hex($c['tenant_id'].$c['client_id']),Config::number('OAUTH_RATE',10),Config::number('OAUTH_BURST',10)]; }
        $this->take($buckets);
    }
    public function externalQueries(array $c, callable $handler): mixed
    {
        $dir=Config::get('RATE_DIR',dirname(__DIR__).'/var/rate').'/external-concurrency';
        if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) { throw new ApiError(503,'temporarily_unavailable'); }
        $held=[];
        try {
            foreach ([['integration', $c['tenant_id'].$c['integration_id'],4],['tenant',$c['tenant_id'],16]] as [$scope,$identity,$slots]) {
                $acquired=false;
                for ($slot=0;$slot<$slots;$slot++) {
                    $f=fopen($dir.'/'.hash('sha256',$scope.$identity).'.'.$slot,'c+b');
                    if (!$f) { throw new ApiError(503,'temporarily_unavailable'); }
                    if (flock($f,LOCK_EX|LOCK_NB)) { $held[]=$f; $acquired=true; break; }
                    fclose($f);
                }
                if (!$acquired) { throw new ApiError(429,'rate_limited',['Retry-After'=>'1','X-RateLimit-Scope'=>$scope]); }
            }
            return $handler();
        } finally { foreach ($held as $f) { flock($f,LOCK_UN); fclose($f); } }
    }
    public function take(array $buckets): void
    {
        $dir = Config::get('RATE_DIR', dirname(__DIR__) . '/var/rate');
        $apcu = function_exists('apcu_enabled') && apcu_enabled();
        $wait = 0; $min = INF;
        foreach ($buckets as [$scope, $identity, $rate, $burst]) {
            $key = hash('sha256', $scope . ':' . $identity);
            [$remaining, $retry] = $apcu ? $this->cached($dir, $key, $rate, $burst) : $this->file($dir, $key, $rate, $burst);
            if ($remaining / $rate < $min) {
                $min = $remaining / $rate;
                $this->headers = ['RateLimit-Limit' => (string) $rate, 'RateLimit-Remaining' => (string) floor($remaining), 'RateLimit-Reset' => (string) (int) ceil(($burst - $remaining) * 60 / $rate), 'X-RateLimit-Scope' => $scope];
            }
            $wait = max($wait, $retry);
        }
        if ($wait > 0) { throw new ApiError(429, 'rate_limited', $this->headers + ['Retry-After' => (string) $wait]); }
    }
    private function cached(string $dir, string $key, int $rate, int $burst): array
    {
        $key = 'keeper:rate:' . hash('sha256', $dir) . ':' . $key;
        $interval = 60000000 / $rate;
        $deadline = microtime(true) + 1;
        do {
            $fullAt = apcu_fetch($key, $found);
            $now = (int) floor(microtime(true) * 1000000);
            if (!$found) {
                if (!apcu_add($key, $now)) { continue; }
                $fullAt = $now;
            }
            // The full-refill time encodes fractional tokens in an integer suitable for atomic CAS.
            $tokens = max(0, $burst - max(0, $fullAt - $now) / $interval);
            if ($tokens < 1) { return [$tokens, (int) ceil((1 - $tokens) * 60 / $rate)]; }
            if (apcu_cas($key, $fullAt, (int) ceil(max($fullAt, $now) + $interval))) { return [$tokens - 1, 0]; }
        } while (microtime(true) < $deadline);
        throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '5']);
    }
    private function file(string $dir, string $key, int $rate, int $burst): array
    {
        $dir .= '/' . substr($key, 0, 2);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '5']); }
        $f = fopen($dir . '/' . $key . '.json', 'c+b');
        if (!$f) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '5']); }
        try {
            if (!flock($f, LOCK_EX)) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '5']); }
            $raw = stream_get_contents($f);
            $now = microtime(true);
            $entry = $raw === '' ? ['at' => $now, 'tokens' => $burst] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            $tokens = min($burst, $entry['tokens'] + max(0, $now - $entry['at']) * $rate / 60);
            $remaining = $tokens >= 1 ? $tokens - 1 : $tokens;
            $wait = $tokens < 1 ? (int) ceil((1 - $tokens) * 60 / $rate) : 0;
            rewind($f); $json = Util::json(['at' => $now, 'tokens' => $remaining]);
            if (fwrite($f, $json) !== strlen($json) || !ftruncate($f, strlen($json)) || !fflush($f)) { throw new ApiError(503, 'temporarily_unavailable'); }
            return [$remaining, $wait];
        } finally { flock($f, LOCK_UN); fclose($f); }
    }
    public function device(array $c): void
    {
        $d = Config::number('TENANT_FLEET', 1000);
        $this->take([
            ['device', bin2hex($c['tenant_id'] . $c['device_id']), Config::number('DEVICE_RATE', 2), Config::number('DEVICE_BURST', 5)],
            ['tenant', bin2hex($c['tenant_id']), max(120, 2 * $d), max(20, (int) ceil(.1 * $d))],
        ]);
    }
    public function bootstrap(?array $identity): void
    {
        $b = [['bootstrap', 'unknown', 60, 20]];
        if ($identity) {
            $b = [
                ['bootstrap', bin2hex($identity['tenant_id'] . $identity['id']), Config::number('BOOTSTRAP_RATE', 5), Config::number('BOOTSTRAP_BURST', 5)],
                ['tenant', 'bootstrap:' . bin2hex($identity['tenant_id']), 60, 20],
            ];
        }
        $this->take($b);
    }
}
