<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The USING clauses the audit writes; docs/audit_schema.sql uses only these two. */
enum IndexAlgorithm: string
{
    case Btree = 'BTREE';
    case Hash = 'HASH';
}
