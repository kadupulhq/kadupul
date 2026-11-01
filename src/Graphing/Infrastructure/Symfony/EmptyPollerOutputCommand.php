<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\EmptyPollerOutput;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\PollerOutputEmptying;
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

/** Console command for cli/poller_output_empty.php: writes the queued poller_output samples to their RRD files. */
#[AsCommand(name: 'kadupul:poller-output:empty', description: 'Write every queued poller output sample to its RRD file.')]
final readonly class EmptyPollerOutputCommand
{
    private const string UTILITY = 'Kadupul Process Poller Output Utility';

    public function __construct(
        private EmptyPollerOutput $empty,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    /**
     * @return int Command::SUCCESS when the queue was emptied, Command::FAILURE otherwise
     * @throws \JsonException when the result cannot be encoded
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] EmptyPollerOutputInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new EmptyPollerOutputLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        try {
            // The original printed what rrdtool and the poller code said as it went; only a shim reproduces that.
            $relay = $mode === OutputMode::Legacy
                ? static fn(string $stream, string $text) => $stream === 'err' ? $io->getErrorStyle()->write($text, false, OutputInterface::OUTPUT_RAW) : $output->write($text, false, OutputInterface::OUTPUT_RAW)
                : null;
            $emptying = ($this->empty)($input->as, $relay);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Emptying the poller output failed');
        }

        return $this->report($io, $output, $mode, $emptying, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, PollerOutputEmptying $emptying, EmptyPollerOutputLegacyArguments $legacy): int
    {
        $exit = $emptying->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Json) {
            $json = ['status' => $emptying->status, 'database' => $emptying->database === OperatorDatabase::Main ? 'main' : 'local', 'updates' => $emptying->succeeded() ? $emptying->updates : null];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($mode === OutputMode::Legacy) {
            if ($emptying->succeeded()) {
                return $this->renderer->render(new CommandResult([], $legacy->success($emptying), $exit), $mode, $output);
            }
            // The original wrote its failures to stderr, so a script that parses stdout sees nothing.
            foreach ($legacy->errors($emptying) as $line) {
                $io->getErrorStyle()->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            return $exit;
        }
        if ($emptying->succeeded()) {
            $io->success(sprintf('There were %d RRD updates made this pass.', $emptying->updates));
        } else {
            $io->getErrorStyle()->error($legacy->errors($emptying)[0]);
        }

        return $exit;
    }
}
