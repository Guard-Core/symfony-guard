Release Notes
=============

___

v1.6.0 (2026-10-16)
-------------------

The response modifier release: the engine floor moves to guard-core-php ^4.4.0, the engine's customResponseModifier reaches pass-through responses, and the agent wiring is documented (v1.6.0)
-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Changed

- **Engine floor raised to guard-core-php ^4.4.0, the shipped Packagist release.** `composer.json` floors `rennf93/guard-core-php` at `^4.4.0` and `composer.lock` resolves it at v4.4.0. `GuardEngine::applyResponseModifier()` and the 4.4.0 config validation are not present in earlier engines.

### Added

- **The engine response modifier runs on pass-through responses.** `finish()` calls `GuardEngine::applyResponseModifier()` between the engine security headers and the CORS verdict headers, so `SecurityConfig::customResponseModifier` now applies to the responses the downstream handler produced, including excluded-path and all-checks-bypass responses, and never twice to a response the engine already modified. Nothing is read from the response while no modifier is configured. `Set-Cookie` is not exposed to the modifier, an unchanged header keeps its original value list, and a body that cannot be read as a string keeps its body (only its status and headers can change). A modifier that throws leaves the response unmodified.
- **Agent and dynamic rule documentation.** The README and `docs/usage.md` describe attaching guard-agent-php (`enableAgent`, `enableDynamicRules`, `dynamicRuleInterval`, `enableEnrichment`) and the lifecycle hooks, and `composer.json` lists `rennf93/guard-agent-php` under `suggest`.

### Behavior to know before upgrading

The engine floor moves to guard-core-php 4.4.0, so these engine changes reach every Symfony install that takes this release. They come from the engine, not from this adapter, and nothing in this adapter's own API or config surface is removed or renamed.

- `enableDynamicRules` and `enableEnrichment` now require `enableAgent`, like the reference `validate_agent_config`. A `SecurityConfig` that sets either without `enableAgent: true` no longer validates and the engine throws when it is built. Add `enableAgent: true` (and pass an agent handler) or drop the option.
- IP list precedence follows the reference. A configured whitelist decides alone and the blacklist applies only when no whitelist is configured. A route whitelist skips the global lists for the request, and the route blacklist is consulted only when the route has no whitelist.

- Client IP canonicalization follows the reference. `X-Forwarded-For` entries lose their port, a mapped peer inside an IPv4 CIDR of `trustedProxies` is no longer trusted, and list matching canonicalizes the entry, so a ban, a rate limit or a list entry can match a client that it did not match before.
- Dynamic rule versions no longer stack. Each new version applies over the base config and no longer carries over the previous version's changes. An `ip_blacklist` dynamic rule is now a timed ban (for `ipBanDuration` seconds) instead of a merge into the static list.

- A new per-request scan budget, `detectionMaxScanSeconds` (default 10.0, range 0.1 to 60.0), caps regex scan CPU time. Once it is spent the remaining patterns are skipped and the request records a weighted `pattern_timeout` threat, so a very expensive request can now be flagged where it was scanned to the end before.
- A passive-mode suspicious log line without a trigger has new wording (`[PASSIVE MODE] Suspicious activity detected from <ip>: ... - Reason: <reason> - Headers: ...`), so update any log match on the old text.

### Verification

- Local gates on php 8.5.11 with a private Redis on port 6390: `composer install`, the php -l sweep, `composer validate --strict` and `composer audit` exit 0, `bin/test_symfony.php` 160/160 checks green (including the modifier assertions), and the 100% line coverage gate passes. `composer.lock` resolves guard-core-php v4.4.0 from packagist.

___

v1.5.0 (2026-10-09)
-------------------

The decorator-seam release: the engine constraint repins to guard-core-php ^4.3.2 plus the decorator handler pass-through, the agent_stats/reset/refreshCloudIpRanges guard surface, and method-scoped route patterns (v1.5.0)
------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------

### Changed

