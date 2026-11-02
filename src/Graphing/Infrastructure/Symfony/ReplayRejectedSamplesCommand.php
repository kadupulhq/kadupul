<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\RejectedSampleReplay;
use Kadupul\Graphing\Application\Command\ReplayRejectedSamples;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Console command for cli/replay_rejected_samples.php: returns rejected poller samples to the poller_output queue. */
#[AsCommand(name: 'kadupul:rejected-samples:replay', description: 'Return rejected poller samples to the poller output queue for the next drain.')]
final readonly class ReplayRejectedSamplesCommand
{
    private const string UTILITY = 'Kadupul Rejected Sample Replay Utility';

    public function __construct(
        private ReplayRejectedSamples $replay,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    /**
     * @return int Command::SUCCESS when the samples were replayed or counted, Command::FAILURE when the run moved nothing, Command::INVALID for a bad scope
     * @throws \JsonException when the result cannot be encoded
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ReplayRejectedSamplesInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new ReplayRejectedSamplesLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->localDataId !== null && preg_match('/^0*[1-9][0-9]*$/D', $input->localDataId) !== 1) {
            return $this->invalid($io, $output, $mode, 'The --local-data-id option needs a positive integer.', [...$legacy->invalid('--local-data-id'), $this->version->line(self::UTILITY), ...$legacy->help()]);
        }
        // The original compared the two as booleans: exactly one scope, or an error with its help.
        if ($input->all === ($input->localDataId !== null)) {
            return $this->invalid($io, $output, $mode, 'Specify exactly one of --local-data-id=N or --all.', [...$legacy->scopeError(), $this->version->line(self::UTILITY), ...$legacy->help()]);
        }
        try {
            // The original cast the digits with (int), which saturates; a number past the integer range is the largest integer, as there.
            // The original printed what the poller code said as it went; only a shim reproduces that.
            $relay = $mode === OutputMode::Legacy
                ? static fn(string $stream, string $text) => $stream === 'err' ? $io->getErrorStyle()->write($text, false, OutputInterface::OUTPUT_RAW) : $output->write($text, false, OutputInterface::OUTPUT_RAW)
                : null;
            $replay = ($this->replay)($input->as, $input->localDataId === null ? null : (int) $input->localDataId, $input->dryRun, $relay);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Replaying the rejected samples failed');
        }

        return $this->report($io, $output, $mode, $replay, $legacy);
    }

    /**
     * @param list<string> $lines what the original printed to stdout
     */
    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message, array $lines): int
    {
        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], $lines, $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, RejectedSampleReplay $replay, ReplayRejectedSamplesLegacyArguments $legacy): int
    {
        $exit = $replay->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Json) {
            $json = ['status' => $replay->status, 'database' => $replay->database === OperatorDatabase::Main ? 'main' : 'local', 'dry_run' => $replay->dryRun,
                'local_data_id' => $replay->localDataId, 'samples' => $replay->succeeded() ? $replay->samples : null];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($mode === OutputMode::Legacy) {
            if ($replay->succeeded()) {
                return $this->renderer->render(new CommandResult([], $legacy->success($replay), $exit), $mode, $output);
            }
            // The original wrote its failures to stderr, so a script that parses stdout sees nothing.
            foreach ($legacy->errors($replay) as $line) {
                $io->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            return $exit;
        }
        if ($replay->succeeded()) {
            $io->success($legacy->success($replay)[0] . '.');
        } else {
            $io->getErrorStyle()->error($legacy->errors($replay)[0]);
        }

        return $exit;
    }
}
