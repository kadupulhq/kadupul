<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Symfony;

use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\AlertingRunOutcome;
use Kadupul\Alerting\Application\EvaluateRules;
use Kadupul\Alerting\Application\Port\AlertingLog;
use Kadupul\Alerting\Application\RunAlerting;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Evaluates alert rules once, then sends queued notifications (ADR 0011).
 * The main poller starts it in the background from poller_bottom; an
 * operator may run it by hand.
 *
 * Exit status: 0 when evaluation completed, alerting is disabled, or another
 * evaluation holds the lock; 2 when the rate source is unavailable
 * (decision 9); 1 for an invalid option or any error, such as an unreachable
 * database or a failed delivery worker. Rules skipped as unusable do not
 * change the status: the run completed and moved past them, and each one is
 * logged as a warning.
 */
#[AsCommand(name: 'kadupul:alerting:evaluate', description: 'Evaluate alert rules once, then send queued alert notifications.')]
final class EvaluateCommand extends Command
{
    public const int RATE_SOURCE_UNAVAILABLE = 2;

    public function __construct(private readonly RunAlerting $run, private readonly AlertingLog $logger)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Rules per batch (1-' . AlertingConfiguration::MAX_BATCH_SIZE . '). Default: the alerting_batch_size setting.');
        $this->addOption('budget', null, InputOption::VALUE_REQUIRED, 'Time budget in seconds for the whole run, checked between batches (0-' . AlertingConfiguration::MAX_BUDGET_SECONDS . '). Default: the alerting_time_budget setting.');
        $this->addOption('delivery-batch-size', null, InputOption::VALUE_REQUIRED, 'Notifications per delivery batch (1-' . AlertingConfiguration::MAX_BATCH_SIZE . ').', (string) RunAlerting::DELIVERY_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $batchSize = self::count($input->getOption('batch-size'), '--batch-size');
            $budget = self::budget($input->getOption('budget'));
            $deliveryBatchSize = self::count($input->getOption('delivery-batch-size'), '--delivery-batch-size') ?? RunAlerting::DELIVERY_BATCH_SIZE;
        } catch (\InvalidArgumentException $invalid) {
            $output->writeln('<error>' . $invalid->getMessage() . '</error>');

            return Command::FAILURE;
        }

        try {
            $run = ($this->run)($batchSize, $budget, $deliveryBatchSize);
        } catch (\Throwable $error) {
            // Driver and query messages can name the server or hold SQL; report the class only.
            $class = (new \ReflectionClass($error))->getShortName();
            $this->logger->error('Alert evaluation failed: {error}', ['error' => $class]);
            $output->writeln('<error>Alert evaluation failed (' . $class . '). Check the database connection and the Kadupul log.</error>');

            return Command::FAILURE;
        }

        if ($run->outcome === AlertingRunOutcome::Disabled) {
            $output->writeln('inactive: alerting is disabled (alerting_enable is off).');

            return Command::SUCCESS;
        }
        if ($run->outcome === AlertingRunOutcome::Busy) {
            $output->writeln('skipped: another alert evaluation is running.');

            return Command::SUCCESS;
        }

        $status = Command::SUCCESS;
        $evaluation = $run->evaluation;
        if ($evaluation?->inactiveReason === EvaluateRules::RATE_SOURCE_UNAVAILABLE) {
            $output->writeln('inactive: rate source unavailable (dsstats_enable must be on).');
            $status = self::RATE_SOURCE_UNAVAILABLE;
        } elseif ($evaluation !== null) {
            $output->writeln(sprintf(
                'Alert rules: %d evaluated, %d already counted, %d skipped as unusable, %d orphaned.',
                $evaluation->evaluated,
                $evaluation->skipped,
                $evaluation->failed,
                $evaluation->orphaned,
            ));
        }
        $delivery = $run->delivery;
        if ($delivery === null) {
            $output->writeln('Notifications: another delivery is running.');
        } else {
            $output->writeln(sprintf(
                'Notifications: %d sent, %d retrying, %d failed, %d suppressed, %d lost.',
                $delivery->sent,
                $delivery->retrying,
                $delivery->failed,
                $delivery->suppressed,
                $delivery->lost,
            ));
        }

        return $status;
    }

    private static function count(mixed $value, string $option): ?int
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]{0,8}$/D', $value) !== 1 || (int) $value > AlertingConfiguration::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException($option . ' must be a whole number from 1 to ' . AlertingConfiguration::MAX_BATCH_SIZE . '.');
        }

        return (int) $value;
    }

    private static function budget(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]{0,8})(\.[0-9]{1,3})?$/D', $value) !== 1 || (float) $value > AlertingConfiguration::MAX_BUDGET_SECONDS) {
            throw new \InvalidArgumentException('--budget must be a number of seconds from 0 to ' . AlertingConfiguration::MAX_BUDGET_SECONDS . ', with at most three decimals.');
        }

        return (float) $value;
    }
}
