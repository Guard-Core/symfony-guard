# Usage

## Constructor

```php
final class GuardMiddleware implements HttpKernelInterface, TerminableInterface
{
    public function __construct(
        HttpKernelInterface $kernel,
        GuardEngine $engine
    ) { ... }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response;
    public function terminate(Request $request, Response $response): void;
}
```

`GuardMiddleware` decorates the kernel: construct it wherever your
`App\Kernel` is assembled (front controller or container). The constructor
calls `$engine->initialize()`; a `GuardRedisException` is swallowed only when
`SecurityConfig.redisFailOpen` is true, otherwise construction fails closed by
rethrowing. Block verdicts are translated into a Symfony `Response` with the
verdict status, body, and headers.

## Attachment

```php
// public/index.php
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
```

The middleware screens the MAIN kernel request only and passes sub-requests
straight into the wrapped kernel; sub-requests are derived from a main request
that was already screened, and screening them again would double-count
rate-limit hits. On a clean pass the original `Request` is forwarded to the
kernel untouched.

## Verdicts

When the engine returns a block verdict, `ResponseTranslator` copies the
verdict status code, body, and headers onto a PSR-7 response, and never calls
the wrapped handler:

| Situation | Status | Body |
|---|---|---|
| Banned IP | 403 | `IP address banned` |
| Suspicious content | 400 | `Suspicious activity detected` |
| Rate limit exceeded | 429 | `Too many requests` plus `Retry-After` |
| Engine malfunction | 500 | `Security check failed` |

Bodies can be overridden globally through `SecurityConfig.customErrorResponses`.

## Fail-closed behavior

If the engine check throws, the middleware catches it, and responds with the
engine's fail-closed response (`500 Security check failed`) rather than letting
the request through. A custom 500 body comes from
`customErrorResponses[500]`, not from adapter code.

## Route-scoped configuration

The engine supports per-route `RouteConfig` (required headers, per-route rate
limits, bypassed checks) keyed by a route ID on the request state. This adapter
builds the engine request internally, so there is no route-ID hook; the
engine-sanctioned equivalent is the `customRequestCheck` config closure, which
runs as the last pipeline check and can return a block verdict for any request
shape. See the [advanced example app](https://github.com/Guard-Core/psr15-guard/tree/master/examples/advanced_app)
for an admin gate built that way.

## Testing your integration

The package ships its own suite (`composer test`); for your app, assert that a
blacklisted IP gets a 403 with the engine body and that clean requests reach
your handler with the original PSR-7 instance.
