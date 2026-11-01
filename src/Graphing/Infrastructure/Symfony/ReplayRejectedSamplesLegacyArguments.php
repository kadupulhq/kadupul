<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\RejectedSampleReplay;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyArguments;

/**
 * replay_rejected_samples.php split each argument at the first "=" that was
 * not its first character and acted on it in order: --local-data-id took a
 * positive integer (the last one wins), --all and --dry-run ignored any value,
 * the version and help flags ended the run, and anything else was an error
 * printed with the help. Failures went to stderr; the result line to stdout.
 */
final class ReplayRejectedSamplesLegacyArguments extends LegacyArguments
{
    /** The id must be digits only and not zero; the original tested ctype_digit() and (int) < 1. */
    private const string POSITIVE_INTEGER = '/^0*[1-9][0-9]*$/D';

    /** @return array<string, array{0: ?string, 1: bool, 2?: string}> */
    #[\Override]
    protected function flags(): array
    {
        return ['--local-data-id' => ['local-data-id', true, self::POSITIVE_INTEGER], '--all' => ['all', false], '--dry-run' => ['dry-run', false], ...self::versionAndHelp()];
    }

    /** @return list<string> */
    #[\Override]
    public function invalid(string $argument): array
    {
        // Only --local-data-id has a value the original checked, so it is the one flag that reaches here by its own name.
        if (explode('=', $argument, 2)[0] === '--local-data-id') {
            return ['ERROR: --local-data-id requires a positive integer', ''];
        }

        return parent::invalid($argument);
    }

    /** @return list<string> */
    #[\Override]
    public function help(): array
    {
        return ['', 'usage: replay_rejected_samples.php --local-data-id=N | --all [--dry-run]', '',
            'Returns samples from poller_output_rejected to poller_output for the next',
            'poller drain.  Repair the RRDfile first or the samples will be rejected again.', '',
            '--local-data-id=N  Replay one data source',
            '--all              Replay every data source',
            '--dry-run          Report the count without moving anything', ''];
    }

    /**
     * What the original printed when it was not told exactly one scope, before its help.
     *
     * @return list<string>
     */
    public function scopeError(): array
    {
        return ['ERROR: Specify exactly one of --local-data-id=N or --all', ''];
    }

    /**
     * The lines the original wrote to stderr for a run that moved nothing.
     *
     * @return list<string>
     */
    public function errors(RejectedSampleReplay $replay): array
    {
        return match ($replay->status) {
            RejectedSampleReplayResult::REMOTE_COLLECTOR => ['ERROR: This collector queues samples in the main database. Run replay_rejected_samples.php on the main data collector.'],
            RejectedSampleReplayResult::UNSAFE_QUEUE => ['ERROR: Rejected samples were not replayed. ' . $replay->reason],
            RejectedSampleReplayResult::FAILED => ['ERROR: Unable to replay rejected samples; nothing was moved.'],
            default => [],
        };
    }

    /**
     * The line the original wrote to stdout after a run that went through.
     *
     * @return list<string>
     */
    public function success(RejectedSampleReplay $replay): array
    {
        $scope = $replay->localDataId === null ? 'all data sources' : 'Local Data ID ' . $replay->localDataId;

        return [($replay->dryRun ? 'Would replay ' : 'Replayed ') . $replay->samples . ' rejected samples for ' . $scope];
    }
}
