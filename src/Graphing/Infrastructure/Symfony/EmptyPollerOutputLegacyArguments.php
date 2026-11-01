<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\PollerOutputEmptying;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/**
 * poller_output_empty.php took no argument but --version and --help (each in
 * three spellings, with or without a "=value" it ignored) and stopped at the
 * first argument it did not know, printing the error and its help to stdout.
 * Its failures went to stderr; only the success line went to stdout.
 */
final class EmptyPollerOutputLegacyArguments extends LegacyArguments
{
    /** @return array<string, array{0: null, 1: false}> */
    #[\Override]
    protected function flags(): array
    {
        return self::versionAndHelp();
    }

    /** @return list<string> */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: poller_output_empty.php', '', 'A utility to process the poller output table.  This tool is deprecated but should work.', ''];
    }

    /**
     * The lines the original wrote to stderr for a pass that ended badly.
     *
     * @return list<string>
     */
    public function errors(PollerOutputEmptying $emptying): array
    {
        return match ($emptying->status) {
            PollerOutputDrainResult::RRD_UNAVAILABLE => ['ERROR: RRD initialization failed; queued samples retained for retry.'],
            PollerOutputDrainResult::COUNT_UNAVAILABLE => ['ERROR: Poller output count is unavailable; samples retained for retry.'],
            PollerOutputDrainResult::NO_PROGRESS => ['ERROR: Poller output made no progress; remaining samples retained for retry.'],
            PollerOutputDrainResult::FAILED => ['ERROR: Poller output could not be processed; unprocessed samples remain queued.'],
            default => [],
        };
    }

    /**
     * The line the original wrote to stdout after a pass that emptied the queue.
     *
     * @return list<string>
     */
    public function success(PollerOutputEmptying $emptying): array
    {
        return ['There were ' . $emptying->updates . ' RRD updates made this pass'];
    }
}
