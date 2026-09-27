<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Application\ReadModel;

final readonly class NetworkPage
{
    /** @param list<NetworkSummary> $networks */
    public function __construct(public array $networks, public bool $hasNext) {}
}
