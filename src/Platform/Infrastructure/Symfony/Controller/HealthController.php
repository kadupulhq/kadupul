<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    // Liveness only: this does not certify database, polling or storage readiness.
    #[Route('/healthz', name: 'health', methods: ['GET', 'HEAD'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
