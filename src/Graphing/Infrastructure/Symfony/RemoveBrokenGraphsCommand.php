<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\BrokenGraphReport;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\RemoveBrokenGraphs;
use Kadupul\Graphing\Application\Port\BrokenGraphGroup;
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
use Symfony\Component\Console\Terminal;

/** Console command that replaces remove_broken_graphs.php. The old argument spelling and output are kept through RemoveBrokenGraphsLegacyArguments; the work is done by RemoveBrokenGraphs. */
#[AsCommand(name: 'kadupul:graph:remove-broken', description: 'Report or remove the graphs that lack data sources.')]
final readonly class RemoveBrokenGraphsCommand
{
    private const string UTILITY = 'Kadupul Remove Broken Graphs Utility';

    public function __construct(
        private RemoveBrokenGraphs $removeBroken,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] RemoveBrokenGraphsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new RemoveBrokenGraphsLegacyArguments();
        $early = $this->renderer->preflight($this->presentation->legacy, $this->version, self::UTILITY, $legacy, $input->as, $io, $output, $mode);
        if ($early !== null) {
            return $early;
        }
        if ($input->report && $input->remove) {
            $message = 'The options --report and --remove are mutually exclusive.';

            return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(
                ['status' => 'invalid', 'error' => $message],
                [...$legacy->exclusive(), $this->version->line(self::UTILITY), ...$legacy->help()],
                $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID
            ));
        }
        try {
            $report = ($this->removeBroken)($input->local, $input->as, $input->remove);
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Broken graph removal failed');
        }

        return $this->report($io, $output, $mode, $report, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, BrokenGraphReport $report, RemoveBrokenGraphsLegacyArguments $legacy): int
    {
        $exit = $report->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Legacy) {
            return $this->renderer->render(new CommandResult([], $legacy->run($report, (new Terminal())->getWidth()), $exit), $mode, $output);
        }
        if ($mode === OutputMode::Json) {
            $json = ['status' => $report->succeeded() ? 'ok' : 'partial', 'database' => $report->database === OperatorDatabase::Main ? 'main' : 'local', 'removing' => $report->removing,
                'broken' => $report->graphCount(), 'removed' => $report->removed,
                'templates' => array_map(static fn(BrokenGraphGroup $group): array => ['graph_template_id' => $group->graphTemplateId, 'name' => $group->name,
                    'graphs' => array_map(static fn($graph): int => $graph->localGraphId, $group->graphs)], $report->groups)];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        $broken = $report->graphCount();
        if (!$report->removing) {
            $io->success(sprintf('%d broken graph(s) in %d graph template(s).', $broken, count($report->groups)));

            return $exit;
        }

        return $this->renderer->summary($io, sprintf('Removed %d of %d broken graph(s)', $report->removed, $broken), $broken - $report->removed);
    }
}
