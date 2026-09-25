<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * A column or index name the audit takes from docs/audit_schema.sql because
 * the server does not have it yet. The file ships with the code, but --load
 * rewrites it from a live database, so its names are held to the characters
 * Kadupul's own tables use before they are quoted into DDL.
 */
final class BaselineName
{
    public const string PATTERN = '/^[A-Za-z0-9_$-]{1,64}$/D';

    public static function valid(?string $name): bool
    {
        return $name !== null && preg_match(self::PATTERN, $name) === 1;
    }
}
