<?php

declare(strict_types=1);

namespace App;

use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Request\GuardResponseFactory;

/**
 * Env-driven engine configuration. Every knob the demo tunes is an
 * environment variable; values mirror the compose file defaults.
 */
final class Config
{
    public static function securityConfig(): SecurityConfig
    {
        return new SecurityConfig(
            enableRedis: true,
            redisPrefix: getenv('REDIS_PREFIX') ?: 'guard_core_symfony:',
            redisFailOpen: true,
            trustedProxies: self::csv('TRUSTED_PROXIES'),
            trustedProxyDepth: (int) (getenv('TRUSTED_PROXY_DEPTH') ?: 1),
            enableRateLimiting: true,
            rateLimit: (int) (getenv('RATE_LIMIT') ?: 30),
            rateLimitWindow: (int) (getenv('RATE_LIMIT_WINDOW') ?: 60),
            endpointRateLimits: [
                '/rate/burst' => [
                    'limit' => (int) (getenv('BURST_LIMIT') ?: 3),
                    'window' => (int) (getenv('BURST_WINDOW') ?: 60),
                ],
            ],
            enableIpBanning: true,
            autoBanThreshold: (int) (getenv('AUTO_BAN_THRESHOLD') ?: 5),
            autoBanDuration: (int) (getenv('AUTO_BAN_DURATION') ?: 300),
            customErrorResponses: [403 => 'Blocked by symfony-guard'],
            excludePaths: ['/health'],
            customRequestCheck: self::adminGate(),
        );
    }

    /**
     * The adapter builds its engine request internally, so the engine's
     * RouteConfig.requiredHeaders route guard is not reachable through
     * GuardMiddleware. The engine-sanctioned equivalent is the
     * custom_request pipeline check: a closure over the GuardRequest that
     * returns a block verdict for requests that fail the admin gate.
     */
    private static function adminGate(): \Closure
    {
        $token = getenv('ADMIN_TOKEN') ?: 'admin-token-change-me';

        return static function (object $request) use ($token): ?object {
            if (!str_starts_with($request->urlPath(), '/admin')) {
                return null;
            }
            if ($request->headers()->get('x-admin-token') === $token) {
                return null;
            }

            return (new GuardResponseFactory())
                ->createResponse('Missing required header: X-Admin-Token', 400);
        };
    }

    /** @return list<string> */
    private static function csv(string $name): array
    {
        $raw = getenv($name);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
