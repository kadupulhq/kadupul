<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** One graph a run created, found already there, or was refused. */
final readonly class GraphResult
{
    public const string CREATED = 'created';
    public const string EXISTS = 'exists';
    public const string REFUSED = 'refused';

    public function __construct(public string $kind, public ?int $graphId = null, public string $dataSources = '') {}
}
