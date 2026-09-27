<?php

declare(strict_types=1);

use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\GuardResponse;
use RenzoFranceschini\GuardCore\Redis\GuardRedisException;
use RenzoFranceschini\GuardCore\Redis\RedisHandler;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;
use RenzoFranceschini\GuardCoreSymfony\SymfonyGuardRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

require __DIR__ . '/../vendor/autoload.php';

final class T
{
    public int $passed = 0;
    public int $failed = 0;

    public function same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
            echo '  expected: ' . var_export($expected, true) . "\n";
            echo '  actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function ok(bool $condition, string $label): void
    {
        if ($condition) {
            $this->passed++;
            echo "ok - {$label}\n";
        } else {
            $this->failed++;
            echo "FAIL - {$label}\n";
        }
    }

    public function throws(string $class, callable $fn, string $label): void
    {
        try {
            $fn();
            $this->failed++;
            echo "FAIL - {$label}: no exception\n";
        } catch (Throwable $e) {
            $this->same($class, $e::class, $label);
        }
    }

    public function section(string $name): void
    {
        echo "\n=== {$name} ===\n";
    }
}

final class RecordingKernel implements HttpKernelInterface, TerminableInterface
{
    public int $calls = 0;

    public ?Request $seen = null;

    public int $terminations = 0;

    public ?Response $terminateResponse = null;

    public Response $response;

    public function __construct()
    {
        $this->response = new Response('downstream');
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->calls++;
        $this->seen = $request;

        return $this->response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->terminations++;
        $this->terminateResponse = $response;
    }
}

final class NonTerminableKernel implements HttpKernelInterface
{
    public int $calls = 0;

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        $this->calls++;

        return new Response('downstream');
    }
}

final class ThrowingPathRequest extends Request
{
    public function getPathInfo(): string
    {
        throw new RuntimeException('malformed request path');
    }
}

/**
 * @param array<string, string|list<string>> $headers
 */
