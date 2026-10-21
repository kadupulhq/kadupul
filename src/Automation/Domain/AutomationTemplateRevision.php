<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** Opaque digests that make an open form stale when what it shows has changed. */
final class AutomationTemplateRevision
{
    /**
     * The sequence is left out: reordering must not invalidate an open editor.
     *
     * @param array<string, mixed> $row id, host_template, availability_method, sysDescr, sysName and sysOid
     */
    public static function of(array $row): string
    {
        return hash('sha256', json_encode([
            (int) $row['id'], (int) $row['host_template'], (int) $row['availability_method'],
            (string) $row['sysDescr'], (string) $row['sysName'], (string) $row['sysOid'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $rows id and sequence of every template in match order */
    public static function order(array $rows): string
    {
        return hash('sha256', json_encode(array_map(
            static fn(array $row): array => [(int) $row['id'], (int) $row['sequence']],
            $rows,
        ), JSON_THROW_ON_ERROR));
    }
}
