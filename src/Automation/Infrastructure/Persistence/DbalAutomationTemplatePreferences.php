<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Persistence;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplatePreferences;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Platform\Contract\JsonFilterPreferences;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AutomationTemplatePreferences::class)]
final readonly class DbalAutomationTemplatePreferences implements AutomationTemplatePreferences
{
    private const string NAME = 'automation_template_filters';

    public function __construct(
        private AutomationTemplateAccess $access,
        private JsonFilterPreferences $preferences,
    ) {}

    public function load(): ?array
    {
        return $this->preferences->load(
            $this->access->authorize()->id,
            self::NAME,
            AutomationTemplateFilters::KEYS,
            static fn(array $filters) => AutomationTemplateFilters::fromQuery($filters, 1),
        );
    }

    public function save(array $filters): void
    {
        $this->preferences->save(self::NAME, $filters, AutomationTemplateFilters::KEYS, 'Invalid automation template filters.', function (): int {
            $actor = $this->access->authorize();
            $this->access->assertCurrent($actor->id);
            return $actor->id;
        });
    }
}
