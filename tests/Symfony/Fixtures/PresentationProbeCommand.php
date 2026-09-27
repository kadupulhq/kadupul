<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Platform\Infrastructure\Symfony\Console\CliPresentation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Test-only probe that prints the CliPresentation it was given, so a test can
 * prove a command run through LegacyCli::run() sees what the shim set. Only
 * registered under when@test in services.yaml; prod and dev never load it.
 */
#[AsCommand(name: 'kadupul:test:presentation')]
final class PresentationProbeCommand extends Command
{
    public function __construct(private readonly CliPresentation $presentation)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->presentation->mode->name . ':' . $this->presentation->legacy->name);

        return Command::SUCCESS;
    }
}
