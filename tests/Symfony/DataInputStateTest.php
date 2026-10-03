<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\DataInput\Domain\DataInputState;
use PHPUnit\Framework\TestCase;

final class DataInputStateTest extends TestCase
{
    public function testRawCommandsAndPlaceholderOrderingArePreserved(): void
    {
        $command = '  perl <path_cacti>/script.pl <second> <first> <second>  ';
        self::assertSame($command, DataInputState::method(['name' => 'Example', 'input_string' => $command, 'type_id' => 1])['input_string']);
        self::assertSame(['second', 'first'], DataInputState::placeholders($command));
    }
    public function testRevisionIncludesChildSequenceAndValuesAndNormalizesPdoScalars(): void
    {
        $parent = ['id' => 1, 'name' => 'A', 'input_string' => '<x>', 'type_id' => 1];
        $fields = [['id' => 2, 'sequence' => 1, 'data_name' => 'x']];
        $revision = DataInputState::revision($parent, $fields);
        self::assertSame($revision, DataInputState::revision(['id' => '1', 'name' => 'A', 'input_string' => '<x>', 'type_id' => '1'], [['id' => '2', 'sequence' => '1', 'data_name' => 'x']]));
        $fields[0]['sequence'] = 2;
        self::assertNotSame($revision, DataInputState::revision($parent, $fields));
        $fields[0]['data_name'] = 'changed';
        self::assertNotSame($revision, DataInputState::revision($parent, $fields));
    }
    public function testMalformedValuesAreRejectedBeforeCasting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DataInputState::method(['name' => ['bad'], 'type_id' => 1]);
    }
    public function testOutputFieldOnlyKeepsApplicableOptions(): void
    {
        $field = DataInputState::field(['name' => 'Result', 'data_name' => 'output', 'input_output' => 'out', 'update_rra' => true, 'allow_nulls' => true, 'type_code' => 'hostname', 'regexp_match' => 'x']);
        self::assertSame('on', $field['update_rra']);
        self::assertSame('', $field['allow_nulls']);
        self::assertSame('', $field['type_code']);
        self::assertSame('', $field['regexp_match']);
    }
    public function testLegacyOutputFieldNamesArePreserved(): void
    {
        $field = DataInputState::field(['name' => 'Legacy output', 'data_name' => 'result-total', 'input_output' => 'out']);
        self::assertSame('result-total', $field['data_name']);
    }

}
