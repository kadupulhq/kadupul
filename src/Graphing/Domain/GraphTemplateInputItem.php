<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Domain;

/** A template item offered for association, as the legacy item list showed it. */
final readonly class GraphTemplateInputItem
{
    /** graph_templates_item.id is an int unsigned. */
    public const int MAX_ID = 4294967295;

    /** The RRDtool names of include/global_arrays.php's $graph_item_types; never translated. */
    private const array TYPES = [1 => 'COMMENT', 2 => 'HRULE', 3 => 'VRULE', 4 => 'LINE1', 5 => 'LINE2', 6 => 'LINE3', 7 => 'AREA', 8 => 'AREA:STACK',
        9 => 'GPRINT', 10 => 'LEGEND', 11 => 'GPRINT:LAST', 12 => 'GPRINT:MAX', 13 => 'GPRINT:MIN', 14 => 'GPRINT:AVERAGE', 15 => 'LEGEND_CAMM',
        20 => 'LINE:STACK', 30 => 'TICK', 40 => 'TEXTALIGN'];
    private const array FUNCTIONS = [1 => 'AVERAGE', 2 => 'MIN', 3 => 'MAX', 4 => 'LAST'];

    public function __construct(public int $id, public int $position, public int $graphTypeId, public int $consolidationFunctionId, public bool $selected) {}

    /** An unknown stored ID shows as the number instead of an empty name. */
    public function typeName(): string
    {
        return self::TYPES[$this->graphTypeId] ?? (string) $this->graphTypeId;
    }

    public function consolidationName(): string
    {
        return self::FUNCTIONS[$this->consolidationFunctionId] ?? (string) $this->consolidationFunctionId;
    }

    /** Legacy printed every item in bold except a plain GPRINT. */
    public function emphasised(): bool
    {
        return $this->graphTypeId !== 9;
    }
}
