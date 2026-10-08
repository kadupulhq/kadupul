<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\RrdCheck;

/** One problem the RRD checker recorded; a null name means the device or data source is gone. */
final readonly class RrdCheckProblem
{
    public function __construct(
        public int $localDataId,
        public ?string $device,
        public ?string $dataSource,
        public string $message,
        public string $testedAt,
    ) {}
}
