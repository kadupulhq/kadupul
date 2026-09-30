<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\ListCdefs;
use Kadupul\GraphDefinition\Domain\CdefListCriteria;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CdefListController
{
    #[Route('/graph-definitions/cdefs', name: 'graph_cdefs', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ListCdefs $list, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->query->all();
            $search = $query['filter'] ?? '';
            if (!is_string($search) || mb_strlen($search) > 255) {
                throw new \InvalidArgumentException('Invalid CDEF list filters.');
            }
            $page = self::integer($query['page'] ?? '1', 'page');
            $rows = self::integer($query['rows'] ?? '30', 'rows');
            if ($rows === -1) {
                $rows = 30;
            }
            if (!in_array($rows, [10, 30, 50, 100], true)) {
                throw new \InvalidArgumentException('Invalid CDEF list filters.');
            }
            $sort = $query['sort'] ?? 'name';
            $direction = $query['direction'] ?? 'asc';
            $hasGraphs = $query['has_graphs'] ?? 'false';
            if (!is_string($sort) || !is_string($direction) || !in_array($hasGraphs, ['true', 'false', 'on', ''], true)) {
                throw new \InvalidArgumentException('Invalid CDEF list filters.');
            }
            $criteria = new CdefListCriteria($search, $page, $rows, $sort, strtolower($direction), in_array($hasGraphs, ['true', 'on'], true));
            $result = $list($criteria);
            $pages = max(1, (int) ceil($result['total'] / $rows));
            return new Response($twig->render('graph_definition/cdefs.html.twig', [
                'rows' => $result['rows'], 'total' => $result['total'], 'page' => $page, 'pages' => $pages, 'criteria' => $criteria,
            ]), 200, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid CDEF list filters.', [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF list is unavailable.', [], 'graph_definition'), 502, $headers);
        }
    }

    private static function integer(mixed $value, string $field): int
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
