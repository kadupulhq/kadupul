<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Symfony\Component\Console\Output\OutputInterface;

final readonly class ResultRenderer
{
    public function render(CommandResult $result, OutputMode $mode, OutputInterface $output): int
    {
        if ($mode === OutputMode::Human) {
            throw new \LogicException('Commands write human output through SymfonyStyle.');
        }
        if ($mode === OutputMode::Legacy) {
            // The originals printed everything, errors included, to stdout.
            foreach ($result->legacy as $line) {
                $output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
        } else {
            $output->writeln(json_encode($result->json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
        }

        return $result->exit;
    }
}
