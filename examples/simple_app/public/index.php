<?php

declare(strict_types=1);

use RenzoFranceschini\GuardCore\Config\SecurityConfig;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCoreSymfony\GuardMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/../vendor/autoload.php';

/**
 * Minimal guarded app. The router is a small anonymous HttpKernelInterface;
 * every main request passes through GuardMiddleware (the symfony-guard adapter
 * for guard-core-php) before the kernel sees it. The HttpKernel components are
 * used as a minimal bootstrap (no full framework skeleton): the wrapped kernel
 * pattern is exactly how the adapter is used around a real App\Kernel.
 */
final readonly class App implements Symfony\Component\HttpKernel\HttpKernelInterface
{
    public function handle(Request $request, int $type = Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        return match ($request->getPathInfo()) {
            '/', '' => new Response('ok'),
            '/health' => new Response('healthy'),
            '/rate/strict' => new Response('strict ok'),
            '/search' => new Response(
                'search: ' . htmlspecialchars((string) ($request->query->get('q', '')), ENT_QUOTES)
            ),
            default => new Response('not found', 404),
        };
    }
}

$kernel = new GuardMiddleware(new App(), new GuardEngine(new SecurityConfig(
    enableRedis: true,
    redisPrefix: getenv('REDIS_PREFIX') ?: 'guard_core_symfony:',
    redisFailOpen: true,
    enableRateLimiting: true,
    rateLimit: 100,
    rateLimitWindow: 60,
    endpointRateLimits: [
        '/rate/strict' => ['limit' => 1, 'window' => 10],
    ],
    enableIpBanning: true,
    autoBanThreshold: 5,
    autoBanDuration: 300,
    // The engine's strike counter for auto-ban lives in memory per engine
    // instance, so under PHP shared-nothing a repeated-violation auto-ban
    // never accumulates across requests. The demo pins the xss threat ban at
    // threshold 1 instead: the first XSS payload trips the ban, and the ban
    // itself is Redis-backed and deterministic across requests.
    threatBanConfig: ['xss' => ['threshold' => 1, 'duration' => 300]],
    customErrorResponses: [403 => 'Blocked by symfony-guard'],
    excludePaths: ['/health'],
)));

$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
