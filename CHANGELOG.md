Release Notes
=============

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
