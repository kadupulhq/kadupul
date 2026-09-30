<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure;

use Kadupul\Platform\Contract\DatabaseTlsOptions;

final readonly class StrictDatabaseTlsOptions implements DatabaseTlsOptions
{
    public function options(#[\SensitiveParameter] array $configuration): array
    {
        return DatabaseTls::options($configuration);
    }
}
