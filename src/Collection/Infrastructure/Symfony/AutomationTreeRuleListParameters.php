<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony;

use Kadupul\Collection\Domain\AutomationTreeRuleCriteria;

final class AutomationTreeRuleListParameters
{
    /** @param array<string, mixed> $query @return array<string, string> */
    public static function formData(array $query): array
    {
        if (array_diff(array_keys($query), ['q', 'status', 'page', 'size', 'sort', 'direction', 'automation_tree_rule_filter']) !== []) {
            throw new \InvalidArgumentException('Unexpected automation tree rule filters.');
        }
        $data = $query['automation_tree_rule_filter'] ?? [];
        if (!is_array($data) || array_diff(array_keys($data), ['q', 'status', 'size', 'sort', 'direction']) !== []) {
            throw new \InvalidArgumentException('Invalid automation tree rule filters.');
        }
        $source = $data === [] ? $query : $data;
        $result = [];
        foreach (['q', 'status', 'size', 'sort', 'direction'] as $field) {
            if (isset($source[$field]) && !is_string($source[$field])) {
                throw new \InvalidArgumentException('Invalid automation tree rule filters.');
            }
            if (isset($source[$field])) {
                $result[$field] = $source[$field];
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $form */
    public static function parse(array $query, array $form): AutomationTreeRuleCriteria
    {
        $page = $query['page'] ?? '1';
        if (!is_string($page) || !preg_match('/\A[1-9][0-9]{0,5}\z/D', $page)) {
            throw new \InvalidArgumentException('Invalid automation tree rule filters.');
        }
        return new AutomationTreeRuleCriteria(
            (string) ($form['q'] ?? ''),
            (string) ($form['status'] ?? 'all'),
            (int) $page,
            (int) ($form['size'] ?? 25),
            (string) ($form['sort'] ?? 'name'),
            (string) ($form['direction'] ?? 'asc')
        );
    }
}
