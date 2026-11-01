<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

use Kadupul\Platform\Infrastructure\Legacy\LegacyInstallerClient;
use Kadupul\Platform\Infrastructure\Legacy\InstallationVersion;
use Kadupul\Platform\Domain\Installer\InstallerOptions;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'kadupul:install', description: 'Inspect, configure and execute the installation plan. Checks never construct the native installer.')]
final class InstallerCommand extends Command
{
    public function __construct(
        private readonly LegacyInstallerClient $worker,
        private readonly CliPresentation $presentation,
        private readonly InstallationVersion $version,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('action', null, InputOption::VALUE_REQUIRED, 'check, status, configure, start or recover', 'check')
            ->addOption('attempt', null, InputOption::VALUE_REQUIRED, 'Exact stopped attempt to recover; requires --force after confirming its worker and children have stopped')
            ->addOption('ini', null, InputOption::VALUE_REQUIRED, 'Legacy INI configuration file')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Expected native installation mode')
            ->addOption('configuration', null, InputOption::VALUE_REQUIRED, 'JSON file containing native installer option names')
            ->addOption('as', null, InputOption::VALUE_REQUIRED, 'Installation/Upgrades operator; defaults to admin_user')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Save, review and run the supplied plan')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Explicitly retry a stopped attempt after reviewing its log')
            ->addOption('confirm-discovery', null, InputOption::VALUE_NONE, 'Authorize discovery of the configured range')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'text or json', 'text')
            ->addOption('accept-eula', null, InputOption::VALUE_NONE, 'Accept the license terms');
        foreach (['path', 'snmp', 'table', 'template'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
        }
        foreach (['automationmode', 'automationrange', 'cron', 'collection-profile', 'rrdtool', 'theme', 'language'] as $name) {
            $this->addOption($name, null, InputOption::VALUE_REQUIRED);
        }
    }

    /** @param array<array-key, mixed> $data */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new \RuntimeException('Invalid installer worker result.');
        }
        return (string) $value;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->presentation->legacy !== LegacyRequest::Run) {
            $output->writeln('Kadupul Installer ' . $this->version->file(), OutputInterface::OUTPUT_RAW);
            if ($this->presentation->legacy !== LegacyRequest::Version) {
                $output->writeln((new InstallerLegacyArguments())->help(), OutputInterface::OUTPUT_RAW);
            }
            return Command::SUCCESS;
        }
        $action = $input->getOption('install') === true ? 'start' : $input->getOption('action');
        if (!is_string($action) || !in_array($action, ['check', 'status', 'configure', 'start', 'recover'], true)
            || !in_array($input->getOption('output'), ['text', 'json'], true)) {
            $output->writeln('Invalid action or output mode.');
            return Command::INVALID;
        }
        $options = [];
        $attempt = $input->getOption('attempt');
        if ($action === 'recover' && ($input->getOption('force') !== true || !is_string($attempt)
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', $attempt) !== 1)) {
            $output->writeln('Recovery requires --attempt with the current attempt number and --force after confirming that its worker and children have stopped.');
            return Command::INVALID;
        }
        $file = $input->getOption('configuration') ?? $input->getOption('ini');
        if ($file !== null) {
            if (!is_string($file) || !is_file($file) || !is_readable($file) || filesize($file) > 65536) {
                $output->writeln('Configuration must be a readable JSON file of at most 64 KiB.');
                return Command::INVALID;
            }
            try {
                $contents = file_get_contents($file, false, null, 0, 65537);
                if ($contents === false || strlen($contents) > 65536) {
                    throw new \InvalidArgumentException('Unreadable or oversized installer configuration.');
                }
                if ($input->getOption('ini') === null) {
                    $options = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
                } else {
                    // Scope warning conversion to this parser alone: its
                    // diagnostics can include credential-bearing source lines.
                    $previous = null;
                    $previous = set_error_handler(static function (int $level, string $message, string $source, int $line) use (&$previous): bool {
                        if ($level === E_WARNING) {
                            throw new \InvalidArgumentException('Invalid INI configuration.');
                        }
                        return $previous !== null && $previous($level, $message, $source, $line) === true;
                    });
                    try {
                        $options = parse_ini_string($contents);
                    } finally {
                        restore_error_handler();
                    }
                }
            } catch (\JsonException|\InvalidArgumentException) {
                $options = null;
            }
            if (!is_array($options) || array_is_list($options) && $options !== []) {
                $output->writeln('Configuration must be a JSON object.');
                return Command::INVALID;
            }
        }
        foreach (['automationmode' => 'AutomationMode', 'automationrange' => 'AutomationRange', 'cron' => 'CronInterval',
            'mode' => 'Mode', 'collection-profile' => 'Profile', 'rrdtool' => 'RRDVersion', 'theme' => 'Theme', 'language' => 'Language'] as $flag => $key) {
            if ($input->getOption($flag) !== null) {
                $options[$key] = $input->getOption($flag);
            }
        }
        foreach (['path' => ['Paths', 'path_'], 'snmp' => ['SnmpOptions', 'Snmp'],
            'table' => ['Tables', ''], 'template' => ['Templates', 'chk_template_']] as $flag => [$key, $prefix]) {
            $entries = $input->getOption($flag);
            if (!is_array($entries)) {
                return Command::INVALID;
            }
            $nested = $options[$key] ?? [];
            if (!is_array($nested)) {
                $output->writeln('Invalid named installer option map.');
                return Command::INVALID;
            }
            foreach ($entries as $entry) {
                if (!is_string($entry) || !str_contains($entry, ':')) {
                    $output->writeln('Invalid named installer option.');
                    return Command::INVALID;
                }
                [$name, $value] = explode(':', $entry, 2);
                $name = trim($name);
                if ($flag === 'template') {
                    $name = str_replace('.', '_', $name);
                }
                $name = str_starts_with($name, $prefix) ? $name : $prefix . $name;
                $nested[$name] = trim($value);
            }
            if ($entries !== []) {
                $options[$key] = $nested;
            }
        }
        if ($input->getOption('accept-eula') === true) {
            $options['Eula'] = true;
        }
        if (in_array($action, ['configure', 'start'], true) && !($action === 'start' && $input->getOption('force') === true && $options === [])) {
            try {
                InstallerOptions::fromArray($options);
            } catch (\InvalidArgumentException $error) {
                $output->writeln($error->getMessage(), OutputInterface::OUTPUT_RAW);
                return Command::INVALID;
            }
        }
        $result = $this->worker->request(
            ['action' => $action, 'options' => $options, 'operator' => $input->getOption('as'),
                'attempt' => $action === 'recover' ? (int) $attempt : null,
                'retry' => (bool) $input->getOption('force'), 'discovery_confirmed' => (bool) $input->getOption('confirm-discovery')],
            $this->version->file()
        );
        $data = $result['data'];
        if ($input->getOption('output') === 'json') {
            $output->writeln(json_encode($data, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $io = new SymfonyStyle($input, $output);
            if (is_string($data['error'] ?? null)) {
                $io->error($data['error']);
            } elseif (is_array($data['checks'] ?? null)) {
                $io->title('Kadupul installation plan');
                $rows = [];
                foreach ($data['checks'] as $check) {
                    if (!is_array($check) || !is_bool($check['ok'] ?? null)) {
                        $io->error('Invalid installer worker result. Check execution status before retrying.');
                        return Command::FAILURE;
                    }
                    $rows[] = [$this->text($check, 'name'), $check['ok'] ? 'Pass' : 'Blocked', $this->text($check, 'observed')];
                    if (!$check['ok']) {
                        $io->warning($this->text($check, 'name') . ': ' . $this->text($check, 'remedy'));
                    }
                }
                $io->table(['Check', 'Result', 'Observed'], $rows);
                $io->definitionList(
                    ['Operation' => $this->text($data, 'mode')],
                    ['Database' => $this->text($data, 'schema')],
                    ['Target' => $this->text($data, 'target')],
                    ['Discovery' => ($data['discovery'] ?? null) === true ? $this->text($data, 'range') : 'Disabled']
                );
                $io->note('Back up the database, RRD files, configuration and keys before using --install. --install explicitly authorizes the supplied plan. Discovery additionally requires --confirm-discovery.');
            } else {
                $io->definitionList(
                    ['State' => $this->text($data, 'state')],
                    ['Phase' => $this->text($data, 'phase')],
                    ['Progress' => $this->text($data, 'progress') . '%'],
                    ['Database' => $this->text($data, 'schema')]
                );
            }
        }
        $blocked = is_array($data['checks'] ?? null) && in_array(false, array_column($data['checks'], 'ok'), true);
        return $result['ok'] && !$blocked ? Command::SUCCESS : Command::FAILURE;
    }
}
