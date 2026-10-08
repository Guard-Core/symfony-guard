# AGENTS.md
Guidance for AI agents (including Claude Code) working in this repository.

## Project Overview

rennf93/symfony-guard (https://github.com/Guard-Core/symfony-guard) is a Symfony middleware adapter for guard-core-php. It maps `Symfony\Component\HttpFoundation\Request` objects into the guard-core engine and translates the engine's block verdicts back to Symfony-native responses. It works with Symfony 6.4 LTS and 7.x.

- Composer package `rennf93/symfony-guard`, type `library`, license MIT. No `version` field in composer.json (the psr15-guard and laravel-guard convention); versions come from git tags, of which there are none, so composer installs it as `dev-main`.
- This repository contains NO security logic. Detection, rate limiting, bans, and verdicts all live in guard-core-php.
- PHP `^8.2`. Autoload is PSR-4: `RenzoFranceschini\GuardCoreSymfony\` maps to `src/`.
- Shipped tags: none. There are no git tags and no releases. `main` is protected: never push to it, never merge into it, never create tags or releases.

## Ecosystem Position

- `rennf93/guard-core-php` (https://github.com/Guard-Core/guard-core-php) is the engine. It owns `SecurityConfig`, `GuardEngine`, the `GuardRequest`/`GuardResponse` contracts, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException`. Every check, verdict, and block response body originates there.
- This package is the Symfony adapter for that engine: `GuardMiddleware` decorates an `HttpKernelInterface` and runs each main request through `GuardEngine::execute()`, `SymfonyGuardRequest` implements the core `GuardRequest` contract over `Symfony\Component\HttpFoundation\Request`, and `ResponseTranslator` converts `GuardResponse` objects to `Symfony\Component\HttpFoundation\Response`.
- Composer constraint: `rennf93/guard-core-php: ^0.1.0` (composer.json `require`). `composer.lock` pins `v0.1.0` (dist zipball fetched from the GitHub VCS repository).
- Repository configuration in composer.json, in order:
  1. Path repository `../guard-core-php`, marked `"canonical": false` (resolves when a sibling checkout of the core exists; non-canonical, so other sources win on conflict).
  2. VCS fallback `https://github.com/Guard-Core/guard-core-php.git` (how fresh checkouts and CI actually resolve the core).
- `minimum-stability: dev` with `prefer-stable: true`, required until the core has a Packagist distribution. The README documents the same setup for consumers of this package.
- `config.platform.php: 8.2.0` pins dependency RESOLUTION to the lowest supported PHP. Without it, composer running on PHP 8.4 resolves the transitive symfony packages (error-handler, event-dispatcher, var-dumper) into their 8.x lines, which require PHP >= 8.4.1 and make the lock uninstallable on the 8.2/8.3 matrix legs. The pin makes one lock installable across 8.2-8.4. Keep it.

## Boundary Rules

The adapter adapts; the engine decides.

- MUST NOT implement detection rules, penetration signatures, rate limiting, IP blacklisting or whitelisting, banning, security headers, or CORS. None of that exists in `src/` and none may be added. README: "No security headers or CORS are added by this adapter." (Symfony response mechanics put a `Date` and a private `Cache-Control` on every response object; the engine's own headers are copied exactly.)
- MUST depend on guard-core-php for every verdict. `GuardMiddleware::handle()` calls `GuardEngine::execute()` and acts only on its return value (`null` means pass, a `GuardResponse` means block).
- MUST stay Symfony-specific and engine-only. `src/` imports only `Symfony\Component\HttpFoundation\*`, `Symfony\Component\HttpKernel\*`, and `RenzoFranceschini\GuardCore\*` classes. Any other import family is a boundary violation.
- MUST stay fail-closed: if the engine throws, `handle()` returns the engine's fail-closed response (`500 Security check failed`, overridable through the engine's `customErrorResponses`), never the wrapped kernel.
- MUST keep the middleware stateless: it holds no mutable state. PHP shared-nothing applies (per request under classic FPM, per worker under long-running runtimes); distributed rate limits, bans, and cloud-range caches require Redis.
- MUST NOT widen the bounded body read: `SymfonyGuardRequest::MAX_BODY_BYTES = 262144` (256 KiB) matches the engine's full-scan window. The framework materializes the full body in memory (php://input); the engine only ever sees the capped prefix. Payloads beyond the prefix, or signatures split across its boundary, are not detected; that tradeoff is deliberate and engine-coupled.
- MUST NOT add a Symfony Bundle, compiler pass, extension, or publishable config file: configuration is constructor options only (the consumer builds a `SecurityConfig` and a `GuardEngine` and wraps their kernel themselves). That keeps symfony/dependency-injection and symfony/framework-bundle out of the dependency tree.

Main-request scoping is wiring policy, not security logic: `handle()` screens only `HttpKernelInterface::MAIN_REQUEST` calls and forwards sub-requests straight into the wrapped kernel. Sub-requests (forwards, fragments, error renders) are internal and derive from a main request that was already screened; re-screening them would double-count rate-limit hits. External traffic can never reach the kernel as a sub-request.

The real adapter surface (all of `src/`, three final classes in namespace `RenzoFranceschini\GuardCoreSymfony`):

- `GuardMiddleware`. Implements `HttpKernelInterface` (decorator) and `TerminableInterface`. Constructor takes the wrapped `HttpKernelInterface $kernel` and `GuardEngine $engine`. The constructor calls `$engine->initialize()`; a `GuardRedisException` is swallowed only when `$engine->config()->redisFailOpen` is true, otherwise construction fails closed by rethrowing. `handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response` screens main requests (wrapping in `SymfonyGuardRequest`, running the engine, translating block verdicts via `ResponseTranslator`) and forwards everything else to `$this->kernel->handle($request, $type, $catch)`. `terminate()` forwards to the wrapped kernel when it is terminable and is a silent no-op otherwise. There is no PSR-7 in this adapter and no bridge is needed: like psr15-guard and laravel-guard, it implements the core's own `GuardRequest` contract directly over the framework request object, calling the engine pipeline directly. That is why psr/http-message and a Symfony-to-PSR-7 conversion are not dependencies.
- `SymfonyGuardRequest implements RenzoFranceschini\GuardCore\Request\GuardRequest`. Adapts URL path from `getPathInfo()` (empty string becomes `/`), scheme from `getScheme()`, full URL as scheme://`getHttpHost()` plus path plus query when non-empty (Symfony `getQueryString()` is normalized, so query order is canonical), and a pure `urlReplaceScheme()`; upper-cased method; client host from `getClientIp()` (null when missing or empty); headers into a `HeaderBag` with multi-value lines joined as `a, b`; query params passed through from the query bag; and the body as a cached, bounded prefix with `bodyWasTruncated()`. Each instance carries its own `RequestState`.
- `ResponseTranslator`. `translate(GuardResponse): Symfony\Component\HttpFoundation\Response` copies status code, body (null becomes an empty string), and all headers onto the Symfony response. Exact translation, no additions; `prepare()` is never called, so no Content-Type/charset is synthesized.

Client address semantics (deliberate, documented in the README): `clientHost()` maps onto `$request->getClientIp()`. Without Symfony trusted proxies configured that is the connecting `REMOTE_ADDR`, and the engine's own `trusted_proxies` / `X-Forwarded-For` resolution applies. With Symfony trusted proxies configured (framework.yaml `trusted_proxies` / `trusted_headers`), Symfony resolves the forwarded chain first and the engine sees the resolved client. Pick one side to do the resolving; configuring both can double-hop.

## Quick Start

This machine has NO `php` and NO `composer` binary. Run everything through Docker (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli` for tests, `composer:2` for composer), or let CI execute.

Local install path (per composer.json):

1. Make guard-core-php resolvable: either check out the core at a sibling directory `../guard-core-php` (the path repository) or rely on the VCS fallback `https://github.com/Guard-Core/guard-core-php.git`.
2. `composer install` (the lock already pins guard-core-php v0.1.0).
3. `composer lint`, then `composer test`.

Docker path used during development (host bind-mounts the ZZZ workspace so the sibling path repository resolves at identical host paths; unit-only variant sets `REDIS_HOST=0`, the full variant runs the Redis integration against the host Redis on 6379 via host.docker.internal):

```
docker run --rm -v <workspace>:/work -w /work/symfony-guard -e REDIS_HOST=0 php:8.3-cli php bin/test_symfony.php
docker run --rm -v <workspace>:/work -w /work/symfony-guard -e REDIS_HOST=host.docker.internal php:8.3-cli php bin/test_symfony.php
docker run --rm -v <workspace>:/work -w /work/symfony-guard composer:2 composer install --no-interaction --no-progress
```

The CI-verified path (copy this when describing a working environment; from `.github/workflows/ci.yml`):

1. Checkout this repo.
2. Checkout `rennf93/guard-core-php` at ref `guard-core-port-php` into `core-checkout`, then `mv core-checkout ../guard-core-php` so the path repository resolves.
3. Set up PHP from the matrix (8.2, 8.3, or 8.4) with the `mbstring` extension, coverage none.
4. `composer install --no-interaction --no-progress`, then `composer update rennf93/guard-core-php --no-interaction`.
5. Run the php -l sweep, then `REDIS_HOST=127.0.0.1 php bin/test_symfony.php` against a `redis:7-alpine` service on port 6379.

## Development Commands

Composer scripts (composer.json `scripts`; these are the only two):

| Command | What it runs |
| --- | --- |
| `composer test` | `php bin/test_symfony.php` |
| `composer lint` | `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` |

Direct commands used by CI (verified in `.github/workflows/ci.yml`; the same install, lint, and test steps appear in `release.yml` and `scheduled-lint.yml`):

- `composer install --no-interaction --no-progress`
- `composer update rennf93/guard-core-php --no-interaction` (CI deliberately refreshes the core dependency on every run)
- `for f in $(find src bin -name '*.php'); do php -l "$f" > /dev/null || exit 1; done && echo LINT_OK` (same sweep as `composer lint`)
- `php bin/test_symfony.php` with env `REDIS_HOST=127.0.0.1`
- `composer audit` (Composer audit job, PHP 8.3)
- `composer validate --strict` must stay clean (run it after any composer.json edit)

`bin/` contains exactly one script: `bin/test_symfony.php` (the whole test suite, plain PHP, no PHPUnit). There is no Makefile, no PHPUnit config, no PHPStan, no PHP-CS-Fixer, and no docker setup in this repo.

## Project Structure

```
.github/dependabot.yml                Weekly dependabot: github-actions + composer (grouped)
.github/workflows/ci.yml              CI: test matrix php 8.2/8.3/8.4 + redis service + composer audit
.github/workflows/release.yml         Release Gate: same suite, runs on v* tags
.github/workflows/scheduled-lint.yml  Weekly cron (Mon 04:00 UTC): php -l sweep + composer audit
bin/test_symfony.php                  Entire test suite, plain PHP runner with a T assertion harness
composer.json                         Package metadata, autoload, scripts, repositories, platform pin
composer.lock                         Locked deps; tracked; regenerate only deliberately
src/GuardMiddleware.php               Symfony kernel middleware (HttpKernelInterface decorator + terminate)
src/SymfonyGuardRequest.php           HttpFoundation Request to GuardRequest adapter
src/ResponseTranslator.php            GuardResponse to Symfony Response translator
src/.agents/skills/symfony-guard/     Package skill (SKILL.md)
LICENSE                               MIT, (c) 2026 Renzo Franceschini
README.md                             Install, usage, lifecycle, behavior notes
AGENTS.md / CLAUDE.md                 Agent guide (byte-identical copies)
```

- `src/` is the PSR-4 package root for `RenzoFranceschini\GuardCoreSymfony\`. It is flat: one final class per file, class name equals file name, no subdirectories. A fourth class would be a real surface change: update the README behavior notes in the same PR.
- `vendor/` exists on disk but is gitignored. Never `git add` it. `composer.lock` is tracked; never modify it casually.

## Technology Stack

- PHP `^8.2` (CI matrix: 8.2, 8.3, 8.4; the audit and scheduled-lint jobs run on 8.3).
- Runtime deps (composer.json `require`, with composer.lock versions):
  - `rennf93/guard-core-php ^0.1.0` (locked v0.1.0)
  - `symfony/http-foundation ^6.4|^7.0` (locked v7.4.19; Symfony 6.4 LTS and 7.x supported)
  - `symfony/http-kernel ^6.4|^7.0` (locked v7.4.19; a direct dependency because the middleware implements `HttpKernelInterface` and `TerminableInterface`, which live there)
- `symfony/event-dispatcher` is deliberately NOT a direct dependency: nothing in `src/` imports it (it arrives transitively through http-kernel). Same for symfony/dependency-injection and symfony/framework-bundle: no Bundle, no DI extension.
- `config.platform.php: 8.2.0` in composer.json (see Ecosystem Position for why).
- No require-dev packages: the test suite needs nothing beyond the runtime deps (Symfony requests are built with `Symfony\Component\HttpFoundation\Request::create`).
- CI runs a `redis:7-alpine` service container on port 6379 with health checks for the Redis integration tests.
- Actions are pinned by commit SHA: `actions/checkout` v7.0.1 and `shivammathur/setup-php` 2.37.2.

## Testing Guidelines

- Run with `composer test` (or `php bin/test_symfony.php`). Exit code 0 means green, 1 means red. Output: `ok - <label>` or `FAIL - <label>` per assertion, `=== section ===` headers, and a final `Passed: N, Failed: N` plus `N/N GREEN` or `N/N RED`.
- Redis integration: the runner attempts a socket connection to `REDIS_HOST` (default 127.0.0.1) and `REDIS_PORT` (default 6379). Set `REDIS_HOST=0` to force integration off; if no Redis is reachable it prints a SKIP line and unit coverage stands. CI sets `REDIS_HOST=127.0.0.1` against the redis service. Local docker uses `REDIS_HOST=host.docker.internal` against the host Redis. Integration uses a random `REDIS_PREFIX=guard_core_symfony:<hex>:` and cleans keys before and after.
- Coverage areas (section names in `bin/test_symfony.php`): Symfony to GuardRequest translation (URL parts, upper-cased method, `getClientIp()` missing or empty, header joining and case-insensitive lookup, query passthrough, body caching, per-instance state); bounded body read at exactly 262144 bytes (boundary, one byte over, truncation flag); block verdicts through the middleware (403 Forbidden for a blacklisted IP with the on_block hook payload and a Symfony-native response, 301 https enforcement with a Location header, 429 Too many requests with Retry-After: 60, 400 Suspicious activity detected from query or POST body); pass-through (the wrapped kernel called exactly once with the original Symfony request and its response returned unchanged); oversize bodies (payload beyond the cap never reaches the engine); whitelist; exclusion paths (including the favicon.ico default); passive mode; sub-requests passing through unscreened (and the same IP still blocked on the main request); terminate forwarding (terminable kernel receives it, non-terminable kernel is a silent no-op); fail-closed on engine malfunction (500 Security check failed, customErrorResponses override, check-exception fail-secure); and Redis fail-open versus fail-closed construction; plus the Redis integration sections (shared rate-limit bucket across two engine instances, ban written by engine A blocking engine B).
- Any new engine behavior that flows through the adapter needs assertions here before the PR lands.
- The host machine cannot run the suite directly (no php binary); docker (`php:8.2-cli` / `php:8.3-cli` / `php:8.4-cli`) and CI are the executors.

## Code Quality Standards

- `declare(strict_types=1);` at the top of every PHP file in `src/` and `bin/`.
- `final` classes, `private readonly` promoted constructor properties, 4-space indentation, one class per file named after the class.
- Imports in `src/` are limited to `Symfony\Component\HttpFoundation\*`, `Symfony\Component\HttpKernel\*`, and `RenzoFranceschini\GuardCore\*`. A new import outside those families is a boundary violation (see Boundary Rules).
- PHP 8.4-only syntax is forbidden (for example `new Foo()->bar()` without parentheses); the supported floor is 8.2.
- The php -l sweep over `src` and `bin` must pass; `composer lint` prints `LINT_OK` on success.
- Conventional commits are the house style (see `git log` of the sibling repos): `feat:`, `fix:`, `fix(deps):`, `ci:`, `chore:`, `chore(composer):`, `test:`, `docs:`. Lowercase, imperative, no attribution trailers.
- Dependabot keeps github-actions and composer dependencies fresh weekly (composer updates are grouped into one PR).

## Best Practices

- `main` is protected. Work on a branch, push, open a PR (draft PRs are fine). Never push to `main`, never tag, never publish a release as part of agent work.
- Never `git add vendor/`, `.DS_Store`, or any stray file. Stage explicit paths only.
- Keep the adapter thin. If a change adds detection, verdict logic, or response shaping beyond translation, it belongs in guard-core-php, not here.
- Configuration is constructor options: consumers build the `SecurityConfig` and `GuardEngine` themselves and wrap their kernel (`new GuardMiddleware($kernel, $engine)`). Do not add a Bundle, DI extension, or a config-array-to-SecurityConfig mapper; that is a parallel config surface.
- Preserve fail-closed semantics: never let an engine exception fall through to the wrapped kernel.
- CI checks out the core at branch `guard-core-port-php` into `../guard-core-php` so the path repository resolves, and `composer update rennf93/guard-core-php` floats within the `^0.1.0` constraint. The composer.json constraint (`^0.1.0`, tagged v0.1.0) is the source of truth for the dependency.
- Update README.md behavior notes in the same PR whenever adapter behavior changes (for example the main-request scoping note when the sub-request policy changes).

## Related Projects

- `rennf93/guard-core-php`: https://github.com/Guard-Core/guard-core-php. The engine this adapter delegates to. `SecurityConfig`, `GuardEngine`, `GuardRequest`/`GuardResponse`, `HeaderBag`, `RequestState`, `RedisHandler`, and `GuardRedisException` live there. Resolved via the `../guard-core-php` path repository (canonical: false) with the VCS fallback `https://github.com/Guard-Core/guard-core-php.git`; CI checks out its `guard-core-port-php` branch.
- `rennf93/psr15-guard`: https://github.com/Guard-Core/psr15-guard. The PSR-15 sibling adapter; the template the PHP adapters mirror.
- `rennf93/laravel-guard`: https://github.com/Guard-Core/laravel-guard. The Laravel sibling adapter; the newest precedent this repository mirrors (docs trio, CI shape, plain-PHP runner).
- `rennf93/symfony-guard`: this repository, the Symfony adapter layer of the guard-core ecosystem.
