<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\Graphing\Domain\GraphTemplateInput;
use Kadupul\Graphing\Domain\GraphTemplateInputItem;

interface GraphTemplateInputCatalog
{
    /** The graph template's name, or null when it does not exist. */
    public function templateName(int $templateId): ?string;

    /** The input only when it belongs to the graph template. */
    public function find(int $templateId, int $inputId): ?GraphTemplateInput;

    /**
     * The template's own items (local_graph_id 0) in sequence order, marked
     * when the input already holds them.
     *
     * @return list<GraphTemplateInputItem>
     */
    public function items(int $templateId, ?int $inputId): array;
}
