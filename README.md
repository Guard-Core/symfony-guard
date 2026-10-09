<p align="center">
    <a href="https://guard-core.github.io/guard-core/latest/">
        <img src="https://guard-core.github.io/guard-core/latest/assets/guard_core_legend.svg" alt="Guard Core">
    </a>
</p>

___

<p align="center">
    <strong>Symfony middleware adapter for [guard-core-php](https://github.com/Guard-Core/guard-core-php): maps Symfony `HttpFoundation` Request objects to the guard-core engine and translates block verdicts back to Symfony-native responses. Works with Symfony 6.4 LTS and 7.x.</strong>
</p>

<p align="center">
    <a href="https://packagist.org/packages/rennf93/symfony-guard">
        <img src="https://img.shields.io/packagist/v/rennf93/symfony-guard?color=0080ff" alt="Packagist version">
    </a>
    <a href="https://guard-core.github.io/symfony-guard/latest/">
        <img src="https://img.shields.io/badge/docs-latest-0080ff.svg" alt="Docs">
    </a>
    <a href="https://github.com/Guard-Core/symfony-guard/actions/workflows/release.yml">
        <img src="https://github.com/Guard-Core/symfony-guard/actions/workflows/release.yml/badge.svg" alt="Release">
    </a>
    <a href="https://opensource.org/licenses/MIT">
        <img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License">
    </a>
    <a href="https://github.com/Guard-Core/symfony-guard/actions/workflows/ci.yml">
        <img src="https://github.com/Guard-Core/symfony-guard/actions/workflows/ci.yml/badge.svg" alt="CI">
    </a>
</p>

<p align="center">
    <a href="https://github.com/Guard-Core/symfony-guard/actions/workflows/pages/pages-build-deployment">
        <img src="https://github.com/Guard-Core/symfony-guard/actions/workflows/pages/pages-build-deployment/badge.svg?branch=gh-pages" alt="PagesBuildDeployment">
    </a>
    <a href="https://github.com/Guard-Core/symfony-guard/actions/workflows/docs.yml">
        <img src="https://github.com/Guard-Core/symfony-guard/actions/workflows/docs.yml/badge.svg" alt="DocsUpdate">
    </a>
    <img src="https://img.shields.io/github/last-commit/Guard-Core/symfony-guard?style=flat&amp;logo=git&amp;logoColor=white&amp;color=0080ff" alt="last-commit">
</p>

<p align="center">
    <img src="https://img.shields.io/badge/Symfony-000000.svg?style=flat&logo=symfony&logoColor=white" alt="Symfony"> <img src="https://img.shields.io/badge/PHP-777BB4.svg?style=flat&logo=php&logoColor=white" alt="PHP">
    <a href="https://packagist.org/packages/rennf93/symfony-guard">
        <img src="https://img.shields.io/packagist/dm/rennf93/symfony-guard" alt="Downloads">
    </a>
</p>

<p align="center">
    <a href="https://guard-core.com">Website</a> &middot;
    <a href="https://guard-core.github.io/symfony-guard/latest/">Docs</a> &middot;
    <a href="https://playground.guard-core.com">Playground</a> &middot;
    <a href="https://app.guard-core.com">Dashboard</a> &middot;
    <a href="https://discord.gg/ZW7ZJbjMkK">Discord</a>
</p>

---

## Install

```bash
composer require rennf93/symfony-guard
```

## Usage

Wrap your kernel with the middleware (front controller or wherever the kernel is assembled):

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;
use Symfony\Component\HttpFoundation\Request;

// Anywhere your App\Kernel is created
$kernel = new GuardMiddleware(
    new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']),
    new GuardEngine(new SecurityConfig(
        enableRedis: false,
        blacklist: ['192.0.2.0/24'],
        rateLimit: 100,
        rateLimitWindow: 60,
        enableRateLimiting: true,
    ))
);
```

```php
// public/index.php
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
```

Blocked requests get the engine's block verdict translated exactly (status, body, headers) as a `Symfony\Component\HttpFoundation\Response`: `403 Forbidden` for a blacklisted IP, `429 Too many requests` with `Retry-After` for a rate limit hit. Passing requests continue into the wrapped kernel untouched.

Pass-through responses are finished by the middleware too: the engine's security headers and CORS verdict headers are merged on top of the kernel's response, and the engine's behavioral return rules observe the response status code plus a body prefix bounded by `behaviorMaxResponseBodyInspectBytes` (only while `behaviorScanResponseBody` is on). Per-route configuration attaches through the middleware's route map or a custom resolver:

```php
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;

return new GuardMiddleware(
    $kernel,
    new GuardEngine($config),
    routes: [
        '/docs/' => new RouteConfig(enableSuspiciousDetection: false),
        '/api/' => new RouteConfig(rateLimit: 5, rateLimitWindow: 10),
    ],
    // optional: country resolver for RouteConfig geoRateLimits tiers
    geoRateLimitResolver: $myResolver,
    // optional: custom resolver receiving the Symfony request
    routeResolver: fn (Request $r) => str_starts_with($r->getPathInfo(), '/admin') ? new RouteConfig(bypassedChecks: ['rate_limit']) : null,
);
```

`routes` patterns match a path exactly or as a prefix when they end with `/`. Geo rate-limit tiers need a country resolver: pass `geoRateLimitResolver` explicitly, or configure `geoIpHandler` together with `blockedCountries`/`whitelistCountries` (the engine keeps the injected handler only when country lists are set) and the middleware bridges it onto the engine's rate-limit handler automatically.

## Lifecycle

PHP shared-nothing applies: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under long-running runtimes (FrankenPHP, RoadRunner, workerman). The middleware holds no mutable state of its own. In-memory fallbacks are per-request safety nets; distributed rate limits, IP bans, and cloud-range caches require Redis (set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance).

## Behavior notes

- Fail-closed: if the engine throws, the middleware returns the engine's fail-closed response (`500 Security check failed`, honorably overridden by `customErrorResponses`) instead of letting the request through.
- Main request only: the middleware screens the main kernel request and passes sub-requests straight into the wrapped kernel. Sub-requests are internal and derived from a main request that was already screened; screening them again would double-count rate-limit hits (fragments, forwards).
- Bounded body read: the request body is scanned as a prefix of at most 256 KiB (`SymfonyGuardRequest::MAX_BODY_BYTES`, matching the engine's full-scan window). The framework materializes the full body in memory; the engine only ever sees the capped prefix. Payloads beyond the prefix, or signatures split across its boundary, are not detected.
- Client address: the adapter maps the engine's `clientHost()` onto `$request->getClientIp()`. Without Symfony's trusted-proxies configuration that is the connecting `REMOTE_ADDR`, and the engine's own `trusted_proxies` / `X-Forwarded-For` resolution applies. With Symfony trusted proxies configured, Symfony resolves the forwarded chain first and the engine sees the resolved client. Pick one side to do the resolving; configuring both can double-hop.
- With `redisFailOpen: true` the middleware constructs and serves requests even when Redis is unreachable; with `redisFailOpen: false` construction fails closed.
- No security headers or CORS are added by this adapter. (Symfony response mechanics put a `Date` and a private `Cache-Control` on every response object; the engine's own headers are copied exactly.)

## Testing

```bash
composer lint
composer test
```

`composer test` runs the plain-PHP suite in `bin/test_symfony.php` (unit coverage always; set `REDIS_HOST` to a reachable Redis to include the shared-state integration cases).

## Status

Released: v1.1.0 on Packagist. The engine floor is `rennf93/guard-core-php ^4.1.0`; no 4.1.0 of the engine is currently published (its tag is absent and Packagist's latest is v4.0.4), so public resolution is collapsed until the synchronized 4.2.0 train retags the engine - CI resolves the engine from the master sibling checkout in the meantime.

## License

MIT
