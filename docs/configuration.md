# Configuration

The adapter has no options of its own; all security tuning is engine
configuration via `RenzoFranceschini\GuardCore\Config\SecurityConfig`. See the
[guard-core-php source](https://github.com/Guard-Core/guard-core-php/tree/master/src/Config/SecurityConfig.php)
for the full surface.

## Minimal tuned setup

```php
$config = new SecurityConfig(
    enableRedis: true,
    redisPrefix: 'guard_core:',
    redisFailOpen: true,
    enableRateLimiting: true,
    rateLimit: 30,
    rateLimitWindow: 60,
    endpointRateLimits: [
        '/rate/strict' => ['limit' => 1, 'window' => 10],
    ],
    enableIpBanning: true,
    autoBanThreshold: 5,
    autoBanDuration: 300,
    customErrorResponses: [403 => 'Blocked by symfony-guard'],
    excludePaths: ['/health'],
);
```

Endpoint rate limits are keyed by URL path and override the global limit for
those paths. `excludePaths` matches a path exactly or as a directory prefix.

## Redis

Distributed bans and rate limits require Redis:

```php
$config = new SecurityConfig(
    enableRedis: true,
    redisPrefix: 'guard_core:',
);
```

The engine connects to `REDIS_HOST` (default `127.0.0.1`) and `REDIS_PORT`
(default `6379`) from the environment. With `redisFailOpen: true` the
middleware constructs and serves requests even when Redis is unreachable; with
`redisFailOpen: false` construction fails closed. Without Redis the managers
fall back to in-process state, which does not share across replicas.

## Excluded detection headers

The engine skips the `ssrf` category for the proxy identity headers (`host`, `x-forwarded-for`, `x-real-ip`, `cf-connecting-ip`, `forwarded`, ...) by default, so a benign `Host` header such as `localhost:8080` does not trigger a block with every detection category enabled. Every other category (sqli, xss, cmd_injection, and so on) still scans those headers.

Pass `excludedDetectionHeaders` to extend that set with your own address-carrying headers, and `excludedSemanticDetectionHeaders` to keep a header (for example a JWT session cookie) out of the semantic analyzer only:

```php
$config = new SecurityConfig(
    excludedDetectionHeaders: ['x-custom-proxy-ip'],
    excludedSemanticDetectionHeaders: ['x-session-token'],
    // ...
);
```

Do not disable the `ssrf` category to work around address headers, and do not try to mutate the request inside the middleware, the adapter does not support it.

## Body inspection

`PsrGuardRequest::MAX_BODY_BYTES` (256 KiB, matching the engine's full-scan
window) bounds what the detector sees. Bodies larger than the limit are still
forwarded to your handler; only the inspected prefix is truncated, and
signatures split across the boundary are not detected. The bound is deliberate
and engine-coupled; do not widen it in consumer code.

## Trusted proxies

When behind a reverse proxy, trust only the proxy hop so the engine resolves
the real client IP from forwarded headers:

```php
$config = new SecurityConfig(
    trustedProxies: ['172.16.0.0/12', '10.0.0.0/8'],
    trustedProxyDepth: 1,
    // ...
);
```

## Per-route configuration and pass-through wiring

The middleware takes route configs and resolver hooks as its own options; the
security content of each route still comes from the engine's `RouteConfig`.

```php
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;

$middleware = new GuardMiddleware(
    $kernel,
    new GuardEngine($config),
    routes: [
        '/open/' => new RouteConfig(enableSuspiciousDetection: false),
        '/chatty/' => new RouteConfig(rateLimit: 5, rateLimitWindow: 60),
    ],
    geoRateLimitResolver: new MyCountryResolver(),   // powers RouteConfig geoRateLimits
    routeResolver: null,                             // or fn (Request $r): ?RouteConfig
);
```

On the pass-through path the middleware applies the engine's security headers
(`securityHeaders`) and CORS verdict headers (`enableCors`, `corsAllow*`) to the
kernel's response, and reports the response status plus a body prefix (bounded
by `behaviorMaxResponseBodyInspectBytes`, scanned only when
`behaviorScanResponseBody` is on) to the engine's behavioral return rules:
`globalBehaviorRules` and route `behaviorRules` with `return_pattern` rules act
on what the application actually served. Return rules never modify the response.
