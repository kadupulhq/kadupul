<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Port;

use Kadupul\GraphDefinition\Domain\CdefListCriteria;

interface CdefCatalog
{
    /** @return list<\Kadupul\GraphDefinition\Domain\CdefSummary> */
    public function list(CdefListCriteria $criteria): array;

    public function count(CdefListCriteria $criteria): int;

    /** @return list<array{id:int,name:string}> */
    public function references(): array;

    /** @return array<string, string> */
    public function functions(): array;

    /** @return array{id:int,name:string,revision:string,graphs:int,templates:int,referencing_cdefs:int,items:list<array{id:int,sequence:int,type:int,value:string,label:string}>}|null */
    public function find(int $id): ?array;

    public function preview(int $id): string;
}
