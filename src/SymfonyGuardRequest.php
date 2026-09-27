<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCoreSymfony;

use RenzoFranceschini\GuardCore\Request\GuardRequest;
use RenzoFranceschini\GuardCore\Request\HeaderBag;
use RenzoFranceschini\GuardCore\Request\RequestState;
use Symfony\Component\HttpFoundation\Request;

final class SymfonyGuardRequest implements GuardRequest
{
    public const MAX_BODY_BYTES = 262144;

    private RequestState $state;

    private ?string $cachedBody = null;

    private bool $bodyRead = false;

    private bool $bodyTruncated = false;

    public function __construct(private readonly Request $request)
    {
        $this->state = new RequestState();
    }

    public function urlPath(): string
    {
        $path = $this->request->getPathInfo();

        return $path === '' ? '/' : $path;
    }

    public function urlScheme(): string
    {
        return $this->request->getScheme();
    }

    public function urlFull(): string
    {
        $query = $this->request->getQueryString();
        $url = $this->urlScheme() . '://' . $this->request->getHttpHost() . $this->urlPath();

        return $query !== null && $query !== '' ? $url . '?' . $query : $url;
    }

    public function urlReplaceScheme(string $scheme): string
    {
        $full = $this->urlFull();

        return $scheme . '://' . substr($full, strlen($this->urlScheme()) + 3);
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    public function clientHost(): ?string
    {
        $ip = $this->request->getClientIp();
        if ($ip === null || $ip === '') {
            return null;
        }

        return $ip;
    }

    public function headers(): HeaderBag
    {
        $headers = [];
        foreach ($this->request->headers->all() as $name => $values) {
            $headers[$name] = implode(', ', array_map(strval(...), (array) $values));
        }

        return new HeaderBag($headers);
    }

    /** @return array<string, string|list<string>> */
    public function queryParams(): array
    {
        return $this->request->query->all();
    }

    public function body(): string
    {
        if ($this->bodyRead) {
            return $this->cachedBody ?? '';
        }

        $content = $this->request->getContent();
        $content = is_string($content) ? $content : '';
        $this->bodyTruncated = strlen($content) > self::MAX_BODY_BYTES;
        $this->cachedBody = substr($content, 0, self::MAX_BODY_BYTES);
        $this->bodyRead = true;

        return $this->cachedBody;
    }

    public function bodyWasTruncated(): bool
    {
        $this->body();

        return $this->bodyTruncated;
    }

    public function state(): RequestState
    {
        return $this->state;
    }

    /**
     * The wrapped Symfony request, for adapter resolvers that inspect
     * framework-native surfaces (the resolved route, attributes, ...).
     */
    public function underlying(): Request
    {
        return $this->request;
    }
}
