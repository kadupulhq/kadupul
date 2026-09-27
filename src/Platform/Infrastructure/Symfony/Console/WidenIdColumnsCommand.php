<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Application\Command\WidenIdColumns;
use Kadupul\Platform\Application\ReadModel\WideningReport;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:database:widen-id-columns', description: 'Widen id columns to int unsigned so auto-increment values do not run out.')]
final readonly class WidenIdColumnsCommand
{
    private const string UTILITY = 'Kadupul Fix Database Range Issue';

    public function __construct(
        private WidenIdColumns $widen,
        private InstallationVersion $version,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
        private ClockInterface $clock,
    ) {}

    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] WidenIdColumnsInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new WidenIdColumnsLegacyArguments();
        try {
            $early = $this->renderer->preflight($this->presentation->legacy, fn(): string => $this->version->line(self::UTILITY, $this->clock->now()), $legacy, $input->as, $io, $output, $mode);
            if ($early !== null) {
                return $early;
            }
            $report = ($this->widen)($input->local, $input->as, !$input->dryRun);
        } catch (InstallationAccessDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            // failed() names only MainDatabaseNotConfigured, by type; other text stays hidden.
            return $this->renderer->failed($io, $output, $mode, $error, 'Column widening failed');
        }

        return $this->report($io, $output, $mode, $report, $input->debug, $legacy);
    }

    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, WideningReport $report, bool $debug, WidenIdColumnsLegacyArguments $legacy): int
    {
        if ($mode === OutputMode::Legacy) {
            // fix_mediumint.php exited 0 whatever its statements did.
            return $this->renderer->render(new CommandResult([], $legacy->report($report, $debug)), $mode, $output);
        }
        $exit = $report->failed() === 0 ? Command::SUCCESS : Command::FAILURE;
        $tables = array_map(static fn(array $step): array => ['name' => $step['table'], 'result' => $step['event']->value, 'statement' => $step['statement']], $report->altered());
        if ($mode === OutputMode::Json) {
            $json = ['status' => $exit === Command::SUCCESS ? 'ok' : 'partial', 'database' => $report->main ? 'main' : 'local', 'dry_run' => $report->dryRun, 'adjusted' => $report->tables(), 'tables' => $tables];

            return $this->renderer->render(new CommandResult($json, [], $exit), $mode, $output);
        }
        if ($tables === []) {
            $io->success('No id column needed widening.');

            return $exit;
        }
        $io->listing(array_map(
            static fn(array $table): string => OutputFormatter::escape($table['name'] . ': ' . $table['result'] . ($table['result'] === 'planned' ? ' (' . $table['statement'] . ')' : '')),
            $tables,
        ));

        return $this->renderer->summary($io, sprintf($report->dryRun ? 'Planned id column changes in %d tables' : 'Widened id columns in %d tables', count($tables) - $report->failed()), $report->failed());
    }
}
