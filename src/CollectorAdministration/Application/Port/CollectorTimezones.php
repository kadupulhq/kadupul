<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Port;

interface CollectorTimezones
{
    /** @return list<array{label: string, value: string}> */
    public function search(string $term): array;
}
