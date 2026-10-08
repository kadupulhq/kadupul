<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface ColorTemplateSynchronization
{
    /**
     * Push one template's colors out to the aggregate templates and
     * non-templated aggregate graphs that use it, all or nothing.
     *
     * @return array{template: string, aggregate_templates: int, aggregate_graphs: int}
     */
    public function synchronize(int $actorId, int $templateId, string $revision): array;
}
