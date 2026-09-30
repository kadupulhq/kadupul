<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Doctrine\DBAL\Exception;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\CollectorListParameters;
use Kadupul\CollectorAdministration\Application\Query\ListCollectors;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LegacyCollectorsController
{
    #[Route('/collectors/legacy', name: 'collector_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $access, UrlGeneratorInterface $urls, ListCollectors $list): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response('Access denied.', $actor === null ? 401 : 403, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response('This legacy form has expired. Open Data Collectors and submit a new form.', 409, $headers);
        }
        try {
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            if ($action === 'edit') {
                $id = $query['id'] ?? '0';
                if (!is_string($id) || !preg_match('/\A[0-9]{1,5}\z/D', $id) || (int) $id > 65535) {
                    throw new \InvalidArgumentException();
                }
                return new RedirectResponse($urls->generate((int) $id === 0 ? 'collector_create' : 'collector_edit', (int) $id === 0 ? [] : ['id' => (int) $id]), 302, $headers);
            }
            if ($action !== '') {
                return new Response('Open Data Collectors and use its current forms.', 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            $sorts = ['poller.hostname' => 'hostname', 'total_time' => 'polling_time'];
            $sort = $query['sort_column'] ?? 'name';
            $direction = $query['sort_direction'] ?? 'asc';
            if (!is_string($sort) || !is_string($direction)) {
                throw new \InvalidArgumentException();
            }
            $filters = [
                'q' => $query['filter'] ?? '',
                'size' => ($query['rows'] ?? '-1') === '-1' ? (string) $list->defaultPageSize() : $query['rows'],
                'sort' => $sorts[$sort] ?? $sort,
                'direction' => strtolower($direction),
                'refresh' => $query['refresh'] ?? '20',
            ];
            $criteria = CollectorListParameters::parse(['page' => $query['page'] ?? '1'], $filters);
            return new RedirectResponse($urls->generate('collector_list', ['collector_filter' => $filters, 'page' => $criteria->page]), 302, $headers);
        } catch (\InvalidArgumentException) {
            return new Response('Invalid collector list filters.', 400, $headers);
        } catch (Exception) {
            return new Response('Unable to load Data Collectors. Please reload before retrying.', 502, $headers);
        }
    }
}
