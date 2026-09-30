<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

use Kadupul\Platform\Domain\Schema\AuditMode;
use Kadupul\Platform\Domain\Schema\TableAudit;

final readonly class AuditReport
{
    /**
     * @param ?UpgradeOutput $upgrade null unless an upgrade ran
     * @param bool $upgradePlanned a dry run that would have upgraded first
     * @param ?int $unparsableLine the first line of docs/audit_schema.sql that did not parse
     * @param list<TableAudit> $tables every table the run looked at, in SHOW TABLES order
     * @param list<array{table: string, legacy: string, result: AlterResult, statement: ?string}> $alters in table order
     * @param list<string> $generatedTables the live tables included in a --load dump
     * @param ?bool $exported null when --load did not export
     */
    public function __construct(
        public AuditOutcome $outcome,
        public ?AuditMode $mode,
        public bool $dryRun,
        public ?UpgradeOutput $upgrade = null,
        public bool $upgradePlanned = false,
        public ?BaselineOutcome $baseline = null,
        public ?int $unparsableLine = null,
        public array $tables = [],
        public array $alters = [],
        public array $generatedTables = [],
        public ?string $dumpPath = null,
        public ?bool $exported = null,
        public ?string $exportContent = null,
    ) {}

    /** Statements that failed, a baseline that did not load, and a dump that did not write. */
    public function failed(): int
    {
        $alters = count(array_filter($this->alters, static fn(array $alter): bool => $alter['result'] === AlterResult::Failed));
        $baseline = in_array($this->baseline, [BaselineOutcome::FileMissing, BaselineOutcome::Unparsable], true) ? 1 : 0;

        return $alters + $baseline + ($this->exported === false ? 1 : 0) + ($this->upgrade?->completed === false ? 1 : 0);
    }
}
