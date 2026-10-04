<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Runner\CodeCoverage;
use SebastianBergmann\CodeCoverage\CodeCoverage as CoverageReport;

trait PestCodeCoverageCompatibility
{
    public function getTestResultObject(): object
    {
        return new class {
            public function getCodeCoverage(): ?CoverageReport
            {
                $runner = CodeCoverage::instance();

                return $runner->isActive() ? $runner->codeCoverage() : null;
            }
        };
    }
}
