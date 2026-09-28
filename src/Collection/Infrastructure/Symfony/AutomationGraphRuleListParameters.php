<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony;

use Kadupul\Collection\Domain\AutomationGraphRuleCriteria;

final class AutomationGraphRuleListParameters
{
    /** @param array<string, mixed> $query @return array<string, string> */
    public static function formData(array $query): array
    {
        if (array_diff(array_keys($query), ['q', 'status', 'data_query', 'page', 'size', 'sort', 'direction', 'automation_graph_rule_filter']) !== []) {
            throw new \InvalidArgumentException('Unexpected automation graph rule filters.');
        }
        $data = $query['automation_graph_rule_filter'] ?? [];
        if (!is_array($data) || array_diff(array_keys($data), ['q', 'status', 'data_query', 'size', 'sort', 'direction']) !== []) {
            throw new \InvalidArgumentException('Invalid automation graph rule filters.');
        }
        $source = $data === [] ? $query : $data;
        $result = [];
        foreach (['q', 'status', 'data_query', 'size', 'sort', 'direction'] as $field) {
            if (isset($source[$field]) && !is_string($source[$field])) {
                throw new \InvalidArgumentException('Invalid automation graph rule filters.');
            }
            if (isset($source[$field])) {
                $result[$field] = $source[$field];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $form */
    public static function parse(array $query, array $form): AutomationGraphRuleCriteria
    {
        $page = $query['page'] ?? '1';
        $dataQuery = $form['data_query'] ?? '-1';
        $dataQuery = $dataQuery === '' ? '-1' : $dataQuery;
        if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,5}\z/D', $page)
            || !is_string($dataQuery) || !preg_match('/\A-?[0-9]{1,8}\z/D', $dataQuery)) {
            throw new \InvalidArgumentException('Invalid automation graph rule filters.');
        }
        return new AutomationGraphRuleCriteria(
            (string) ($form['q'] ?? ''),
            (string) ($form['status'] ?? 'all'),
            (int) $dataQuery,
            (int) $page,
            (int) ($form['size'] ?? 25),
            (string) ($form['sort'] ?? 'name'),
            (string) ($form['direction'] ?? 'asc')
        );
    }
}
