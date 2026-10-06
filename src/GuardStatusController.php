<?php

declare(strict_types=1);

namespace RenzoFranceschini\GuardCoreSymfony;

use RenzoFranceschini\GuardCore\Engine\GuardEngine;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * GET /_guard/status controller: serves the engine's initialization status
 * (per-component enabled/ok/error from initialize()), mirroring
 * fastapi-guard's add_status_route. Register it as a Symfony controller
 * service wired with your engine instance.
 */
final class GuardStatusController
{
    public function __construct(private readonly GuardEngine $engine)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse($this->engine->initializationStatus());
    }
}
