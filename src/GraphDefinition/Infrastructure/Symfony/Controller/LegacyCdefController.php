<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\CdefAuthorization;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyCdefController
{
    #[Route('/graph-definitions/cdefs/legacy', name: 'graph_cdef_legacy', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, CdefAuthorization $authorization, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $authorization->actor();
            $query = $request->query->all();
            $action = is_string($query['action'] ?? null) ? $query['action'] : '';
            $id = $query['id'] ?? null;
            $cdefId = $query['cdef_id'] ?? null;
            if ($action === 'edit' && ($id === null || (is_string($id) && ctype_digit($id)))) {
                if ($id === null || $id === '' || $id === '0') {
                    return new RedirectResponse($urls->generate('graph_cdef_create'), 302, ['Cache-Control' => 'private, no-store']);
                }
                if ((int) $id > 0) {
                    return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => (int) $id]), 302, ['Cache-Control' => 'private, no-store']);
                }
            }
            if ($action === 'item_edit' && is_string($cdefId) && ctype_digit($cdefId) && (int) $cdefId > 0
                && ($id === null || (is_string($id) && ctype_digit($id)))) {
                $parameters = ['cdefId' => (int) $cdefId, 'itemId' => ($id === null || $id === '' || $id === '0') ? 0 : (int) $id];
                if (isset($query['type_select']) && is_string($query['type_select']) && isset(\Kadupul\GraphDefinition\Domain\CdefFunctions::TYPES[(int) $query['type_select']])) {
                    $parameters['type'] = $query['type_select'];
                }
                return new RedirectResponse($urls->generate('graph_cdef_item_edit', $parameters), 302, ['Cache-Control' => 'private, no-store']);
            }
            $parameters = [];
            foreach (['filter' => 'filter', 'rows' => 'rows', 'page' => 'page'] as $old => $new) {
                if (isset($query[$old]) && is_string($query[$old])) {
                    $parameters[$new] = $query[$old];
                }
            }
            if (isset($query['sort_column']) && is_string($query['sort_column'])) {
                $parameters['sort'] = in_array($query['sort_column'], ['graphs', 'templates'], true) ? $query['sort_column'] : 'name';
            }
            if (isset($query['sort_direction']) && is_string($query['sort_direction'])) {
                $parameters['direction'] = strtolower($query['sort_direction']) === 'desc' ? 'desc' : 'asc';
            }
            if (($query['has_graphs'] ?? '') === 'true') {
                $parameters['has_graphs'] = 'true';
            }
            return new RedirectResponse($urls->generate('graph_cdefs', $parameters), 302, ['Cache-Control' => 'private, no-store']);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        }
    }
}
