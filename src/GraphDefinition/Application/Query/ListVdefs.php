<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Domain\VdefListCriteria;

final readonly class ListVdefs
{
    public function __construct(private VdefAuthorization $authorization, private VdefCatalog $catalog) {}

    /** @return array{rows:list<\Kadupul\GraphDefinition\Domain\VdefSummary>,total:int} */
    public function __invoke(VdefListCriteria $criteria): array
    {
        $this->authorization->actor();
        return ['rows' => $this->catalog->list($criteria), 'total' => $this->catalog->count($criteria)];
    }
}