function symfonyRequest(
    string $path = '/',
    string $ip = '203.0.113.9',
    string $method = 'GET',
    string $query = '',
    string $body = '',
    array $headers = []
): Request {
    $request = Request::create(
        'http://ex.test' . $path . ($query !== '' ? '?' . $query : ''),
        $method,
        [],
        [],
        [],
        ['REMOTE_ADDR' => $ip],
        $body !== '' ? $body : null
    );
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

/**
 * @return array{0: GuardMiddleware, 1: GuardEngine, 2: RecordingKernel}
 */
function makeStack(SecurityConfig $config): array
{
    $engine = new GuardEngine($config);
    $kernel = new RecordingKernel();

    return [new GuardMiddleware($kernel, $engine), $engine, $kernel];
}

/**
 * @param list<array<string, mixed>> $hooks
 */
function hookCapture(array &$hooks): \Closure
{
    return function (object $request, array $payload) use (&$hooks): void {
        $hooks[] = $payload;
    };
}

$t = new T();
$attackQuery = 'q=' . urlencode("<script>alert('xss')</script>");

$t->section('Symfony -> GuardRequest translation');
$sf = symfonyRequest('/api/users', '198.51.100.7', 'GET', 'x=1', 'hello', ['X-Custom-Test' => ['a', 'b'], 'Content-Type' => 'text/plain']);
$sf->setMethod('get');
$guard = new SymfonyGuardRequest($sf);
$t->same('/api/users', $guard->urlPath(), 'url_path');
$t->same('http', $guard->urlScheme(), 'url_scheme');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_full');
$t->same('https://ex.test/api/users?x=1', $guard->urlReplaceScheme('https'), 'url_replace_scheme pure');
$t->same('http://ex.test/api/users?x=1', $guard->urlFull(), 'url_replace_scheme did not mutate');
$t->same('GET', $guard->method(), 'method upper-cased');
$t->same('198.51.100.7', $guard->clientHost(), 'client_host from the request client ip');
$t->same('a, b', $guard->headers()->get('x-custom-test'), 'multi-value header joined');
$t->same('text/plain', $guard->headers()->get('Content-Type'), 'header get is case-insensitive');
$t->same(['x' => '1'], $guard->queryParams(), 'query_params passthrough');
$t->same('hello', $guard->body(), 'body first read');
$t->same('hello', $guard->body(), 'body cached on replay');
$t->same(true, $guard->state() !== (new SymfonyGuardRequest($sf))->state(), 'state is per translated request');

$noAddr = Request::create('http://ex.test/');
$noAddr->server->remove('REMOTE_ADDR');
$t->same(null, (new SymfonyGuardRequest($noAddr))->clientHost(), 'missing REMOTE_ADDR -> null client_host');
$emptyAddr = Request::create('http://ex.test/');
$emptyAddr->server->set('REMOTE_ADDR', '');
$t->same(null, (new SymfonyGuardRequest($emptyAddr))->clientHost(), 'empty REMOTE_ADDR -> null client_host');

$t->section('bounded body read (spec 01.2)');
$guard = new SymfonyGuardRequest(symfonyRequest('/', '203.0.113.1', 'POST', body: str_repeat('A', 300000)));
$oversize = $guard->body();
$t->same(SymfonyGuardRequest::MAX_BODY_BYTES, strlen($oversize), 'oversize body capped at MaxBodyBytes');
$t->same(true, $guard->bodyWasTruncated(), 'truncation observable');
$t->same($oversize, $guard->body(), 'replay returns the same cached prefix');
$exact = SymfonyGuardRequest::MAX_BODY_BYTES;
$guard = new SymfonyGuardRequest(symfonyRequest('/', '203.0.113.2', 'POST', body: str_repeat('B', $exact)));
$t->same($exact, strlen($guard->body()), 'body exactly MaxBodyBytes read fully');
$t->same(false, $guard->bodyWasTruncated(), 'boundary body not flagged truncated');
$guard = new SymfonyGuardRequest(symfonyRequest('/', '203.0.113.3', 'POST', body: str_repeat('B', $exact + 1)));
$t->same($exact, strlen($guard->body()), 'body one byte over the cap capped');
$t->same(true, $guard->bodyWasTruncated(), 'one byte over flagged truncated');

$t->section('block verdict -> Symfony response');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware, , $kernel] = makeStack($config);
$blocked = $middleware->handle(symfonyRequest('/private', '192.0.2.66'), HttpKernelInterface::MAIN_REQUEST);
$t->same(403, $blocked->getStatusCode(), 'blacklisted ip -> 403');
$t->same('Forbidden', $blocked->getContent(), '403 body exact');
$t->ok($blocked instanceof Response, 'block response is a Symfony-native response');
// guard-core-php parity: error responses carry the engine default security
// headers (Python reference guard_core/core/responses/factory.py applies
// apply_security_headers inside create_error_response).
$securityHeaderKeys = [
    'x-content-type-options',
    'x-frame-options',
    'x-xss-protection',
    'referrer-policy',
    'permissions-policy',
    'x-permitted-cross-domain-policies',
    'x-download-options',
    'cross-origin-embedder-policy',
    'cross-origin-opener-policy',
    'cross-origin-resource-policy',
    'strict-transport-security',
];
$allowedHeaders = ['cache-control' => true, 'date' => true, 'content-type' => true] + array_flip($securityHeaderKeys);
$missing = array_diff_key(array_flip($securityHeaderKeys), $blocked->headers->all());
$t->same([], $missing, 'engine default security headers present on plain block');
$extra = array_diff_key($blocked->headers->all(), $allowedHeaders);
$t->same([], $extra, 'no unexpected headers on plain block (framework cache-control/date, engine content-type required)');
$t->same('text/plain; charset=utf-8', $blocked->headers->get('Content-Type'), 'block response content type explicit');
$t->same(0, $kernel->calls, 'downstream kernel not called on block');
$t->same('ip_security', $hooks[0]['check_name'] ?? null, 'on_block check_name');
$t->same('IP blacklisted: 192.0.2.66', $hooks[0]['reason'] ?? null, 'on_block reason');
$t->same(403, $hooks[0]['status_code'] ?? null, 'on_block status_code');
$t->same(false, $hooks[0]['passive_mode'] ?? null, 'on_block passive_mode false');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, enforceHttps: true, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$redirect = $middleware->handle(symfonyRequest('/order', '203.0.113.45'), HttpKernelInterface::MAIN_REQUEST);
$t->same(301, $redirect->getStatusCode(), 'https enforcement -> 301');
$t->same(['https://ex.test/order'], $redirect->headers->all('Location'), 'Location header translated to the Symfony response');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$limited = symfonyRequest('/limited', '203.0.113.44');
$t->same(200, $middleware->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'rate limit hit 1 passes');
$t->same(200, $middleware->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'rate limit hit 2 passes');
$third = $middleware->handle($limited, HttpKernelInterface::MAIN_REQUEST);
$t->same(429, $third->getStatusCode(), 'rate limit hit 3 -> 429');
$t->same('Too many requests', $third->getContent(), '429 body exact');
$t->same(['60'], $third->headers->all('Retry-After'), 'Retry-After header translated');

