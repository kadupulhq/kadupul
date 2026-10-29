<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'kadupul:boost:run', description: 'Run one Boost cycle: rotate, flush and clean up archives.')]
final class BoostRunCommand extends Command
{
    public const int BUSY = 2;

    public function __construct(private readonly BoostRunHandler $handler, private readonly BoostState $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Run even when no cycle is due.');
        $this->addOption('debug', null, InputOption::VALUE_NONE, 'Pass --debug to the Boost master.');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable result and hide the master output. Exit 2 when a scheduler worker or another run owns the cycle.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        $lock = $this->state->lock();
        if (!$lock->acquire()) {
            $this->report($output, $json, ['status' => 'busy', 'error' => 'A Boost cycle is already owned by the scheduler worker or another run. Stop it before running manually.']);

            return self::BUSY;
        }
        try {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $progress = $json ? null : static function (string $type, string $chunk) use ($output, $errors): void {
                // The master prints plain log lines; keep their brackets from being read as markup.
                ($type === 'err' ? $errors : $output)->write(OutputFormatter::escape($chunk), false, OutputInterface::OUTPUT_RAW);
            };
            $run = $this->handler->run($input->getOption('force'), $input->getOption('debug'), $progress);
            if ($run['ok']) {
                $this->report($output, $json, ['status' => 'ok'], 'Boost cycle finished.');

                return Command::SUCCESS;
            }
            $this->report($output, $json, ['status' => 'failed', 'error' => 'The Boost master failed. Check the Boost log and the poller log.']);
        } catch (\Throwable) {
            $this->report($output, $json, ['status' => 'failed', 'error' => 'The Boost master could not be run. Check the installation configuration.']);
        } finally {
            $lock->release();
        }

        return Command::FAILURE;
    }

    /** @param array<string, string> $result */
    private function report(OutputInterface $output, bool $json, array $result, ?string $text = null): void
    {
        if ($json) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $output->writeln($result['status'] === 'ok' ? (string) $text : '<error>' . $result['error'] . '</error>');
        }
    }
}