- **Engine constraint repinned to guard-core-php ^4.3.2, the shipped parity release.** `composer.json` floors `rennf93/guard-core-php` at the released `^4.3.2` and `composer.lock` resolves it at v4.3.2 from packagist, replacing the `dev-master as 4.3.99` alias the decorator seam PR (#27) carried as registered debt.

### Added

- **Agent surface (PR #25).** `GuardMiddleware` keeps the injected agent handler and exposes `agentStats()`: `{enabled: false, degraded: false}` without a handler, the handler `getStats()` map merged under `enabled: true` with one (fastapi-guard `middleware.agent_stats` semantics). `reset()` delegates to the engine rate-limit handler's reset (fastapi-guard `middleware.reset`). `refreshCloudIpRanges()` mirrors `middleware.refresh_cloud_ip_ranges`: a no-op while cloud blocking is disabled, a store-backed refreshAsync of the configured providers with the configured ttl otherwise.
- **Method-scoped route patterns (PR #25, the method half of A3).** Route patterns may carry a method prefix ("GET /api/users"): method-scoped patterns match one method, bare patterns match every method, and a method-scoped pattern wins a same-path tie (the method half of the reference `_match_router`).
- **The decorator handler seam (PR #27, the set_decorator_handler analog).** The constructor's `decoratorHandler` parameter accepts the engine decorator family handler (`RenzoFranceschini\GuardCore\Decorators\SecurityDecorator`): its decorated routes (route pattern keys and callable-endpoint route ids) merge under the explicit `$routes` map (an explicit entry wins a shared pattern) and the handler is wired into the engine (`GuardEngine::setDecoratorHandler`) so requests stamped with a route id resolve their RouteConfig through the handler's registry. The routes map is de-promoted to a plain readonly property assigned once in the constructor (the merge happens before the single assignment).
- **Example app resolution (PR #28).** The example roots allow `dev-master` alongside the shipped range for the first-party packages; `prefer-stable` keeps them on released versions now that the engine constraint round-trips.
- **Upstream drift stamp (PR #29).** The daily drift workflow checks out engine master and requires it as `dev-master` (the mounted checkout IS engine master), so the job stays green against the shipped-range adapter constraint.
- **Post-transfer metadata sweep (PR #30).** Repo URLs, the Pages host, the lock's engine source/dist URLs, the drift workflow checkout slug, mkdocs repo_url, FUNDING, the CODE_OF_CONDUCT enforcement contact, and a new `.github/CODEOWNERS` point at the Guard-Core org.

### Verification

- Local gates on php 8.5.11: `composer validate` exit 0, `composer audit --locked` clean, `make lint` exit 0, `bin/test_symfony.php` all checks green with host Redis on 6379 (including the decorator handler and method-scoped pattern sections), `composer.lock` resolving guard-core-php v4.3.2 from packagist. PHPStan level 3 via the `ghcr.io/phpstan/phpstan:latest` image: no errors.

___

v1.4.0 (2026-10-07)
-------------------

The engine-repin release: guard-core-php ^4.3.1 from packagist plus the longest-path route patterns, optional agent wiring and the JSON status route (v1.4.0)
-------------------------------------------------------------------------------------------------------------------------------------------------------------

### Changed

- **Engine constraint repinned to guard-core-php ^4.3.1, the shipped parity release.** `composer.json` floors `rennf93/guard-core-php` at the released `^4.3.1` and `composer.lock` resolves it at v4.3.1 from packagist, replacing the dev-master alias; everything resolves from the registry with no path or VCS repository entries.

### Added

- **FP-PHP parity surface (PR #20).** Route patterns resolve most-specific-first (length-descending), so the longest matching path pattern wins regardless of insertion order, closing the first-match insertion-order divergence with the reference implementations. An optional `agentHandler` constructor argument wires a duck-typed agent (`sendEvent`) into the engine's event bus. `GuardStatusController` serves `GuardEngine::initializationStatus()` as JSON, mirroring fastapi-guard's status route.

### Verification

- Local gates on php 8.5.11: `composer validate` exit 0, `composer audit --locked` clean (zero open advisories), `make lint` exit 0, and `bin/test_symfony.php` 123/123 checks green with host Redis on 6379 (includes the three new parity assertions), with `composer.lock` resolving guard-core-php v4.3.1. PHPStan level 3 via the `ghcr.io/phpstan/phpstan:latest` image: no errors. Dockerized live smoke (examples/simple_app, port 8080): all six workflow assertions green.

___

v1.3.0 (2026-10-01)
-------------------

### Changed

- **Engine floor raised to guard-core-php ^4.3.0, the safety-corpus release.** 1.3.0 floors `rennf93/guard-core-php` to ^4.3.0 and picks up the 4.3.0 engine train: the spec 12 event bus with dynamic rules and Redis-backed metrics persistence, the spec 10 geo download and refresh lifecycle, the spec 04 ReDoS safety gates over PCRE plus the performance monitor, and the pattern_safety / events / redis_interop conformance runners. Nothing in the Symfony surface changes: the middleware is a thin adapter, so the new engine surfaces ride config through the unchanged option map. The CI engine checkout stamps the mounted sibling path checkout at 4.3.99 (ci.yml, release.yml, static-analysis.yml, upstream-drift.yml, scheduled-lint.yml) so the floor resolves against the engine master while keeping the published constraint ^4.3.0, and `composer.lock` resolves the engine at the released v4.3.0.

### Added

- **Process scaffold (PR #16).** `SECURITY.md` (supported versions, advisory reporting), `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `.github/FUNDING.yml`, the PR template and the issue templates, modeled on the Go family and the fastapi-guard baseline. `.github/workflows/static-analysis.yml`: phpstan via the docker pattern these repos already use (composer:2 install with the same ci-only path-repository patch as ci.yml, then `ghcr.io/phpstan/phpstan:latest analyse src --level=3`), on push/pr to master plus the weekly Monday cron; level 3 is the highest level with zero findings on master. `.github/workflows/live-smoke.yml` gains the `live-smoke-advanced` job (the existing simple_app job untouched): a dockerized run of `examples/advanced_app` on host port 8081 asserting `GET /` and `GET /health` 200 plus one blocked-request check (`GET /admin/check?ip=203.0.113.9` without `X-Admin-Token` returns the admin gate's 400 "Missing required header: X-Admin-Token"). CodeQL was drafted and dropped after the first run proved GitHub CodeQL no longer supports PHP (`Did not recognize the following languages: php`); `.github/workflows/semgrep.yml` is the substitute, running the pinned semgrep/semgrep:1.177.0 container with the p/security-audit and p/secrets rulesets over src/ (path-filtered push/PR triggers plus a weekly Monday cron, metrics off).
- **100% line coverage gate (PR #17, following the psr15-guard pilot).** The bespoke `bin/test_symfony.php` suite stays as it is; `.github/coverage-runner.php` (phpunit/php-code-coverage ^11 + pcov) executes the suite under coverage and the new CI coverage job gates on 100.00% measured lines in src/. The suite already measured 100.00% lines locally (pcov, php 8.3), with and without a reachable redis, so no test changes were needed; the job mirrors ci.yml's path-repo sibling pattern (php 8.3, coverage: pcov) and suite pass/fail stays gated by the existing test job.

### Verification

- `make lint` exit 0 and `make test` exit 0 on php 8.5.11 (host Redis on 6379, 120/120 checks green), with `composer.lock` resolving guard-core-php v4.3.0.

___

v1.2.0 (2026-09-27)
-------------------

### Changed

- **Engine floor raised to guard-core-php ^4.2.0, the parity release.** The 4.1.0 family tags were a version-accuracy error and were yanked/unpublished, so the published ^4.1.0 constraint of v1.1.0 does not resolve publicly; 1.2.0 floors `rennf93/guard-core-php` to ^4.2.0 and picks up the 4.2.0 engine train: the on_block hook payloads now carry the reference log-format reasons, route-level IP rules (`RouteConfig` ipWhitelist/ipBlacklist/blockedCountries/whitelistCountries) are enforced, and the spec 4.1.0 corpus runs with an empty divergence registry (219 cases, pipeline gate 35 passed / 0 failed / 0 xfail). The CI engine checkout stamps the mounted sibling path checkout at 4.2.99 so the floor resolves against the engine master while keeping the published constraint ^4.2.0.

v1.1.0 (unreleased)
-------------------

### Added

- **Parity pass-through surface.** `GuardMiddleware` now finishes pass-through responses the way the reference response factory does: the engine's security headers (`securityHeaders`) and CORS verdict headers (`enableCors`, `corsAllow*`) are merged onto the kernel's response, and the engine's behavioral return rules observe the response status plus a body prefix bounded by `behavior_max_response_body_inspect_bytes` (scanned only while `behavior_scan_response_body` is on), so `globalBehaviorRules` and route `behaviorRules` with `return_pattern` rules act on what the application actually served.
- **Per-route configuration.** The middleware takes `routes` (path pattern to `RouteConfig`, exact or trailing-slash prefix match) and an optional `routeResolver` closure receiving the raw Symfony request, attaching per-route behavior rules, detection exclusions, rate-limit tiers and check bypasses to the engine's request state.
- **Geo rate-limit resolver.** A `geoRateLimitResolver` option injects the country resolver that powers `RouteConfig` `geoRateLimits` tiers; when absent, the middleware bridges the engine config's `geo_ip_handler` (kept by the engine only when country lists are configured).
- Reachability tests for every new surface in `bin/test_symfony.php` (116 checks green) and pass-through/route/geo documentation in the README and `docs/configuration.md`.

___

v1.0.0 (2026-09-24)
-------------------

First stable release (v1.0.0)
-----------------------------

### Added

- **Symfony middleware adapter for guard-core-php 4.0.4.** `GuardMiddleware` maps Symfony `HttpFoundation` Request objects to the guard-core engine (`RenzoFranceschini\GuardCore\Engine\GuardEngine`) and translates block verdicts back to Symfony-native responses, for Symfony 6.4 LTS and 7.x. It wraps the kernel at the front controller or wherever the kernel is assembled.
- **Exact block translation.** Blocked requests get the engine's verdict translated status-for-status, body-for-body and header-for-header into a Symfony response; passing requests continue into the kernel untouched.
- **Fail-closed behavior.** If the engine throws, the request is answered with the engine's fail-closed response instead of being let through.
- **Distributed state via Redis.** Distributed rate limits, IP bans, and cloud-range caches require Redis (`enableRedis: true`); `redisFailOpen: true` keeps serving when Redis is unreachable, `false` fails closed.

### Changed

- **The engine dependency is pinned to `rennf93/guard-core-php` `^4.0.4`**, the first stable engine release, replacing the `^0.1.0` pin to the burned pre-release snapshot tag.

### Internal (v1.0.0)

- Unit and Redis-backed integration suites for the bridging layer in `bin/test_symfony.php` (Redis-backed shared-state cases run when `REDIS_HOST` points at a reachable Redis), plus a `php -l` sweep and `composer audit` in CI across PHP 8.2, 8.3 and 8.4.
- Community workflows, a MkDocs documentation site, and example apps landed via the parity-polish pass.

___
