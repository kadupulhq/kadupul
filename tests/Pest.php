<?php

/*
 * Pest bootstrap.
 */

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

uses(PHPUnit\Framework\TestCase::class, PestCodeCoverageCompatibility::class)->in('Unit', 'integration', 'mutation', 'handoff');
