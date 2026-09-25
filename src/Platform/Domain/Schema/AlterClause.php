<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * One change inside an audit's ALTER TABLE. Each kind carries typed parts the
 * adapter renders and quotes, and the text audit_database.php would have
 * written, which --alters and a failed repair print but the server never sees.
 */
interface AlterClause
{
    public function legacy(): string;
}
