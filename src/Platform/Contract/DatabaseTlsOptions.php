<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

interface DatabaseTlsOptions
{
    /** @return array<int, mixed> */
    public function options(#[\SensitiveParameter] array $configuration): array;
}
