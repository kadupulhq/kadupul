<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\GraphDefinition\Domain\VdefSummary;

/** Read access to VDEFs: summaries for the list, full detail for the editor, and a preview of the resulting expression. */
interface VdefCatalog
{
    /** @return list<VdefSummary> */
    public function list(VdefListCriteria $criteria): array;

    /** The number of VDEFs that match the criteria, ignoring paging. */
    public function count(VdefListCriteria $criteria): int;

    /** @return array{id:int,name:string,revision:string,items:list<array{id:int,sequence:int,type:int,value:string,label:string}>}|null */
    public function find(int $id): ?array;

    /** @return array<int,array{id:int,name:string,revision:string}> */
    public function selected(array $ids): array;

    /** The VDEF's expression as the text RRDtool receives, with nested VDEFs expanded. */
    public function preview(int $id): string;

}
