<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Application\Port;

use Kadupul\AggregateTemplate\Domain\AggregateTemplateCriteria;

interface AggregateTemplateCatalog
{
    /** @return array{rows:list<array<string,mixed>>, total:int} */
    public function list(AggregateTemplateCriteria $criteria): array;

    /** @return array<string,mixed>|null */
    public function editData(?int $id, int $sourceTemplateId = 0): ?array;
}
