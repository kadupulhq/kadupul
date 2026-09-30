<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Controller;

use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplateCatalog;
use Kadupul\AggregateTemplate\Application\Port\AggregateTemplatePermissions;
use Kadupul\IdentityAccess\Contract\CurrentActor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AggregateTemplateListController
{
    #[Route('/aggregate-templates', name: 'aggregate_template_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, CurrentActor $currentActor, AggregateTemplatePermissions $access, AggregateTemplateCatalog $catalog, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $currentActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 401, $headers);
        }
        if (!$access->canManage($actor)) {
            return new Response($translator->trans('Access denied.', [], 'aggregate_template'), 403, $headers);
        }
        $query = $request->query->all();
        try {
            foreach ($query as $key => $value) {
                if (!in_array($key, ['filter', 'page', 'rows', 'sort', 'direction', 'has_graphs', 'deleted'], true) || !is_string($value)) {
                    throw new \InvalidArgumentException('Invalid aggregate template list filters.');
                }
            }
            $page = filter_var($query['page'] ?? '1', FILTER_VALIDATE_INT);
            $rows = filter_var($query['rows'] ?? '30', FILTER_VALIDATE_INT);
            if ($page === false || $rows === false || !in_array($query['has_graphs'] ?? '', ['', 'on'], true)) {
                throw new \InvalidArgumentException('Invalid aggregate template list filters.');
            }
            $criteria = new AggregateTemplateCriteria(
                is_string($query['filter'] ?? '') ? trim($query['filter'] ?? '') : '',
                $page,
                $rows,
                is_string($query['sort'] ?? null) ? $query['sort'] : 'name',
                is_string($query['direction'] ?? null) ? strtolower($query['direction']) : 'asc',
                ($query['has_graphs'] ?? '') === 'on',
            );
            $result = $catalog->list($criteria);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid aggregate template list filters.', [], 'aggregate_template'), 400, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('aggregate_template/list.html.twig', [
            'result' => $result, 'criteria' => $criteria,
            'pages' => max(1, (int) ceil($result['total'] / $criteria->pageSize)),
        ]), 200, $headers);
    }
}
