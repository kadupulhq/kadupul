<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** Opaque digest of a definition and its items; any change makes an open form stale. */
final class CdefRevision
{
    /**
     * @param array<string, mixed> $parent id, hash, system and name
     * @param list<array<string, mixed>> $items id, hash, sequence, type and value in sequence order
     */
    public static function of(array $parent, array $items): string
    {
        return hash('sha256', json_encode([
            (int) $parent['id'], (string) $parent['hash'], (int) $parent['system'], (string) $parent['name'],
            array_map(static fn(array $item): array => [
                (int) $item['id'], (string) $item['hash'], (int) $item['sequence'], (int) $item['type'], (string) $item['value'],
            ], $items),
        ], JSON_THROW_ON_ERROR));
    }
}
