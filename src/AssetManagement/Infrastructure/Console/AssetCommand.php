<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Console;

use Kadupul\AssetManagement\Application\AssetRegistry;
use Kadupul\AssetManagement\Application\ViewAssets;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetData;
use Kadupul\AssetManagement\Domain\AssetFields;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console entry point for the asset register: shows it, applies one JSON command from stdin, or serves the web adapter's worker request.
 * It prints a single JSON line with status ok or failed.
 */
#[AsCommand(name: 'assets:register', description: 'Inspect the asset pilot or apply one bounded JSON command from stdin.')]
final class AssetCommand extends Command
{
    /**
     * Binds the command to the registry, the view, the operator selector and the console access check.
     */
    public function __construct(private readonly AssetRegistry $registry, private readonly ViewAssets $view, private readonly ConsoleOperator $operator, private readonly CliAssetAccess $access)
    {
        parent::__construct();
    }

    /**
     * Declares the operator, apply and worker options.
     */
    protected function configure(): void
    {
        $this->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Account used for authorization and attribution.')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Apply a JSON command from stdin.')
            ->addOption('worker', null, InputOption::VALUE_NONE, 'Receive an authenticated web adapter envelope on stdin.');
    }

    /**
     * Reads any stdin command, selects the operator and runs the view or the command.
     * Failures are reported as a JSON reason code, never as an exception; the exit status is non-zero on failure.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $username = $input->getOption('operator');
            $command = null;
            $session = null;
            $this->access->expectedActor = null;
            if ($input->getOption('apply') === true || $input->getOption('worker') === true) {
                $stream = fopen('php://stdin', 'rb');
                if ($stream === false) {
                    throw new AssetError('invalid_command');
                }
                try {
                    $json = stream_get_contents($stream, 524289);
                } finally {
                    fclose($stream);
                }
                if (!is_string($json) || strlen($json) > 524288) {
                    throw new AssetError('invalid_command');
                }
                $command = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
                if (!is_array($command)) {
                    throw new AssetError('invalid_command');
                }
                if ($input->getOption('worker') === true) {
                    if (array_keys($command) !== ['actor_id', 'credential', 'command'] || !is_string($command['credential'])) {
                        throw new AssetError('invalid_command');
                    }
                    // Select the web session's account by id and credential:
                    // the same username may exist in several auth realms.
                    $session = [AssetFields::integer($command['actor_id'], 2147483647), $command['credential']];
                    $this->access->expectedActor = $session[0];
                    $command = $command['command'];
                    if (!is_array($command)) {
                        throw new AssetError('invalid_command');
                    }
                }
            }
            if ($session !== null) {
                $this->operator->selectSession($session[0], $session[1], OperatorDatabase::Local);
            } else {
                $this->operator->select(is_string($username) ? $username : null, OperatorDatabase::Local);
            }
            $result = $command === null ? ($this->view)() : $this->registry->apply(AssetData::object($command));
            $output->writeln(json_encode(['status' => 'ok', 'result' => $result], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (AssetError $error) {
            $reason = $error->reason;
        } catch (\JsonException) {
            $reason = 'invalid_command';
        } catch (\Throwable) {
            $reason = 'unavailable';
        }
        $output->writeln(json_encode(['status' => 'failed', 'reason' => $reason], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        return Command::FAILURE;
    }
}
