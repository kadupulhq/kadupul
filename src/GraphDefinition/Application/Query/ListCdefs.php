<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Application\Query;

use Kadupul\GraphDefinition\Application\Port\CdefCatalog;
use Kadupul\GraphDefinition\Domain\CdefListCriteria;

final readonly class ListCdefs
{
    public function __construct(private CdefAuthorization $authorization, private CdefCatalog $catalog) {}

    /** @return array{rows:list<\Kadupul\GraphDefinition\Domain\CdefSummary>,total:int} */
    public function __invoke(CdefListCriteria $criteria): array
    {
        $this->authorization->actor();

        return ['rows' => $this->catalog->list($criteria), 'total' => $this->catalog->count($criteria)];
    }
}
