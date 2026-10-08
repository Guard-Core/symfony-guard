# symfony-guard advanced app

Production-shaped guarded app: env-driven `SecurityConfig` engine tuning
(`src/Config.php`), a kernel whose admin routes drive the engine's ban manager
(`src/Routes.php`), an admin gate enforced by the engine pipeline, a
per-endpoint rate limit, and a non-root multi-stage Docker build. It is the
image published to `ghcr.io/guard-core/symfony-guard-example` by the repo's
`container-release` workflow.

Layout:

- `public/index.php`: app assembly (engine, guard, front-controller sequence)
- `src/Config.php`: env-driven `SecurityConfig` plus the admin gate closure
- `src/Routes.php`: guarded HttpKernelInterface router

## Run

```bash
docker compose -f examples/advanced_app/docker-compose.yml up --build -d --wait
curl -i http://localhost:8081/
docker compose -f examples/advanced_app/docker-compose.yml down -v
```

The engine runs with Redis enabled (`REDIS_HOST=redis`, `REDIS_PORT=6379`);
distributed rate limits and bans are shared state.

## Routes

| Route | Behavior |
|---|---|
| `GET /` | Returns `200 ok` |
| `GET /health` | Returns `200 healthy`; engine `excludePaths` makes it bypass the guard entirely |
| `GET /search?q=` | Echoes `q` (HTML-escaped); XSS payloads are blocked with `400 Suspicious activity detected` |
| `GET /rate/burst` | Endpoint rate limit of 3 requests per 60 seconds (`endpointRateLimits`); excess gets `429 Too many requests` |
| `GET /admin/ban?ip=&duration=` | Bans the IP through `GuardEngine::banManager()->ban()`; requires `X-Admin-Token` |
| `GET /admin/unban?ip=` | Lifts a ban through `GuardEngine::banManager()->unban()`; requires `X-Admin-Token` |
| `GET /admin/check?ip=` | Reports `GuardEngine::banManager()->isIpBanned()`; requires `X-Admin-Token` |

## The admin gate (required headers, adapter equivalent)

The engine's route-scoped guard (`RouteConfig.requiredHeaders`) is keyed off a
route ID on the request state, and the adapter builds its engine request
internally, so there is no route-ID hook to reach it. The engine-sanctioned
equivalent used here is the `custom_request` pipeline check: a
`SecurityConfig.customRequestCheck` closure that inspects the `GuardRequest`
and returns a block verdict for `/admin/*` requests missing the right
`X-Admin-Token` header:

- `400 Missing required header: X-Admin-Token` without the header
- `200` with the header

It runs as the last engine check, after IP bans, rate limits, and detection,
so a banned client cannot reach the admin routes regardless of the gate.

## Configuration (environment)

| Variable | Default | Purpose |
|---|---|---|
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | Engine Redis connection (read by the engine itself) |
| `REDIS_PREFIX` | `guard_core_symfony:` | Key namespace for bans and rate limits |
| `TRUSTED_PROXIES` | `172.16.0.0/12,10.0.0.0/8,192.168.65.0/24` | CIDRs trusted for `X-Forwarded-For` resolution (standard docker bridge gateway plus the Docker Desktop gateway) |
| `TRUSTED_PROXY_DEPTH` | `1` | Trusted proxy hops |
| `ADMIN_TOKEN` | `admin-token-change-me` | Expected `X-Admin-Token` value |
| `RATE_LIMIT` / `RATE_LIMIT_WINDOW` | `30` / `60` | Global rate limit |
| `BURST_LIMIT` / `BURST_WINDOW` | `3` / `60` | `/rate/burst` endpoint limit |
| `AUTO_BAN_THRESHOLD` / `AUTO_BAN_DURATION` | `5` / `300` | Auto-ban tuning |

## What the compose smoke asserts

Bring the stack up as above, then:

1. `GET /` serves `200`; `GET /health` (excluded path) serves `200`
2. `GET /admin/ban?ip=203.0.113.7` without `X-Admin-Token` is blocked with `400` and body `Missing required header: X-Admin-Token`
3. The same call with the token bans the IP; `GET /admin/check?ip=203.0.113.7` reports `{"banned": true}`
4. `GET /` with `X-Forwarded-For: 203.0.113.7` (trusted compose network resolves it) is blocked `403` with body `Blocked by symfony-guard`
5. `GET /admin/unban?ip=203.0.113.7` lifts the ban; the request serves `200` again
6. The 4th `GET /rate/burst` is blocked `429` with body `Too many requests`
7. XSS query param (`?q=<script>alert(1)</script>`) is blocked `400` with body `Suspicious activity detected`

## Serving choice

The runtime image is `php:8.3-cli-alpine` (non-root `guard` user) serving
through the PHP built-in webserver with the front controller as router script:
every request, including 404s, passes through the guard. The built-in server
is single-process and meant for demos; production deployments should use
FrankenPHP, FPM behind nginx, or a long-running worker runtime (Swoole,
RoadRunner, Octane) and construct the engine per request (FPM) or per worker
(long-running runtimes).
