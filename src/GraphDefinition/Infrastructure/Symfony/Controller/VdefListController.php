<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Query\ListVdefs;
use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\GraphDefinition\Application\Query\VdefAccessDenied;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class VdefListController
{
    #[Route('/graph-definitions/vdefs', name: 'graph_vdefs', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ListVdefs $list, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->query->all();
            $page = self::int($query['page'] ?? 1, 'page');
            $pageSize = self::int($query['rows'] ?? 30, 'rows');
            if ($pageSize === -1) {
                $pageSize = 30;
            }
            $criteria = new VdefListCriteria(
                is_string($query['filter'] ?? '') ? mb_substr($query['filter'] ?? '', 0, 255) : '',
                $page,
                $pageSize,
                in_array($query['sort'] ?? 'name', ['name', 'graphs', 'templates'], true) ? ($query['sort'] ?? 'name') : 'name',
                ($query['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc',
                ($query['has_graphs'] ?? '') === 'true',
            );
            $result = $list($criteria);
            $pages = max(1, (int) ceil($result['total'] / $pageSize));
            return new Response($twig->render('graph_definition/vdefs.html.twig', [
                'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page,
                'pages' => $pages, 'criteria' => $criteria,
            ]), 200, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid VDEF list options.', [], 'graph_definition'), 400, $headers);
        }
    }

    private static function int(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?[0-9]{1,9}$/D', $value) === 1) {
            return (int) $value;
        }
        throw new \InvalidArgumentException('Invalid ' . $field . '.');
    }
}
