<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

interface GraphTemplateInputEditor
{
    public const string NOT_FOUND = 'Graph Item Input not found.';
    public const string TEMPLATE_NOT_FOUND = 'Graph Template not found.';
    public const string STALE = 'The Graph Item Input changed since you opened this form. Reload before saving.';
    public const string RELATIONSHIP = 'The Graph Item Input relationship is invalid.';

    /**
     * Create or update the input and replace its item set in one transaction.
     * Items that join an existing input take that input's current value on
     * every graph built from the template.
     *
     * @param list<int> $itemIds
     */
    public function save(int $actorId, int $templateId, ?int $inputId, string $name, string $description, string $column, array $itemIds, ?string $revision): int;

    public function remove(int $actorId, int $templateId, int $inputId, string $revision): void;
}
