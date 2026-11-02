<?php

declare(strict_types=1);

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Domain\Installer\OperationalVerificationRequest;
use Kadupul\Platform\Infrastructure\Legacy\LegacyOperationalVerificationClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kadupul:install:verify', description: 'Opt in to bounded persisted operational evidence and local RRD timestamps; never starts collection or renders graphs.')]
final class InstallerVerifyCommand extends Command
{
    public function __construct(private readonly LegacyOperationalVerificationClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('acknowledge-read-only', null, InputOption::VALUE_NONE, 'Authorize local database and contained RRD timestamp reads; no devices or pollers are contacted')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Eligible Installation/Upgrades operator; defaults to admin_user')
            ->addOption('graph-id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Existing graph ID, at most 20; additionally requires graph permissions')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!in_array($input->getOption('output'), ['text', 'json'], true)) {
            $output->writeln('Output must be text or json.');
            return Command::INVALID;
        }
        try {
            if ($input->getOption('acknowledge-read-only') !== true) {
                throw new \InvalidArgumentException('Use --acknowledge-read-only to authorize this bounded assessment.');
            }
            $values = $input->getOption('graph-id');
            if (!is_array($values)) {
                throw new \InvalidArgumentException('Graph IDs must be a list.');
            }
            $request = OperationalVerificationRequest::fromGraphIds($values);
            $username = $input->getOption('as');
            if ($username !== null && (!is_string($username) || $username === '' || strlen($username) > 255 || str_contains($username, "\0"))) {
                throw new \InvalidArgumentException('Invalid operator name.');
            }
            $data = $this->client->request($username, $request->graphIds);
        } catch (\InvalidArgumentException|\RuntimeException $error) {
            $output->writeln($input->getOption('output') === 'json'
                ? json_encode(['ok' => false, 'error' => $error->getMessage()], JSON_THROW_ON_ERROR) : $error->getMessage(), OutputInterface::OUTPUT_RAW);
            return $error instanceof \InvalidArgumentException ? Command::INVALID : Command::FAILURE;
        }
        if ($input->getOption('output') === 'json') {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $io = new SymfonyStyle($input, $output);
            $io->title('Persisted operational evidence');
            $io->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
            $io->note('Exit zero confirms the assessment ran. Inspect each evidence status; live device health and graph rendering remain unassessed.');
        }
        $attention = $data['scheduler']['status'] === 'action_required'
            || in_array($data['collection']['status'], ['failed', 'stale'], true)
            || $data['collection']['reason'] === 'local_poller_record_missing';
        foreach ($data['graphs'] as $graph) {
            foreach ($graph['sources'] as $source) {
                $attention = $attention || in_array($source['status'], ['failed', 'stale', 'absent'], true);
            }
        }
        return $attention ? Command::FAILURE : Command::SUCCESS;
    }
}
