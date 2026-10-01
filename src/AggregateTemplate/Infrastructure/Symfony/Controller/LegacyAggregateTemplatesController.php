<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Controller;

use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\IdentityAccess\Contract\CurrentActor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LegacyAggregateTemplatesController
{
    #[Route('/aggregate-templates/legacy', name: 'aggregate_template_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, CurrentActor $currentActor, AggregateTemplatePermissions $permissions, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $currentActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 401, $headers);
        }
        if (!$permissions->canManage($actor)) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 403, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This form has expired. Open Aggregate Templates and submit a new form.', [], 'aggregate_template'), 409, $headers);
        }
        $query = $request->query->all();
        $action = $query['action'] ?? '';
        if ($action === 'edit') {
            $raw = $query['id'] ?? '0';
            if (!is_string($raw) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $raw)) {
                return new Response($translator->trans('Invalid aggregate template selection.', [], 'aggregate_template'), 400, $headers);
            }
            return new RedirectResponse($urls->generate('aggregate_template_edit', ['id' => (int) $raw]), 302, $headers);
        }
        if ($action !== '') {
            return new Response($translator->trans('Open Aggregate Templates and use its current forms.', [], 'aggregate_template'), 400, $headers);
        }
        $filters = array_intersect_key($query, array_flip(['filter', 'page', 'rows', 'has_graphs']));
        if (isset($filters['rows']) && !in_array($filters['rows'], ['30', '50', '100'], true)) {
            unset($filters['rows']);
        }
        if (isset($filters['has_graphs'])) {
            $filters['has_graphs'] = match ($filters['has_graphs']) {
                'true' => 'on', 'false' => '', default => $filters['has_graphs'],
            };
        }
        if (isset($query['sort_column'])) {
            $filters['sort'] = match ($query['sort_column']) {
                'name' => 'name', 'graphs', 'graphs.graphs' => 'graphs', 'graph_template_name' => 'source', default => 'name',
            };
        }
        if (is_string($query['sort_direction'] ?? null)) {
            $filters['direction'] = strtolower($query['sort_direction']);
        }
        return new RedirectResponse($urls->generate('aggregate_template_list', $filters), 302, $headers);
    }
}
