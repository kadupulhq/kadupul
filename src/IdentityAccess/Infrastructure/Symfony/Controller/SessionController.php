<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Application\Query\CurrentActor;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class SessionController
{
    #[Route('/session', name: 'session', methods: ['GET', 'HEAD'])]
    public function __invoke(CurrentActor $currentActor): JsonResponse
    {
        $actor = $currentActor();

        return new JsonResponse(
            $actor === null ? ['error' => 'authentication_required'] : ['id' => $actor->id, 'username' => $actor->username],
            $actor === null ? 401 : 200,
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
