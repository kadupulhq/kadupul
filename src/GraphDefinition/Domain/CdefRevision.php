<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final class CdefRevision
{
    public static function fromRows(array $parent, array $items): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $parent['id'], 'hash' => (string) $parent['hash'],
            'system' => (int) $parent['system'], 'name' => (string) $parent['name'],
            'items' => array_map(static fn(array $item): array => [
                'id' => (int) $item['id'], 'hash' => (string) $item['hash'],
                'cdef_id' => (int) $item['cdef_id'], 'sequence' => (int) $item['sequence'],
                'type' => (int) $item['type'], 'value' => $item['value'] === null ? null : (string) $item['value'],
            ], $items),
        ], JSON_THROW_ON_ERROR));
    }
}
