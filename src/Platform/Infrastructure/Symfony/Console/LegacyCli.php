<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class LegacyCli
{
    /**
     * @param class-string<LegacyArguments> $map
     * @param list<string> $argv the full $_SERVER['argv'], script name first
     * @param OutputInterface|null $output test-only: captures both the deprecation
     *     line and the legacy text in one buffer. The shim never passes this; with
     *     no value, deprecation text keeps going to stderr as in production.
     */
    public static function run(string $command, string $map, array $argv, ?OutputInterface $output = null): int
    {
        self::raiseRuntimeLimits();
        $injected = $output !== null;
        $output ??= new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $deprecation = !$injected && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if (getenv('KADUPUL_CLI_QUIET_DEPRECATION') !== '1') {
            $deprecation->writeln('NOTE: ' . basename((string) ($argv[0] ?? 'script')) . ' is deprecated; use bin/console ' . $command . '.', OutputInterface::OUTPUT_RAW);
        }
        $arguments = new $map();
        try {
            [$input, $special] = $arguments->translate(array_slice($argv, 1));
        } catch (InvalidLegacyArgument $error) {
            foreach ([...$arguments->invalid($error->argument), ...$arguments->help()] as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_RAW);
            }

            return 1;
        }
        // src/Platform/Infrastructure/Symfony/Console -> src -> repository root is 5 levels up.
        $kernel = require dirname(__DIR__, 5) . '/config/bootstrap.php';
        // Boot before building the Application so the presentation set here is on the
        // same container the Application later reads its commands from: Application
        // boots the kernel lazily too, but boot() is a no-op once it's already booted.
        $kernel->boot();
        $kernel->getContainer()->get(CliPresentation::class)->forLegacy($special ?? LegacyRequest::Run);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);

        return $application->run(new ArrayInput(['command' => $command] + $input), $output);
    }

    /**
     * include/cli_check.php ran this before anything else, and the shims no
     * longer load it. The loose comparisons are copied on purpose: ini_get()
     * returns strings, and '-1' must compare equal to -1 as it did there.
     */
    private static function raiseRuntimeLimits(): void
    {
        $defaultLimit = -1;
        $defaultTime = -1;
        $memoryLimit = ini_get('memory_limit');
        $executionTime = ini_get('max_execution_time');
        if ($memoryLimit != $defaultLimit) {
            ini_set('memory_limit', $defaultLimit);
        }
        if ($executionTime < $defaultTime && $executionTime >= 0) {
            ini_set('max_execution_time', $defaultTime);
        }
    }
}
