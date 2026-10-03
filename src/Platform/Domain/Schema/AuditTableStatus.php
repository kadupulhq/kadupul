<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** Backed, because the values are the stable JSON "status" strings. */
enum AuditTableStatus: string
{
    /** Not in the audit schema and not recorded by a plugin: "Does not Exist.  Possible Plugin". */
    case Unknown = 'unknown';
    case Plugin = 'plugin';
    case Audited = 'audited';
}
