<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface ColorTemplateSynchronization
{
    /** Each template runs in its own worker of up to 120 seconds. */
    public const int MAX_SELECTION = 10;

    /** No further worker starts after this many seconds of one request. */
    public const int DEADLINE_SECONDS = 180;

    /**
     * Push one template's colors out to the aggregate templates and
     * non-templated aggregate graphs that use it, all or nothing.
     *
     * @return array{template: string, aggregate_templates: int, aggregate_graphs: int}
     */
    public function synchronize(int $actorId, int $templateId, string $revision): array;
}
