<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCoreSymfony;

use Closure;
use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use RenzoFranceschini\GuardCore\GeoIp\CountryResolver;
use RenzoFranceschini\GuardCore\Request\GuardResponse;
use RenzoFranceschini\GuardCore\Redis\GuardRedisException;
use RenzoFranceschini\GuardCore\Routing\RouteConfig;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

final class GuardMiddleware implements HttpKernelInterface, TerminableInterface
{
    private readonly ResponseTranslator $translator;

    /** @var array<string, RouteConfig> route patterns sorted most-specific-first */
    private array $sortedRoutes = [];

    /**
     * @param array<string, RouteConfig> $routes route pattern => config,
     *     resolved per request by path match and attached to the engine's
     *     request state before the pipeline runs (per-route behavior rules,
     *     detection exclusions, rate-limit tiers and check bypasses)
     * @param (\Closure(Request): RouteConfig|null)|null $routeResolver
     *     custom resolver; takes precedence over the static pattern map and
     *     receives the raw Symfony request
     * @param CountryResolver|null $geoRateLimitResolver country resolver for
     *     RouteConfig geoRateLimits tiers; falls back to the engine
     *     config's geo_ip_handler when that carries one (the config keeps
     *     an injected handler only when country lists are configured)
     */
    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly GuardEngine $engine,
        private readonly array $routes = [],
        private readonly ?Closure $routeResolver = null,
        ?CountryResolver $geoRateLimitResolver = null,
        ?object $agentHandler = null
    ) {
        $this->translator = new ResponseTranslator();
        $this->sortedRoutes = self::sortRoutesLongestFirst($this->routes);
        $this->wireGeoRateLimitResolver($geoRateLimitResolver);
        if ($agentHandler !== null) {
            $engine->setAgentHandler($agentHandler);
        }
        try {
            $engine->initialize();
        } catch (GuardRedisException $e) {
            if (!$engine->config()->redisFailOpen) {
                throw $e;
            }
        }
    }

    public function handle(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST, bool $catch = true): Response
    {
        if ($type !== HttpKernelInterface::MAIN_REQUEST) {
            return $this->kernel->handle($request, $type, $catch);
        }

        $guardRequest = new SymfonyGuardRequest($request);
        $this->attachRouteConfig($guardRequest);

        try {
            $blocked = $this->engine->execute($guardRequest);
        } catch (\Throwable) {
            return $this->translator->translate($this->engine->failClosedResponse());
        }

        if ($blocked !== null) {
            return $this->translator->translate($blocked);
        }

        return $this->finish($guardRequest, $this->kernel->handle($request, $type, $catch));
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->kernel instanceof TerminableInterface) {
            $this->kernel->terminate($request, $response);
        }
    }

    /**
     * Pass-through response finish, mirroring the reference response
     * factory's process_response phase (guard_core/core/responses/
     * factory.py): the behavioral return rules run against the status code
     * and the leading body prefix the kernel produced (body captured only
     * while behavior_scan_response_body is on, bounded by
     * behavior_max_response_body_inspect_bytes; return rules never modify
     * the response), then the engine's security-header set and the CORS
     * verdict headers are merged onto the outgoing response (CORS wins on a
     * shared name, matching the reference _inject_cors_headers ordering).
     */
    private function finish(SymfonyGuardRequest $guardRequest, Response $response): Response
    {
        $config = $this->engine->config();
        $body = null;
        if ($config->behaviorScanResponseBody) {
            $content = $response->getContent();
            $body = substr(is_string($content) ? $content : '', 0, $config->behaviorMaxResponseBodyInspectBytes);
        }
        $this->engine->processResponse(
            $guardRequest,
            new GuardResponse($response->getStatusCode(), body: $body)
        );

        foreach ($this->engine->responseHeaders() as $name => $value) {
            $response->headers->set($name, $value);
        }
        foreach ($this->engine->corsResponseHeaders($guardRequest) as $name => $value) {
            $response->headers->set($name, $value);
        }

        return $response;
    }

    /**
     * Most-specific-first ordering: patterns sort by length descending, so
     * when several patterns match a path the longest (most specific) wins
     * regardless of the order the user supplied them in. Mirrors the TS
     * resolver's longest-path rule and the reference adapter's semantics.
     */
    private static function sortRoutesLongestFirst(array $routes): array
    {
        uksort($routes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $routes;
    }

    private function attachRouteConfig(SymfonyGuardRequest $guardRequest): void
    {
        $routeConfig = $this->resolveRouteConfig($guardRequest);
        if ($routeConfig === null) {
            return;
        }
        $guardRequest->state()->routeConfig = $routeConfig;
    }

    private function resolveRouteConfig(SymfonyGuardRequest $guardRequest): ?RouteConfig
    {
        if ($this->routeResolver !== null) {
            return ($this->routeResolver)($guardRequest->underlying());
        }

        foreach ($this->sortedRoutes as $pattern => $routeConfig) {
            if (self::matchesRoutePattern($pattern, $guardRequest->urlPath())) {
                return $routeConfig;
            }
        }

        return null;
    }

    /**
     * Path match with the engine's exclude-paths convention: the pattern
     * matches the path exactly, or as a prefix when it ends with '/'.
     */
    private static function matchesRoutePattern(string $pattern, string $path): bool
    {
        return $pattern === $path || (str_ends_with($pattern, '/') && str_starts_with($path, $pattern));
    }

    /**
     * Geo rate-limit tiers (RouteConfig geoRateLimits) activate only when a
     * resolver is configured on the engine's rate-limit handler; the engine
     * does not wire one itself, so the adapter bridges the config's
     * geo_ip_handler into it at construction.
     */
    private function wireGeoRateLimitResolver(?CountryResolver $explicit): void
    {
        $geoIpHandler = $explicit ?? $this->engine->config()->geoIpHandler;
        if ($geoIpHandler === null) {
            return;
        }
        $this->engine->rateLimitHandler()->setGeoResolver(
            static fn (string $ip): string => (string) ($geoIpHandler->getCountry($ip) ?? '')
        );
    }
}
