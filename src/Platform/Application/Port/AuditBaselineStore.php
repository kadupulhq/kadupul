<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\Schema\AuditBaseline;
use Kadupul\Platform\Domain\Schema\InvalidAuditSchema;
use Kadupul\Platform\Application\Port\AuditCatalog;

/** The parsed canonical schema used by the audit, and a renderer for live schema metadata. */
interface AuditBaselineStore
{
    /**
     * The file, parsed. It is read, never sent to the server.
     *
     * @return ?AuditBaseline null when the file is missing
     * @throws InvalidAuditSchema
     */
    public function read(): ?AuditBaseline;

    /** Render the live database catalog as a parseable SQL baseline dump. */
    public function export(AuditCatalog $catalog): string;
}