$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$suspicious = $middleware->handle(symfonyRequest('/search', '203.0.113.10', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(400, $suspicious->getStatusCode(), 'penetration via query param -> 400');
$t->same('Suspicious activity detected', $suspicious->getContent(), 'suspicious body exact');

$t->section('pass verdict -> downstream kernel');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, onBlock: hookCapture($hooks));
[$middleware, , $kernel] = makeStack($config);
$request = symfonyRequest('/search', '203.0.113.10', 'GET', 'q=hello+world');
$passthrough = $middleware->handle($request, HttpKernelInterface::MAIN_REQUEST);
$t->same(1, $kernel->calls, 'downstream kernel called exactly once on pass');
$t->same($request, $kernel->seen, 'downstream kernel received the original Symfony request');
$t->same('downstream', $passthrough->getContent(), 'downstream response returned unchanged');
$t->same([], $hooks, 'no on_block on pass');

$t->section('POST body scanned and replayed through the stack');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$t->same(200, $middleware->handle(symfonyRequest('/submit', '203.0.113.20', 'POST', body: 'name=renzo&comment=ok'), HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'clean POST passes');
$t->same(400, $middleware->handle(symfonyRequest('/submit', '203.0.113.21', 'POST', body: 'comment=<script>alert(1)</script>'), HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'attack body in POST -> 400');

$t->section('oversize body per spec 01 (engine only ever sees the capped prefix)');
$seenBody = null;
$config = new SecurityConfig(
    enableRedis: false,
    enablePenetrationDetection: false,
    customRequestCheck: static function (GuardRequest $request) use (&$seenBody): ?GuardResponse {
        $seenBody = $request->body();

        return null;
    }
);
[$middleware] = makeStack($config);
$beyond = symfonyRequest('/upload', '203.0.113.30', 'POST', body: str_repeat('lorem ipsum ', 25000) . '<script>alert(1)</script>');
$t->same(200, $middleware->handle($beyond, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'beyond-cap suffix does not block the request');
$t->same(SymfonyGuardRequest::MAX_BODY_BYTES, strlen($seenBody ?? ''), 'engine received exactly the capped prefix');
$t->same(false, str_contains($seenBody ?? '', '<script>'), 'payload beyond the cap never reaches the engine');
$seenBody = null;
$inside = symfonyRequest('/upload', '203.0.113.31', 'POST', body: str_repeat('lorem ipsum ', 20000) . '<script>alert(1)</script>');
$t->same(200, $middleware->handle($inside, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'spy stack passes the mid-size attack request');
$t->same(true, str_contains($seenBody ?? '', '<script>alert(1)</script>'), 'payload inside the prefix reaches the engine');

$t->section('mid-size POST body detection end to end');
$config = new SecurityConfig(enableRedis: false);
[$middleware] = makeStack($config);
$filler = 'lorem ipsum dolor sit amet consectetur adipiscing elit sed do eiusmod ';
$hit = $middleware->handle(
    symfonyRequest('/submit', '203.0.113.32', 'POST', body: $filler . '<script>alert(1)</script>' . $filler),
    HttpKernelInterface::MAIN_REQUEST
);
$t->same(400, $hit->getStatusCode(), 'attack inside a mid-size body is detected');
$t->same('Suspicious activity detected', $hit->getContent(), 'mid-size attack body exact');
$clean = $middleware->handle(symfonyRequest('/submit', '203.0.113.33', 'POST', body: $filler), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $clean->getStatusCode(), 'clean mid-size body passes');

$t->section('whitelist through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, whitelist: ['203.0.113.50'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$allowed = $middleware->handle(symfonyRequest('/search', '203.0.113.50', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $allowed->getStatusCode(), 'whitelisted ip passes despite attack payload');
$other = $middleware->handle(symfonyRequest('/search', '203.0.113.99', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(403, $other->getStatusCode(), 'non-whitelisted ip -> 403');
$t->same('Forbidden', $other->getContent(), 'whitelist miss body exact');

$t->section('exclusion paths through the full stack');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware] = makeStack($config);
$docs = $middleware->handle(symfonyRequest('/docs/api', '203.0.113.60', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $docs->getStatusCode(), 'attack on excluded path passes (exclusion-scoped pipeline)');
$search = $middleware->handle(symfonyRequest('/search', '203.0.113.60', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(400, $search->getStatusCode(), 'same attack outside excluded path -> 400');
$docsBanned = $middleware->handle(symfonyRequest('/docs/api', '192.0.2.66'), HttpKernelInterface::MAIN_REQUEST);
$t->same(403, $docsBanned->getStatusCode(), 'ip_security still enforced on excluded paths');
$favicon = $middleware->handle(symfonyRequest('/favicon.ico', '203.0.113.60', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $favicon->getStatusCode(), 'default exclusion favicon.ico passes');

$t->section('passive mode');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, passiveMode: true, onBlock: hookCapture($hooks));
[$middleware, , $kernel] = makeStack($config);
$passive = $middleware->handle(symfonyRequest('/search', '203.0.113.70', 'GET', $attackQuery), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $passive->getStatusCode(), 'passive mode does not block');
$t->same(1, $kernel->calls, 'passive mode hands request to the downstream kernel');
$t->same([], $hooks, 'passive mode fires no on_block from suspicious_activity');

$t->section('sub-requests pass through unscreened');
$hooks = [];
$config = new SecurityConfig(enableRedis: false, blacklist: ['192.0.2.66'], onBlock: hookCapture($hooks));
[$middleware, , $kernel] = makeStack($config);
$subRequest = symfonyRequest('/fragment', '192.0.2.66');
$sub = $middleware->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
$t->same('downstream', $sub->getContent(), 'sub-request for a blacklisted ip reaches the kernel');
$t->same(1, $kernel->calls, 'sub-request forwarded to the wrapped kernel');
$t->same([], $hooks, 'engine never consulted for sub-requests');
$t->same(403, $middleware->handle(symfonyRequest('/fragment', '192.0.2.66'), HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'same ip on the main request is still blocked');

$t->section('terminate forwards to a terminable kernel');
$config = new SecurityConfig(enableRedis: false);
[$middleware, , $kernel] = makeStack($config);
$terminateRequest = symfonyRequest('/done', '203.0.113.71');
$terminateResponse = new Response('done');
$middleware->terminate($terminateRequest, $terminateResponse);
$t->same(1, $kernel->terminations, 'terminate forwarded to the terminable kernel');
$t->same($terminateResponse, $kernel->terminateResponse, 'terminate forwarded the original response');
$ntk = new NonTerminableKernel();
$middleware = new GuardMiddleware($ntk, new GuardEngine(new SecurityConfig(enableRedis: false)));
$t->ok((static function () use ($middleware, $terminateRequest): bool {
    try {
        $middleware->terminate($terminateRequest, new Response('done'));

        return true;
    } catch (Throwable) {
        return false;
    }
})(), 'terminate on a non-terminable kernel is a silent no-op');

$t->section('fail-closed on engine malfunction (conformance.md)');
$config = new SecurityConfig(enableRedis: false);
[$middleware, , $kernel] = makeStack($config);
$malformed = $middleware->handle(new ThrowingPathRequest([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.90']), HttpKernelInterface::MAIN_REQUEST);
$t->same(500, $malformed->getStatusCode(), 'engine malfunction -> fail-closed 500');
$t->same('Security check failed', $malformed->getContent(), 'fail-closed body exact');
$t->same(0, $kernel->calls, 'downstream kernel not called on malfunction');

$config = new SecurityConfig(enableRedis: false, customErrorResponses: [500 => 'Security unavailable']);
[$middleware] = makeStack($config);
$custom = $middleware->handle(new ThrowingPathRequest([], [], [], [], [], ['REMOTE_ADDR' => '203.0.113.91']), HttpKernelInterface::MAIN_REQUEST);
$t->same('Security unavailable', $custom->getContent(), 'custom_error_responses honored on fail-closed');

$config = new SecurityConfig(enableRedis: false, customRequestCheck: static fn (): never => throw new RuntimeException('validator exploded'));
[$middleware] = makeStack($config);
$pipelineFail = $middleware->handle(symfonyRequest('/x', '203.0.113.80'), HttpKernelInterface::MAIN_REQUEST);
$t->same(500, $pipelineFail->getStatusCode(), 'check exception -> pipeline fail-secure 500');
$t->same('Security check failed', $pipelineFail->getContent(), 'pipeline fail-secure body exact');

$config = new SecurityConfig(enableRedis: true, redisFailOpen: false);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_symfonyu:', host: '127.0.0.1', port: 1));
$t->throws(
    GuardRedisException::class,
    static function () use ($config, $engine): void {
        new GuardMiddleware(new NonTerminableKernel(), $engine);
    },
    'redis down + redis_fail_open=false: middleware construction fails closed'
);

$config = new SecurityConfig(enableRedis: true, redisFailOpen: true);
$engine = new GuardEngine($config, new RedisHandler(enableRedis: true, prefix: 'guard_core_symfonyu:', host: '127.0.0.1', port: 1));
$middleware = new GuardMiddleware(new NonTerminableKernel(), $engine);
$openPass = $middleware->handle(symfonyRequest('/x', '203.0.113.81'), HttpKernelInterface::MAIN_REQUEST);
$t->same(200, $openPass->getStatusCode(), 'redis down + redis_fail_open=true: construction survives, request passes (bounded fail-open)');

$t->section('integration: shared state over real redis');
$integration = getenv('REDIS_HOST') !== '0';
if ($integration) {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $socket = @fsockopen($host, $port, $errno, $errstr, 1.0);
    if ($socket === false) {
        echo "SKIP: integration mode: no redis reachable at {$host}:{$port} ({$errstr}); unit coverage stands\n";
    } else {
        fclose($socket);
        runRedisIntegration($t);
    }
} else {
    echo "\nNOTE: integration mode off (set REDIS_HOST to run the adapter over real redis)\n";
}

function runRedisIntegration(T $t): void
{
    putenv('REDIS_PREFIX=guard_core_symfony:' . bin2hex(random_bytes(3)) . ':');
    $redis = RedisHandler::fromEnv();
    $redis->initialize();
    $conn = $redis->connection();
    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }

    $t->section('integration: rate limit shared across engine instances');
    $configA = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineA = new GuardEngine($configA, $redis);
    $middlewareA = new GuardMiddleware(new NonTerminableKernel(), $engineA);
    $limited = symfonyRequest('/limited', '192.0.2.11');
    $t->same(200, $middlewareA->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'engine A hit 1 passes');
    $t->same(200, $middlewareA->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'engine A hit 2 passes');
    $t->same(429, $middlewareA->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'engine A hit 3 -> 429 over redis');

    $configB = new SecurityConfig(rateLimit: 2, rateLimitWindow: 60, enableRateLimiting: true, enablePenetrationDetection: false);
    $engineB = new GuardEngine($configB, $redis);
    $middlewareB = new GuardMiddleware(new NonTerminableKernel(), $engineB);
    $t->same(429, $middlewareB->handle($limited, HttpKernelInterface::MAIN_REQUEST)->getStatusCode(), 'fresh engine B sees the shared bucket immediately');

    $t->section('integration: ban written by engine A blocks engine B through the adapter');
    $t->same(true, $engineA->banManager()->ban('192.0.2.66', 60, 'symfony_integration'), 'engine A bans 192.0.2.66');
    $banned = $middlewareB->handle(symfonyRequest('/private', '192.0.2.66'), HttpKernelInterface::MAIN_REQUEST);
    $t->same(403, $banned->getStatusCode(), 'engine B blocks the banned ip');
    $t->same('IP address banned', $banned->getContent(), 'ban body exact');

    foreach ($redis->keys('*') as $key) {
        $conn->del((string) $key);
    }
}

$total = $t->passed + $t->failed;
echo "\nPassed: {$t->passed}, Failed: {$t->failed}\n";
echo "{$t->passed}/{$total}" . ($t->failed === 0 ? ' GREEN' : ' RED') . "\n";
exit($t->failed === 0 ? 0 : 1);
