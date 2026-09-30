<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Domain;

final class AggregateTemplateRevision
{
    public static function fromState(array $template, array $graph, array $items): string
    {
        ksort($template);
        ksort($graph);
        usort($items, static fn(array $left, array $right): int => [$left['sequence'], $left['graph_templates_item_id']] <=> [$right['sequence'], $right['graph_templates_item_id']]);
        return hash('sha256', json_encode([$template, $graph, $items], JSON_THROW_ON_ERROR));
    }
}
