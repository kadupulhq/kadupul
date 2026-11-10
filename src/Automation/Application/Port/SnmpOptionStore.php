<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Application\Port;

use Kadupul\Automation\Domain\SnmpOptionEntries;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Domain\SnmpOptionSet;

/**
 * SNMP Option Sets and their entries. Reads never return credentials.
 * Writes throw \InvalidArgumentException for a refusal the user can correct,
 * including a stale revision. Any other exception leaves the outcome uncertain.
 */
interface SnmpOptionStore
{
    /**
     * The SNMP defaults a new entry starts from, read from the settings the
     * legacy form used; credentials are not included.
     *
     * @return array<string, int|string> keyed like SnmpOptionEntry::settings()
     */
    public function entryDefaults(): array;

    public function findSet(int $id): ?SnmpOptionSet;

    public function entries(int $setId): SnmpOptionEntries;

    /** Only an entry of $setId is returned. */
    public function findEntry(int $setId, int $entryId): ?SnmpOptionEntry;

    public function saveSet(int $actorId, ?int $id, string $name, ?string $revision): int;

    /** A new entry is appended after the set's last entry. */
    public function saveEntry(int $actorId, int $setId, ?int $entryId, SnmpOptionEntryChange $change, ?string $revision): int;

    public function deleteEntry(int $actorId, int $setId, int $entryId, string $revision): void;

    /** Swap the entry with its neighbour in discovery order; $offset is -1 or 1. */
    public function moveEntry(int $actorId, int $setId, int $entryId, int $offset, string $order): void;
}
