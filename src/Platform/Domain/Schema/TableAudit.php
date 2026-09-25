<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** What report_audit_results() found in one table: its status, findings, counts and the clauses --repair would send. */
final readonly class TableAudit
{
    /**
     * @param list<string> $findings the ERROR and WARNING lines --report prints, in order
     * @param list<AlterClause> $clauses in the order the original collected them
     * @param list<WidenedColumn> $widened columns left wider than the audit schema lists them
     */
    public function __construct(
        public string $table,
        public AuditTableStatus $status,
        public array $findings,
        public int $errors,
        public int $warnings,
        public array $clauses,
        public array $widened = [],
    ) {}

    /** @param bool $output true for --report, false for --repair and --alters, which print no findings */
    public static function of(LiveTable $table, AuditBaseline $baseline, PluginSchemaChanges $plugins, bool $output): self
    {
        if (!$baseline->hasTable($table->name)) {
            // Only --report tells the two apart; the others print "Completed".
            $status = $plugins->createdTable($table->name) ? AuditTableStatus::Plugin : AuditTableStatus::Unknown;

            return new self($table->name, $status, [], 0, 0, []);
        }
        $columns = ColumnDrift::audit($table, $baseline, $plugins, $output);
        $indexes = IndexDrift::audit($table, $baseline, $output);

        return new self(
            $table->name,
            AuditTableStatus::Audited,
            [...$columns['lines'], ...$indexes['lines']],
            $columns['errors'] + $indexes['errors'],
            $columns['warnings'],
            [...$columns['clauses'], ...$indexes['clauses']],
            $columns['widened'],
        );
    }

    public function alter(TableStatus $status): ?TableAlter
    {
        return $this->clauses === [] ? null : new TableAlter($this->table, $this->clauses, $status);
    }
}
