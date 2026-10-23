<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Port;

use Kadupul\Automation\Domain\AutomationTemplate;
use Kadupul\Automation\Domain\AutomationTemplateFilters;
use Kadupul\Automation\Domain\AutomationTemplatePage;

/**
 * Writes throw \InvalidArgumentException for a refusal the user can correct,
 * including a stale revision. Any other exception leaves the outcome uncertain.
 */
interface AutomationTemplateStore
{
    public const int MAX_SELECTION = 100;

    public function defaultRows(): int;

    /** The Downed Device Detection setting, which the legacy form preselected for a new template. */
    public function defaultAvailability(): int;

    public function list(AutomationTemplateFilters $filters): AutomationTemplatePage;

    public function find(int $id): ?AutomationTemplate;

    /**
     * @param list<int> $ids
     * @return array<int, AutomationTemplate> keyed by ID, only for IDs that exist
     */
    public function findMany(array $ids): array;

    /** @return array<int, string> device template ID => name, in name order */
    public function deviceTemplates(): array;

    /** @param string $sysDescr as entered; it is stored with the legacy HTML encoding */
    public function save(int $actorId, ?int $id, int $hostTemplate, int $availabilityMethod, string $sysDescr, string $sysName, string $sysOid, ?string $revision): int;

    /** Swap the template with its neighbour in match order; $offset is -1 or 1. */
    public function move(int $actorId, int $id, int $offset, string $order): void;

    /**
     * @param list<int> $ids
     * @param array<int, string> $revisions
     */
    public function delete(int $actorId, array $ids, array $revisions): void;
}
