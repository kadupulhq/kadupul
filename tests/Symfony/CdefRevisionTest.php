<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Domain\CdefRevision;
use PHPUnit\Framework\TestCase;

final class CdefRevisionTest extends TestCase
{
    public function testEveryStoredFieldAndOrderedMembershipChangesTheRevision(): void
    {
        $parent = ['id' => 2, 'hash' => 'parent', 'system' => 0, 'name' => 'Name'];
        $items = [['id' => 21, 'hash' => 'child', 'cdef_id' => 2, 'sequence' => 1, 'type' => 6, 'value' => 'Value']];
        $revision = CdefRevision::fromRows($parent, $items);
        foreach (['id' => 3, 'hash' => 'replaced', 'system' => 1, 'name' => 'Renamed'] as $field => $value) {
            self::assertNotSame($revision, CdefRevision::fromRows(array_replace($parent, [$field => $value]), $items), $field);
        }
        foreach (['id' => 22, 'hash' => 'replaced', 'cdef_id' => 3, 'sequence' => 2, 'type' => 4, 'value' => null] as $field => $value) {
            self::assertNotSame($revision, CdefRevision::fromRows($parent, [array_replace($items[0], [$field => $value])]), $field);
        }
        self::assertNotSame(CdefRevision::fromRows($parent, [array_replace($items[0], ['value' => null])]), CdefRevision::fromRows($parent, [array_replace($items[0], ['value' => ''])]));
        self::assertNotSame($revision, CdefRevision::fromRows($parent, []));
        $second = array_replace($items[0], ['id' => 22, 'hash' => 'second', 'sequence' => 2]);
        self::assertNotSame(CdefRevision::fromRows($parent, [$items[0], $second]), CdefRevision::fromRows($parent, [$second, $items[0]]));
        self::assertSame($revision, CdefRevision::fromRows(array_map('strval', $parent), [array_map('strval', $items[0])]));
    }
}
