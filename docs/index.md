# psr15-guard

`symfony-guard` is the official Symfony adapter for
[guard-core-php](https://github.com/Guard-Core/guard-core-php), the PHP port of the
guard-core security engine. It wraps your `HttpKernelInterface` kernel with the
full engine pipeline: penetration detection, rate limiting, IP banning, and
verdict responses translated back to Symfony-native responses.

All security logic lives in the engine; this package is a thin shim that
translates a HttpFoundation `Request` into a `GuardRequest`, runs the engine,
and translates the block verdict back to a `Response` when one arrives.

## Installation

```bash
composer require rennf93/psr15-guard
```

Requires PHP 8.2 or later. Until `rennf93/guard-core-php` has a Packagist
distribution, point composer at its repository and allow dev stability:

```json
{
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        { "type": "vcs", "url": "https://github.com/Guard-Core/guard-core-php" }
    ]
}
```

## Quick start

```php
use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;

$kernel = new GuardMiddleware(
    new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']),
    new GuardEngine(new SecurityConfig(
        enableRedis: true,
        redisPrefix: 'guard_core:',
        rateLimit: 100,
        rateLimitWindow: 60,
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

Blocked requests get the engine's `GuardResponse` translated exactly (status,
body, headers). Passing requests continue down the stack untouched.

## What the middleware handles

- Client identity: `$request->getClientIp()` (Symfony trusted-proxy resolution
  applies), with the engine's own `trustedProxies` available as an alternative;
  pick one side to do the resolving
- Headers: joined into the engine's case-insensitive `HeaderBag`
- Body: the first 256 KiB are shown to the engine
  (`SymfonyGuardRequest::MAX_BODY_BYTES`)
- Main request only: sub-requests pass straight into the wrapped kernel, so
  fragments and forwards are not double-counted by rate limits
- Fail-closed: engine throws become `500` with a fixed, non-leaky message
- Statelessness: the middleware holds no mutable state; PHP shared-nothing
  applies (per request under FPM, per worker under FrankenPHP/RoadRunner/Octane)

See [Usage](usage.md) for the full adapter surface and
[Configuration](configuration.md) for engine tuning. Runnable apps live in the
[examples](https://github.com/Guard-Core/psr15-guard/tree/master/examples)
directory.
