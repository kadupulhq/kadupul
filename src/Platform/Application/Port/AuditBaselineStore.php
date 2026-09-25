<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\InvalidAuditSchema;

/** docs/audit_schema.sql and the two tables audit_database.php loaded it into. */
interface AuditBaselineStore
{
    /**
     * The file, parsed. It is read, never sent to the server.
     *
     * @return ?AuditBaseline null when the file is missing
     * @throws InvalidAuditSchema
     */
    public function read(): ?AuditBaseline;

    /**
     * create_tables() before its load: both tables exist and are empty.
     *
     * @return ?string the first table that could not be created, or null
     */
    public function reset(DatabaseTarget $target): ?string;

    /** What piping the file into the mysql client left behind: its own two table definitions, holding $baseline's rows. */
    public function replace(DatabaseTarget $target, AuditBaseline $baseline): bool;

    /** load_audit_database(): every column and index $catalog lists, in one transaction. */
    public function import(DatabaseTarget $target, AuditCatalog $catalog): bool;

    /** Where export() writes: <root>/docs/audit_schema.sql, or null when docs/ does not exist. */
    public function dumpPath(): ?string;

    /** db_dump_data() of both tables into dumpPath(). False when the dump program failed, which leaves the file as it was. */
    public function export(DatabaseTarget $target): bool;
}
