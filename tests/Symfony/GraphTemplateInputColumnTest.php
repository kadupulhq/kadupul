<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Domain\GraphTemplateInputColumn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GraphTemplateInputColumnTest extends TestCase
{
    /** @return iterable<string, array{GraphTemplateInputColumn, mixed, bool}> */
    public static function values(): iterable
    {
        foreach ([
            [GraphTemplateInputColumn::GraphType, '255', true], [GraphTemplateInputColumn::GraphType, '256', false], [GraphTemplateInputColumn::GraphType, 9, true],
            [GraphTemplateInputColumn::ConsolidationFunction, '-1', false],
            [GraphTemplateInputColumn::DataSource, '4294967295', true], [GraphTemplateInputColumn::DataSource, '4294967296', false], [GraphTemplateInputColumn::DataSource, '1e3', false],
            [GraphTemplateInputColumn::Cdef, '16777215', true], [GraphTemplateInputColumn::Vdef, '16777216', false], [GraphTemplateInputColumn::Gprint, '1 OR 1', false],
            [GraphTemplateInputColumn::Color, '', true], [GraphTemplateInputColumn::Color, '16777215', true], [GraphTemplateInputColumn::Color, '16777216', false],
            [GraphTemplateInputColumn::Alpha, 'Ff', true], [GraphTemplateInputColumn::Alpha, 'FFF', false],
            [GraphTemplateInputColumn::Shift, 'on', true], [GraphTemplateInputColumn::HardReturn, 'yes', false], [GraphTemplateInputColumn::Shift, true, true],
            [GraphTemplateInputColumn::LineWidth, '99,99', true], [GraphTemplateInputColumn::LineWidth, '100', false], [GraphTemplateInputColumn::LineWidth, '1.555', false], [GraphTemplateInputColumn::LineWidth, '', true],
            [GraphTemplateInputColumn::DashOffset, '-8388608', true], [GraphTemplateInputColumn::DashOffset, '8388608', false],
            [GraphTemplateInputColumn::Sequence, '16777215', true], [GraphTemplateInputColumn::Sequence, '16777216', false],
            [GraphTemplateInputColumn::Dashes, '5, 2.5', true], [GraphTemplateInputColumn::Dashes, '5;2', false], [GraphTemplateInputColumn::Dashes, str_repeat('1', 21), false],
            [GraphTemplateInputColumn::TextAlign, 'left', true], [GraphTemplateInputColumn::TextAlign, 'left!', false], [GraphTemplateInputColumn::TextAlign, str_repeat('a', 11), false],
            [GraphTemplateInputColumn::TextFormat, "Inbound\t|", true], [GraphTemplateInputColumn::TextFormat, "a\0b", false], [GraphTemplateInputColumn::Value, str_repeat('é', 255), true],
            [GraphTemplateInputColumn::Value, str_repeat('é', 256), false], [GraphTemplateInputColumn::TextFormat, str_repeat('a', 1021), false], [GraphTemplateInputColumn::TextFormat, "\xC3", false],
            [GraphTemplateInputColumn::TextFormat, null, false], [GraphTemplateInputColumn::GraphType, ['1'], false],
        ] as $index => [$column, $value, $allowed]) {
            yield $index . ' ' . $column->value => [$column, $value, $allowed];
        }
    }

    #[DataProvider('values')]
    public function testAStoredValueMustFitItsGraphItemField(GraphTemplateInputColumn $column, mixed $value, bool $allowed): void
    {
        self::assertSame($allowed, $column->allows($value));
    }

    public function testTheLegacyFunctionDelegatesToTheDomainRule(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/graph_template_input.php';
        foreach (self::values() as [$column, $value, $allowed]) {
            self::assertSame($allowed, \graph_template_input_value_is_allowed($column->value, $value));
        }
        self::assertFalse(\graph_template_input_value_is_allowed('local_graph_id', '1'));
        self::assertFalse(\graph_template_input_value_is_allowed(['alpha'], 'FF'));
    }

    public function testEveryFieldHasALabel(): void
    {
        self::assertCount(17, array_unique(array_map(static fn(GraphTemplateInputColumn $column): string => $column->label(), GraphTemplateInputColumn::cases())));
    }
}
