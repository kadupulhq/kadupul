<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Infrastructure\Symfony\LinkListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyLinksController
{
    #[Route('/links/legacy', name: 'navigation_links_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, LinkAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'navigation'), 401, $headers);
        }
        try {
            $access->authorize();
        } catch (LinkAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'navigation'), $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open External Links and submit a new form.', [], 'navigation'), 409, $headers);
        }
        $query = $request->query->all();
        $action = $query['action'] ?? '';
        if (!is_string($action)) {
            return new Response($translator->trans('Invalid link selection.', [], 'navigation'), 400, $headers);
        }
        try {
            if ($action === 'edit') {
                $id = $query['id'] ?? '0';
                if ($id === '' || $id === '0') {
                    return new RedirectResponse($urls->generate('navigation_link_create'), 302, $headers);
                }
                $ids = LinkListParameters::ids([$id]);
                return new RedirectResponse($urls->generate('navigation_link_edit', ['id' => $ids[0]]), 302, $headers);
            }
            $operations = ['delete_page' => 'delete', 'move_page_up' => 'up', 'move_page_down' => 'down'];
            if (isset($operations[$action])) {
                return new RedirectResponse($urls->generate('navigation_link_action', ['operation' => $operations[$action], 'ids' => LinkListParameters::ids([$query['id'] ?? null])]), 302, $headers);
            }
            if ($action !== '') {
                return new Response($translator->trans('Open External Links and use its current forms.', [], 'navigation'), 400, $headers);
            }
            $filters = LinkListParameters::parse($query);
            unset($filters['limit']);
            $filters = array_intersect_key($query, $filters);
            if (isset($query['clear'])) {
                $filters['clear'] = '1';
            }
            return new RedirectResponse($urls->generate('navigation_links', $filters), 302, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'navigation'), 400, $headers);
        }
    }
}
