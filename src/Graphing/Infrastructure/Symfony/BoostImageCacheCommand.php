<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\PurgeBoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCache;
use Kadupul\Graphing\Application\Port\BoostImageCacheUnavailable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Purges expired Boost graph images by hand, whether or not the cache setting is on. */
#[AsCommand(name: 'kadupul:boost:purge-image-cache', description: 'Remove expired Boost graph images from the image cache directory.')]
final class BoostImageCacheCommand extends Command
{
    public function __construct(private readonly BoostImageCache $cache)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable result.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = $input->getOption('json');
        // An operator running this by hand wants the purge even when the cache setting is off,
        // as poller_boost.php does for a forced run.
        try {
            $removed = $this->cache->purgeOlderThan(PurgeBoostImageCache::EXPIRY_SECONDS);
        } catch (BoostImageCacheUnavailable $error) {
            $json
                ? $output->writeln(json_encode(['status' => 'failed', 'error' => $error->getMessage()], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW)
                : $output->writeln('<error>' . OutputFormatter::escape($error->getMessage()) . '</error>');

            return Command::FAILURE;
        }
        $json
            ? $output->writeln(json_encode(['status' => 'ok', 'removed' => $removed], JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW)
            : $output->writeln(sprintf('Removed %d expired image(s).', $removed));

        return Command::SUCCESS;
    }
}
