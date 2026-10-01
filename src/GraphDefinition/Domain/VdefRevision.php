<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final class VdefRevision
{
    /** @param list<array{id:int, sequence:int, type:int, value:string}> $items */
    public static function fromState(string $name, array $items): string
    {
        return hash('sha256', json_encode([
            $name,
            array_map(static fn(array $item): array => [
                (int) $item['id'], (int) $item['sequence'], (int) $item['type'], (string) $item['value'],
            ], $items),
        ], JSON_THROW_ON_ERROR));
    }
}
