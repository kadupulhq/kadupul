<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache;
use Kadupul\IdentityAccess\Application\Query\InspectInvalidatedRowCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'kadupul:maintenance:row-cache', description: 'Inspect invalidated row-count cache entries, or explicitly clean one bounded batch.')]
final class RowCacheCommand extends Command
{
    public function __construct(private readonly InspectInvalidatedRowCache $inspect, private readonly CleanInvalidatedRowCache $clean, private readonly RowCacheState $state, private readonly ?string $enabled)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Delete at most 1,000 stale rows per class. Stop the scheduled worker first.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable result.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = $input->getOption('execute');
        $json = $input->getOption('json');
        $lock = null;
        try {
            if ($execute) {
                $lock = $this->state->lock();
                if (!$lock->acquire()) {
                    $this->failure($output, $json, 'busy', 'Cleanup is already owned by a worker or another command. Stop it before manual cleanup.');

                    return Command::FAILURE;
                }
            }
            $deleted = $execute ? ($this->clean)() : 0;
            $classes = [];
            $remaining = 0;
            foreach (($this->inspect)() as $entry) {
                $classes[] = ['class' => $entry->invalidation->class, 'before' => $entry->invalidation->before, 'rows' => $entry->rows];
                $remaining += $entry->rows;
            }
            $result = ['status' => 'ok', 'mode' => $execute ? 'cleanup' : 'inspect', 'scheduler_configured' => $this->enabled === '1', 'deleted' => $deleted, 'remaining' => $remaining, 'classes' => $classes];
            if ($json) {
                $output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            } else {
                $output->writeln(sprintf('%s: %d deleted; %d stale rows remain.', $execute ? 'Cleanup' : 'Inspection', $deleted, $remaining));
                foreach ($classes as $class) {
                    $output->writeln(sprintf('%s: %d stale rows before epoch %d', OutputFormatter::escape($class['class']), $class['rows'], $class['before']));
                }
            }

            return Command::SUCCESS;
        } catch (\Throwable) {
            $this->failure($output, $json, 'failed', $execute
                ? 'Cleanup could not be completed. Some deletions may have committed; inspect again before retrying.'
                : 'Cache inspection failed. Check the primary installation configuration and database.');

            return Command::FAILURE;
        } finally {
            $lock?->release();
        }
    }

    private function failure(OutputInterface $output, bool $json, string $status, string $message): void
    {
        $output->writeln($json ? json_encode(['status' => $status, 'error' => $message], JSON_THROW_ON_ERROR) : '<error>' . $message . '</error>', $json ? OutputInterface::OUTPUT_RAW : OutputInterface::OUTPUT_NORMAL);
    }
}
