<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** One graph to create: a plain template graph, or one index of a data query. */
final readonly class GraphDraft
{
    /**
     * @param list<array{0: string, 1: ?string, 2: ?string}> $customData data template id, data input field id and value, in the order given
     */
    public function __construct(
        public int $templateId,
        public int $deviceId,
        public ?int $queryId,
        public ?int $queryTypeId,
        public ?string $queryIndex,
        public array $customData,
        public string $title,
    ) {}
}
