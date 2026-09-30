<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Doctrine\DBAL\Exception;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Application\Query\SearchCollectorTimezones;
use Symfony\Component\HttpFoundation\JsonResponse;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CollectorTimezonesController
{
    #[Route('/collectors/timezones', name: 'collector_timezones', methods: ['GET'])]
    public function __invoke(Request $request, SearchCollectorTimezones $search, TranslatorInterface $translator, ConsoleAccess $access): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new JsonResponse(['error' => $translator->trans('Access denied.', [], 'collectors')], $actor === null ? 401 : 403, $headers);
        }
        $term = $request->query->all()['term'] ?? '';
        if (!is_string($term) || strlen($term) > 100 || str_contains($term, "\0")) {
            return new JsonResponse(['error' => $translator->trans('Invalid time zone search.', [], 'collectors')], 400, $headers);
        }
        try {
            return new JsonResponse($search($term), 200, $headers);
        } catch (Exception) {
            return new JsonResponse(['error' => $translator->trans('Unable to load time zones.', [], 'collectors')], 502, $headers);
        } catch (CollectorAccessDenied $error) {
            return new JsonResponse(['error' => $translator->trans('Access denied.', [], 'collectors')], $error->unauthenticated ? 401 : 403, $headers);
        }
    }
}
