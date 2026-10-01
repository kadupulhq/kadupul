<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;
use Kadupul\Navigation\Application\Port\LinkPreferences;
use Kadupul\Navigation\Application\Query\LinkAccessDenied;
use Kadupul\Navigation\Application\Query\ListLinks;
use Kadupul\Navigation\Infrastructure\Symfony\LinkListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class LinkListController
{
    #[Route('/links', name: 'navigation_links', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, LinkAccess $access, LinkStore $store, LinkPreferences $preferences, ListLinks $list, Environment $twig, TranslatorInterface $translator): Response
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
        try {
            $query = $request->query->all();
            $remembered = isset($query['clear']) ? [] : ($preferences->load() ?? []);
            $filters = LinkListParameters::parse(array_replace($remembered, $query), $store->defaultRows());
            $saved = $filters;
            unset($saved['limit']);
            $preferences->save($saved);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'navigation'), 400, $headers);
        }
        $page = $list($filters);
        return new Response($twig->render('navigation/links.html.twig', ['links' => $page['links'], 'filters' => $filters, 'total' => $page['total'], 'viewPath' => $request->getBasePath() . '/link.php']), 200, $headers);
    }
}
