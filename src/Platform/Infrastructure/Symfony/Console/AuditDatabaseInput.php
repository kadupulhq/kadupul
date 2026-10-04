<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Domain\Schema\AuditMode;
use Symfony\Component\Console\Attribute\Option;

/** MapInput builds this without its constructor and assigns each public property. */
final class AuditDatabaseInput
{
    #[Option(description: 'Report how the schema differs from docs/audit_schema.sql.')]
    public bool $report = false;

    #[Option(description: 'Change the schema to match docs/audit_schema.sql. Without --force this only plans, and asks first on a terminal.')]
    public bool $repair = false;

    #[Option(description: 'Run the --repair statements without asking.')]
    public bool $force = false;

    #[Option(description: 'Print the statements a repair would run instead of running them.')]
    public bool $alters = false;

    #[Option(description: 'Upgrade the database first when its version is behind the code.')]
    public bool $upgrade = false;

    #[Option(description: 'Reload the audit schema tables from docs/audit_schema.sql.')]
    public bool $create = false;

    #[Option(description: 'Rewrite docs/audit_schema.sql from this database (for developers).')]
    public bool $load = false;

    #[Option(description: 'Operator account to act as (default: the admin_user setting).')]
    public ?string $as = null;

    #[Option(description: 'Read the file and the schema, list the statements, and change nothing.')]
    public bool $dryRun = false;

    #[Option(description: 'Emit a machine-readable result.')]
    public bool $json = false;

    /** The mode the original ran when several were given; null when none was. */
    public function mode(): ?AuditMode
    {
        return AuditMode::first(array_values(array_filter([
            $this->repair ? AuditMode::Repair : null,
            $this->create ? AuditMode::Create : null,
            $this->report ? AuditMode::Report : null,
            $this->alters ? AuditMode::Alters : null,
            $this->load ? AuditMode::Load : null,
        ])));
    }
}
