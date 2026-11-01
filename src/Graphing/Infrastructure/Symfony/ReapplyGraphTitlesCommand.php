<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphTitleReport;
use Kadupul\Graphing\Application\Command\ReapplyGraphTitles;
use Kadupul\Graphing\Domain\GraphSelection;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/** Console command that replaces poller_graphs_reapply_names.php. The old argument spelling and output are kept through ReapplyGraphTitlesLegacyArguments; the work is done by ReapplyGraphTitles. */
#[AsCommand(name: 'kadupul:graph:reapply-titles', description: 'Reapply the graph naming rules to existing graphs in bulk.')]
final readonly class ReapplyGraphTitlesCommand
{
    private const string UTILITY = 'Kadupul Reapply graph Names Utility';
    private const string BAD_HOSTS = 'Specify --host-id=all or a comma-separated list of positive device IDs.';

    public function __construct(
        private ReapplyGraphTitles $reapply,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] ReapplyGraphTitlesInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new ReapplyGraphTitlesLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->hostId === null && $input->filter === null && !$input->debug && $mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], [...$legacy->noInput(), $this->version->line(self::UTILITY), ...$legacy->help()], Command::FAILURE), $mode, $output);
        }
        $selection = GraphSelection::parse($input->hostId ?? '', $input->filter ?? '');
        if ($selection === null) {
            if ($mode === OutputMode::Legacy) {
                // The original wrote this to stderr, then exited 1 without the help.
                ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln('ERROR: ' . self::BAD_HOSTS, OutputInterface::OUTPUT_RAW);

                return Command::FAILURE;
            }

            return $this->renderer->failure($io, $output, $mode, self::BAD_HOSTS, new CommandResult(['status' => 'invalid', 'error' => self::BAD_HOSTS], [], Command::INVALID));
        }
        try {
            $report = ($this->reapply)($selection, $input->local, $input->as, !$input->dryRun);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Graph rename failed');
        }

        return $this->report($io, $output, $mode, $input, $report, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, ReapplyGraphTitlesInput $input, GraphTitleReport $report, ReapplyGraphTitlesLegacyArguments $legacy): int
    {
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Legacy) {
            [$lines, $newline] = $legacy->run($report->graphs, $input->debug);

            return $this->renderer->render(new CommandResult([], $lines, $exit, $newline), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'partial', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'dry_run' => $report->dryRun,
                'selected' => count($report->graphs), 'reapplied' => $report->reapplied,
                'graphs' => array_map(static fn($graph): int => $graph->localGraphId, $report->graphs)];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $count = count($report->graphs);
        if ($report->dryRun) {
            $io->success(sprintf('Would reapply the naming rules to %d graph(s).', $count));

            return $exit;
        }

        return $this->renderer->summary($io, sprintf('Reapplied the naming rules to %d of %d graph(s)', $report->reapplied, $count), $count - $report->reapplied);
    }
}
