---
name: symfony-guard
description: Use when an agent works in rennf93/symfony-guard or touches its Symfony adapter surface: Symfony middleware adapter that wraps an HttpKernelInterface and wires HttpFoundation requests to the guard-core-php engine and contains no security logic of its own; covers middleware construction and configuration (GuardEngine, redisFailOpen), request adaptation (SymfonyGuardRequest, bounded 256 KiB body read, getClientIp() client host), main-request scoping with sub-request passthrough, terminate forwarding, block-response translation (ResponseTranslator, fail-closed 500), composer scripts (composer lint, composer test), and the plain-PHP test runner bin/test_symfony.php with its REDIS_HOST integration mode.
---

# symfony-guard

## Quick Reference

- Package `rennf93/symfony-guard`; PSR-4 namespace `RenzoFranceschini\GuardCoreSymfony\` mapped to `src/`.
- Three final classes: `GuardMiddleware` (HttpKernelInterface decorator + TerminableInterface), `SymfonyGuardRequest` (implements the core `GuardRequest` contract), `ResponseTranslator` (`GuardResponse` to `Symfony\Component\HttpFoundation\Response`).
- Requires PHP `^8.2`, `rennf93/guard-core-php ^0.1.0` (locked v0.1.0), `symfony/http-foundation ^6.4|^7.0`, and `symfony/http-kernel ^6.4|^7.0` (the middleware interfaces the adapter implements live there).
- Commands: `composer lint` (php -l sweep over `src` and `bin`), `composer test` (`php bin/test_symfony.php`). CI also runs `composer install --no-interaction --no-progress`, `composer update rennf93/guard-core-php --no-interaction`, the same php -l sweep, `REDIS_HOST=127.0.0.1 php bin/test_symfony.php`, and `composer audit`.
- The adapter holds no security logic. Every verdict comes from `GuardEngine::execute()`.

## Installation

```bash
composer require rennf93/symfony-guard
```

Until `rennf93/guard-core-php` has a Packagist release, point Composer at its repository (README setup):

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Guard-Core/guard-core-php" }
    ]
}
```

The dependency constraint is `rennf93/guard-core-php: ^0.1.0`. This package's own composer.json resolves the core locally through a `../guard-core-php` path repository (marked `"canonical": false`) with the VCS URL above as fallback.

## Setup

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;
use Symfony\Component\HttpFoundation\Request;

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

