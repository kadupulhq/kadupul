<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Kadupul\CollectorAdministration\Application\Command\TestCollectorConnection;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Symfony\Component\HttpFoundation\JsonResponse;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CollectorConnectionTestController
{
    private const array CREDENTIAL_FIELDS = ['dbhost', 'dbuser', 'dbpass', 'dbdefault', 'dbport', 'dbretries', 'dbssl', 'dbsslkey', 'dbsslcert', 'dbsslca'];
    private const array FORM_FIELDS = [...self::CREDENTIAL_FIELDS, 'revision'];

    #[Route('/collectors/connection-test', name: 'collector_connection_test', methods: ['POST'])]
    public function __invoke(Request $request, TestCollectorConnection $test, CsrfTokenManagerInterface $tokens, TranslatorInterface $translator, ConsoleAccess $access): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new JsonResponse(['error' => $translator->trans('Access denied.', [], 'collectors')], $actor === null ? 401 : 403, $headers);
        }
        $token = $request->request->get('connection_token');
        if (!is_string($token) || !$tokens->isTokenValid(new CsrfToken('collector_connection_test', $token))) {
            return new JsonResponse(['message' => $translator->trans('The connection test request was rejected.', [], 'collectors')], 419, $headers);
        }
        $group = $request->request->all('collector_edit');
        unset($group['_token']);
        $requiredFields = array_diff(self::CREDENTIAL_FIELDS, ['dbssl']);
        if (array_diff($requiredFields, array_keys($group)) !== [] || array_diff(array_keys($group), self::FORM_FIELDS) !== []) {
            return new JsonResponse(['message' => $translator->trans('Connection test fields are incomplete.', [], 'collectors')], 400, $headers);
        }
        foreach (self::CREDENTIAL_FIELDS as $field) {
            if (isset($group[$field]) && !is_scalar($group[$field])) {
                return new JsonResponse(['message' => $translator->trans('Connection test fields are invalid.', [], 'collectors')], 400, $headers);
            }
        }
        $credentials = array_intersect_key($group, array_flip(self::CREDENTIAL_FIELDS));
        $credentials['dbssl'] ??= '';
        $credentials['dbssl'] = ($credentials['dbssl'] === '1' || $credentials['dbssl'] === 'on') ? 'on' : '';
        $collectorIdValue = $request->request->get('collector_id');
        $collectorId = $collectorIdValue === null ? null : filter_var($collectorIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2, 'max_range' => 65535]]);
        if ($collectorIdValue !== null && $collectorId === false) {
            return new JsonResponse(['message' => $translator->trans('Connection test fields are invalid.', [], 'collectors')], 400, $headers);
        }
        try {
            $revision = is_string($group['revision'] ?? null) ? $group['revision'] : null;
            $connected = $test($credentials, $collectorId === false ? null : $collectorId, $revision);
            return new JsonResponse(['message' => $translator->trans($connected ? 'Connection Successful' : 'Connection Failed', [], 'collectors')], 200, $headers);
        } catch (CollectorAccessDenied $error) {
            return new JsonResponse(['message' => $translator->trans('Access denied.', [], 'collectors')], $error->unauthenticated ? 401 : 403, $headers);
        }
    }
}
