<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\PollerCacheRebuild;
use Kadupul\Graphing\Application\Command\RebuildPollerCache;
use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Kadupul\Platform\Infrastructure\Symfony\Console\CommandResult;
use Kadupul\Platform\Infrastructure\Symfony\Console\LegacyRequest;
use Kadupul\Platform\Infrastructure\Symfony\Console\OutputMode;
use Kadupul\Platform\Infrastructure\Symfony\Console\ResultRenderer;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * kadupul:poller-cache:rebuild, which cli/rebuild_poller_cache.php forwards
 * to. The master counts the enabled devices and starts one child per slice
 * through cli/push_out_hosts.php; each child pushes out its devices. Output
 * is streamed as the script printed it, except under --json.
 */
#[AsCommand(name: 'kadupul:poller-cache:rebuild', description: 'Rebuild the poller cache of every enabled device, or of the devices selected.')]
final readonly class RebuildPollerCacheCommand implements SignalableCommandInterface
{
    /**
     * @param RebuildPollerCache $rebuild the use case
     * @param InstallationVersion $version include/cacti_version for the version line
     * @param ClockInterface $clock the year of the version line
     * @param CliPresentation $presentation legacy output when a shim runs the command
     * @param ResultRenderer $renderer the shared legacy and JSON output
     */
    public function __construct(
        private RebuildPollerCache $rebuild,
        private InstallationVersion $version,
        private ClockInterface $clock,
        private CliPresentation $presentation,
        private ResultRenderer $renderer,
    ) {}

    /**
     * Checks the values as the script did, then runs the master or a child.
     *
     * @return int 0, 1 or, under bin/console, 2 for invalid input
     */
    public function __invoke(SymfonyStyle $io, OutputInterface $output, #[MapInput] RebuildPollerCacheInput $input): int
    {
        $mode = $input->json ? OutputMode::Json : $this->presentation->mode;
        $legacy = new RebuildPollerCacheLegacyArguments();
        if ($this->presentation->legacy !== LegacyRequest::Run) {
            return $this->renderer->legacyRequest($this->presentation->legacy, $this->versionLine(), $legacy, $output);
        }
        // The script checked each value as it read it; the map stops at the first it refused, so at most one is bad here.
        $hostId = $input->hostId === null ? null : trim($input->hostId);
        if ($hostId !== null && (!ctype_digit($hostId) || (int) $hostId < 1)) {
            return $this->invalid($io, $output, $mode, 'The --host-id option must be a positive integer.', RebuildPollerCacheLegacyArguments::badHostId(), true);
        }
        $hostTemplateId = $input->hostTemplateId === null ? null : trim($input->hostTemplateId);
        if ($hostTemplateId !== null && !is_numeric($hostTemplateId)) {
            return $this->invalid($io, $output, $mode, 'The --host-template-id option must be numeric.', RebuildPollerCacheLegacyArguments::badHostTemplateId());
        }
        $dataTemplateId = $input->dataTemplateId === null ? null : trim($input->dataTemplateId);
        if ($dataTemplateId !== null && !is_numeric($dataTemplateId)) {
            return $this->invalid($io, $output, $mode, 'The --data-template-id option must be numeric.', RebuildPollerCacheLegacyArguments::badDataTemplateId());
        }
        $threads = $input->threads === null ? null : RebuildPollerCacheLegacyArguments::threads($input->threads);
        if ($input->threads !== null && $threads === null) {
            return $this->badThreads($io, $output, $mode);
        }
        $request = new PollerCacheRequest($hostId, $hostTemplateId, $dataTemplateId, $threads, $input->type ?? PollerCacheRequest::MASTER, $input->child, $input->force, $input->debug);
        try {
            $rebuild = ($this->rebuild)($request, $mode === OutputMode::Json ? null : $this->relay($output));
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Rebuilding the poller cache failed');
        }

        return $this->report($io, $output, $mode, $rebuild, $request->type);
    }

    /**
     * SIGINT and SIGTERM, which the script handled itself.
     *
     * @return list<int>
     */
    #[\Override]
    public function getSubscribedSignals(): array
    {
        return \defined('SIGINT') ? [\SIGINT, \SIGTERM] : [];
    }

    /**
     * The script's handler stopped the children and exited 1. A run in
     * progress gets the signal and ends that way; without one the command
     * exits 1 itself.
     */
    #[\Override]
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        return $this->rebuild->interrupt($signal) ? false : Command::FAILURE;
    }

    /** "Kadupul Rebuild poller cache Tool, Version <CACTI_VERSION> <COPYRIGHT_YEARS>", without the DB version the other tools print. */
    private function versionLine(): string
    {
        return 'Kadupul Rebuild poller cache Tool, Version ' . $this->version->file() . ' Copyright (C) 2004-' . $this->clock->now()->format('Y') . ' The Cacti Group';
    }

    /**
     * Writes what the worker prints as it arrives: stdout to stdout and stderr to stderr.
     *
     * @return \Closure(string, string): void
     */
    private function relay(OutputInterface $output): \Closure
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        return static function (string $type, string $chunk) use ($output, $errors): void {
            // Raw: the script printed plain text, which must not be read as markup.
            ($type === 'err' ? $errors : $output)->write($chunk, false, OutputInterface::OUTPUT_RAW);
        };
    }

    /**
     * @param bool $toErrors whether the script printed the line to stderr instead of stdout
     */
    private function invalid(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, string $message, string $line, bool $toErrors = false): int
    {
        if ($mode === OutputMode::Legacy && $toErrors) {
            ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)->writeln($line, OutputInterface::OUTPUT_RAW);

            return Command::FAILURE;
        }

        return $this->renderer->failure($io, $output, $mode, $message, new CommandResult(['status' => 'invalid', 'error' => $message], [$line], $mode === OutputMode::Legacy ? Command::FAILURE : Command::INVALID));
    }

    /** The script quoted the setting in its message, so a shim reads it, for an authorized operator only. */
    private function badThreads(SymfonyStyle $io, OutputInterface $output, OutputMode $mode): int
    {
        $message = 'The --threads option must be a positive integer.';
        if ($mode !== OutputMode::Legacy) {
            return $this->invalid($io, $output, $mode, $message, '');
        }
        try {
            $default = $this->rebuild->concurrentProcesses();
        } catch (GraphManagementDenied) {
            return $this->renderer->denied($io, $output, $mode);
        } catch (\Throwable $error) {
            return $this->renderer->failed($io, $output, $mode, $error, 'Rebuilding the poller cache failed');
        }

        return $this->invalid($io, $output, $mode, $message, RebuildPollerCacheLegacyArguments::badThreads($default));
    }

    /** The ending after the streamed output: JSON, a failure line, or a human summary. */
    private function report(SymfonyStyle $io, OutputInterface $output, OutputMode $mode, PollerCacheRebuild $rebuild, string $type): int
    {
        $exit = $rebuild->succeeded() ? Command::SUCCESS : Command::FAILURE;
        if ($mode === OutputMode::Json) {
            return $this->renderer->render(new CommandResult(['status' => $rebuild->status, 'database' => $rebuild->database === OperatorDatabase::Main ? 'main' : 'local',
                'type' => $type, 'hosts' => $rebuild->hosts], [], $exit), $mode, $output);
        }
        if ($rebuild->status === PollerCacheRebuild::FAILED) {
            // The worker's own output, if any, is already printed; this names the failure without its details.
            return $this->renderer->failure($io, $output, $mode, 'Rebuilding the poller cache failed.', new CommandResult(['status' => 'failed'], ['ERROR: Rebuilding the poller cache failed'], Command::FAILURE));
        }
        if ($mode === OutputMode::Legacy) {
            // Everything the script printed has been streamed already.
            return $exit;
        }
        match ($rebuild->status) {
            PollerCacheOutcome::REBUILT => $io->success(sprintf('The poller cache was rebuilt for %d device(s).', $rebuild->hosts)),
            PollerCacheOutcome::NO_HOSTS => $io->warning('No enabled device matched.'),
            PollerCacheOutcome::ALREADY_RUNNING => $io->warning('Another rebuild of the same type and thread is registered; nothing was done. Use --force to run anyway.'),
            PollerCacheOutcome::INTERRUPTED => $io->error('The rebuild was interrupted.'),
            default => $io->note('Nothing to do for this --type.'),
        };

        return $exit;
    }
}