// public/index.php
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
```

- The consumer builds the `SecurityConfig` and the `GuardEngine` and wraps their own kernel (`new GuardMiddleware($kernel, $engine)`), typically where the kernel is assembled. This package ships no Symfony Bundle, no DI extension, no compiler pass, and no publishable config file.
- Lifecycle: construct `GuardEngine` (and therefore `GuardMiddleware`) per request in classic FPM, or per worker under long-running runtimes (FrankenPHP, RoadRunner). The middleware holds no mutable state of its own.
- Redis: set `enableRedis: true` and point `REDIS_HOST`/`REDIS_PORT` at your instance for distributed rate limits, IP bans, and cloud-range caches. With `redisFailOpen: true` the middleware still constructs and serves requests when Redis is unreachable; with `redisFailOpen: false` construction fails closed.

## GuardMiddleware

- `final class GuardMiddleware implements HttpKernelInterface, TerminableInterface`; a kernel decorator middleware, not a PSR-15 middleware and not an event subscriber.
- `__construct(HttpKernelInterface $kernel, GuardEngine $engine)`. It builds a `ResponseTranslator` internally and calls `$engine->initialize()`. A `GuardRedisException` from initialization is swallowed only when `$engine->config()->redisFailOpen` is true; otherwise it rethrows and construction fails closed.
- `handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Symfony\Component\HttpFoundation\Response`:
  - Non-main `$type` (sub-requests): forwarded straight to the wrapped kernel; the engine is never consulted. Sub-requests are internal and derive from a main request that was already screened; external traffic cannot arrive as a sub-request.
  - Main request: wrapped in `SymfonyGuardRequest` and passed to `$engine->execute()`.
  - Engine throws `Throwable`: returns `$this->translator->translate($this->engine->failClosedResponse())`. Default is `500 Security check failed`, overridable through the engine's `customErrorResponses`. The wrapped kernel never runs.
  - Non-null `GuardResponse` verdict: translated to a Symfony response with the exact engine status, body, and headers. The wrapped kernel never runs.
  - `null` verdict: `$this->kernel->handle($request, $type, $catch)`, passing the ORIGINAL Symfony request untouched.
- `terminate(Request $request, Response $response): void` forwards to the wrapped kernel when it implements `TerminableInterface`; silent no-op otherwise. Front controllers can call `terminate()` on the middleware as they would on the bare kernel.

## SymfonyGuardRequest

- `final class SymfonyGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`; wraps a `Symfony\Component\HttpFoundation\Request`.
- `MAX_BODY_BYTES = 262144` (256 KiB, public). `body()` reads `$request->getContent()`, caches at most the cap, and replays the cache on later calls. `bodyWasTruncated()` reports whether bytes remain beyond the cap. The boundary is exact: a body of exactly 262144 bytes is read fully and not flagged truncated; one byte over is capped and flagged. The framework materializes the full body in memory (php://input); the engine only ever sees the capped prefix.
- URL adaptation: `urlPath()` from `getPathInfo()` (empty string becomes `/`), `urlScheme()` from `getScheme()`, `urlFull()` as scheme://`getHttpHost()` plus path plus query when non-empty (Symfony `getQueryString()` normalizes and sorts the query, so `urlFull()` is canonical), and `urlReplaceScheme()`, which is pure and does not mutate the request.
- `method()` upper-cases the Symfony method. `clientHost()` returns `$request->getClientIp()`, or `null` when it is missing or empty. Without Symfony trusted proxies configured that is the connecting `REMOTE_ADDR`, and the engine's own `trusted_proxies` / `X-Forwarded-For` resolution applies; with Symfony trusted proxies configured, Symfony resolves the forwarded chain first (configure one side, not both).
- `headers()` builds a `HeaderBag` from the Symfony header bag (keys are lowercase-dashed), joining multi-value headers as `a, b`; lookup is case-insensitive. `queryParams()` passes the query bag through unchanged.
- `state()` returns a fresh per-instance `RequestState` (each translated request has its own).

## ResponseTranslator

- `final class ResponseTranslator`; no constructor arguments.
- `translate(GuardResponse $response): Symfony\Component\HttpFoundation\Response` creates a response with the guard status code and body (a null body becomes the empty string), then sets every header from the guard response's `HeaderBag`. It is an exact copy and adds nothing: no security headers, no CORS. `prepare()` is never called, so no Content-Type/charset is synthesized.

## Footguns

- No php and no composer on the dev machine: verify behavior by reading source and CI YAML; CI and docker are the executors (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli` for tests, `composer:2` for composer).
- Bounded body read: payloads beyond 256 KiB, or signatures split across that boundary, are not detected and reach the wrapped kernel on pass. `MAX_BODY_BYTES` matches the engine's full-scan window; do not change it in isolation.
- Sub-requests are never screened by design. If Symfony renders a fragment whose URL should be guarded, guard the main request that renders it; do not add `$type` branching security logic here.
- Fail-closed is intentional: an engine exception yields `500 Security check failed`, never a pass-through. `customErrorResponses` lives in the engine's `SecurityConfig`, not in the adapter.
- With `redisFailOpen: false` and Redis down, middleware CONSTRUCTION throws `GuardRedisException`, so the failure happens before any request is handled.
- In the test runner, `REDIS_HOST=0` forces integration mode off; otherwise it probes `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379) and prints SKIP if unreachable. CI sets `REDIS_HOST=127.0.0.1` with a `redis:7-alpine` service; local docker uses `host.docker.internal` against the host Redis.
- The adapter adds no security headers or CORS; blocked responses are exact engine translations (for example `403 Forbidden`, `429 Too many requests` with `Retry-After`). Symfony response mechanics put `Date` and a private `Cache-Control` on every response object.
- `config.platform.php: 8.2.0` in composer.json pins dependency RESOLUTION to the lowest supported PHP so one lock installs across the 8.2/8.3/8.4 matrix. Keep it.
- `main` is protected and there are no shipped tags: branch, PR, no direct pushes to `main`, no new tags or releases.

## Related Projects

- `rennf93/guard-core-php`: https://github.com/Guard-Core/guard-core-php. The engine. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there, and every verdict originates there.
- `rennf93/psr15-guard`: https://github.com/Guard-Core/psr15-guard. The PSR-15 sibling adapter; the template the PHP adapters mirror.
- `rennf93/laravel-guard`: https://github.com/Guard-Core/laravel-guard. The Laravel sibling adapter; the newest precedent this repository mirrors.
- `rennf93/symfony-guard`: https://github.com/Guard-Core/symfony-guard. This repository, the Symfony adapter layer of the guard-core ecosystem.
