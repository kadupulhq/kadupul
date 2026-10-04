<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Application\Command\InstallationAccessDenied;
use Kadupul\Platform\Infrastructure\Doctrine\MainDatabaseNotConfigured;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ResultRenderer
{
    public function render(CommandResult $result, OutputMode $mode, OutputInterface $output): int
    {
        if ($mode === OutputMode::Human) {
            throw new \LogicException('Commands write human output through SymfonyStyle.');
        }
        if ($mode === OutputMode::Legacy) {
            // The originals printed everything, errors included, to stdout.
            $last = array_key_last($result->legacy);
            foreach ($result->legacy as $index => $line) {
                if ($index === $last && !$result->finalNewline) {
                    $output->write($line, false, OutputInterface::OUTPUT_RAW);
                } else {
                    $output->writeln($line, OutputInterface::OUTPUT_RAW);
                }
            }
        } else {
            $output->writeln(json_encode($result->json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        }

        return $result->exit;
    }

    /** Only a cli/ shim asks for version or help this way, and it cannot pass --json. */
    public function legacyRequest(LegacyRequest $request, string $versionLine, LegacyArguments $arguments, OutputInterface $output): int
    {
        $lines = [$versionLine];
        if (in_array($request, [LegacyRequest::Help, LegacyRequest::Usage], true)) {
            $lines = [...$lines, ...$arguments->help()];
        }

        return $this->render(new CommandResult([], $lines, $request === LegacyRequest::Usage ? Command::FAILURE : Command::SUCCESS), OutputMode::Legacy, $output);
    }

    /**
     * What a write command does before its use case: a cli/ shim's version or
     * help request, and an empty --as=, which the use case would only see as a
     * denial. Null means the command goes on to run. The version line is read
     * only for a version or help request, so a normal run does not touch
     * include/cacti_version or the version table here.
     */
    public function preflight(LegacyRequest $request, InstallationVersion $version, string $utility, LegacyArguments $arguments, ?string $as, SymfonyStyle $io, OutputInterface $output, OutputMode $mode): ?int
    {
        if ($request !== LegacyRequest::Run) {
            return $this->legacyRequest($request, $version->line($utility), $arguments, $output);
        }

        return $as === '' ? $this->emptyOperator($io, $output, $mode) : null;
    }

    /** The human ending of a write command: success, or a warning naming the failures. */
    public function summary(SymfonyStyle $io, string $summary, int $failed): int
    {
        if ($failed === 0) {
            $io->success($summary . '.');

            return Command::SUCCESS;
        }
        $io->warning(sprintf('%s; %d failed.', $summary, $failed));

        return Command::FAILURE;
    }

    /**
     * The --json result of a command that changes the schema. $fields follow
     * the three keys every such command shares, in the order given.
     *
     * @param array<string, mixed> $fields
     */
    public function written(OutputInterface $output, bool $main, bool $dryRun, int $failed, array $fields): int
    {
        $exit = $failed === 0 ? Command::SUCCESS : Command::FAILURE;
        $json = ['status' => $exit === Command::SUCCESS ? 'ok' : 'partial', 'database' => $main ? 'main' : 'local', 'dry_run' => $dryRun] + $fields;

        return $this->render(new CommandResult($json, [], $exit), OutputMode::Json, $output);
    }

    public function failure(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $human, CommandResult $result): int
    {
        if ($mode !== OutputMode::Human) {
            return $this->render($result, $mode, $output);
        }
        $io->getErrorStyle()->error($human);

        return $result->exit;
    }

    public function denied(SymfonyStyle $io, OutputInterface $output, OutputMode $mode): int
    {
        return $this->failure($io, $output, $mode, 'Unknown or unauthorized operator.', new CommandResult(['status' => 'denied'], ['ERROR: Unknown or unauthorized operator'], Command::FAILURE));
    }

    /** An empty --as= would fall back to admin_user, so it is a usage error, not a denial. */
    public function emptyOperator(SymfonyStyle $io, OutputInterface $output, OutputMode $mode): int
    {
        return $this->failure($io, $output, $mode, 'The --as option needs an operator name.', new CommandResult(['status' => 'invalid', 'error' => 'The --as option needs an operator name'], ['ERROR: Invalid Parameter --as='], Command::INVALID));
    }

    public function failed(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, \Throwable $error, string $generic): int
    {
        // Exception text can carry SQL or connection details, so it is never
        // shown. Only the connection driver's own typed exception is named.
        $message = $error instanceof MainDatabaseNotConfigured ? 'Main database is not configured' : $generic;

        return $this->failure($io, $output, $mode, $message . '.', new CommandResult(['status' => 'failed', 'error' => $message], ['ERROR: ' . $message], Command::FAILURE));
    }

    /**
     * What a command's last catch renders: a denial as a denial, anything
     * else as failed() does. A command catches its own specific exceptions first.
     */
    public function refused(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, \Throwable $error, string $generic): int
    {
        if ($error instanceof InstallationAccessDenied) {
            return $this->denied($io, $output, $mode);
        }

        return $this->failed($io, $output, $mode, $error, $generic);
    }
}
