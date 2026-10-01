<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

use Kadupul\GraphDefinition\Domain\VdefListCriteria;
use Kadupul\GraphDefinition\Domain\VdefSummary;

interface VdefCatalog
{
    /** @return list<VdefSummary> */
    public function list(VdefListCriteria $criteria): array;

    public function count(VdefListCriteria $criteria): int;

    /** @return array{id:int,name:string,revision:string,items:list<array{id:int,sequence:int,type:int,value:string,label:string}>}|null */
    public function find(int $id): ?array;

    /** @return array<int,array{id:int,name:string,revision:string}> */
    public function selected(array $ids): array;

    public function preview(int $id): string;

}
