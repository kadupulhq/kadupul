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
use Kadupul\Platform\Domain\Installer\InstallerPreset;
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
            ->addOption('validate', null, InputOption::VALUE_NONE, 'Validate configuration syntax and options locally without saving or starting a worker')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Defaults for standalone or collector installation; requires explicit license acceptance')
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

    private function invalid(InputInterface $input, OutputInterface $output, string $message, string $code = 'invalid_configuration'): int
    {
        $output->writeln($input->getOption('output') === 'json'
            ? json_encode(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], JSON_THROW_ON_ERROR)
            : $message, OutputInterface::OUTPUT_RAW);
        return Command::INVALID;
    }

    /** @param array<array-key, mixed> $options
     *  @return array<array-key, mixed>
     */
    private function resolveSecretReferences(#[\SensitiveParameter] array $options): array
    {
        if (!is_array($options['SnmpOptions'] ?? null)) {
            return $options;
        }
        foreach ($options['SnmpOptions'] as $name => $reference) {
            if (!is_array($reference)) {
                continue;
            }
            if (!in_array($name, InstallerOptions::SNMP_SECRETS, true) || array_keys($reference) !== ['env']
                || !is_string($reference['env']) || strlen($reference['env']) > 255
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $reference['env']) !== 1) {
                throw new \InvalidArgumentException('Invalid installer secret reference.');
            }
            $secret = getenv($reference['env']);
            if ($secret === false) {
                throw new \InvalidArgumentException('An installer secret environment variable is unavailable.');
            }
            $options['SnmpOptions'][$name] = $secret;
        }
        return $options;
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
            return $this->invalid($input, $output, 'Invalid action or output mode.', 'invalid_arguments');
        }
        $validate = $input->getOption('validate') === true;
        if ($validate && ($input->getOption('install') === true || !in_array($action, ['check', 'configure'], true))) {
            return $this->invalid($input, $output, 'Configuration validation cannot be combined with execution, status or recovery.', 'invalid_arguments');
        }
        $preset = $input->getOption('preset');
        if ($preset !== null && (!is_string($preset) || $input->getOption('force') === true
            || !$validate && !in_array($action, ['configure', 'start'], true))) {
            return $this->invalid($input, $output, 'Use a preset only with validation or a new configuration and without --force.', 'invalid_arguments');
        }
        $options = [];
        $attempt = $input->getOption('attempt');
        if ($action === 'recover' && ($input->getOption('force') !== true || !is_string($attempt)
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', $attempt) !== 1)) {
            return $this->invalid($input, $output, 'Recovery requires --attempt with the current attempt number and --force after confirming that its worker and children have stopped.', 'invalid_arguments');
        }
        $file = $input->getOption('configuration') ?? $input->getOption('ini');
        if ($file !== null) {
            if (!is_string($file) || !is_file($file) || !is_readable($file) || filesize($file) > 65536) {
                return $this->invalid($input, $output, 'Configuration must be a readable JSON file of at most 64 KiB.');
            }
            try {
                $contents = file_get_contents($file, false, null, 0, 65537);
                if ($contents === false || strlen($contents) > 65536) {
                    throw new \InvalidArgumentException('Unreadable or oversized installer configuration.');
                }
                if ($input->getOption('ini') === null) {
                    $options = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
                    // Associative decoding maps both {} and [] to an empty
                    // array; the configuration root must still be an object.
                    if (!str_starts_with(ltrim($contents), '{')) {
                        throw new \InvalidArgumentException('Configuration must be a JSON object.');
                    }
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
                return $this->invalid($input, $output, 'Configuration must be a JSON object.');
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
                return $this->invalid($input, $output, 'Invalid named installer option.');
            }
            $nested = $options[$key] ?? [];
            if (!is_array($nested)) {
                return $this->invalid($input, $output, 'Invalid named installer option map.');
            }
            foreach ($entries as $entry) {
                if (!is_string($entry) || !str_contains($entry, ':')) {
                    return $this->invalid($input, $output, 'Invalid named installer option.');
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
        try {
            if (is_string($preset)) {
                $options = InstallerPreset::apply($preset, $options);
            }
            if ($input->getOption('configuration') !== null && $input->getOption('ini') === null) {
                $options = $this->resolveSecretReferences($options);
            }
        } catch (\InvalidArgumentException $error) {
            return $this->invalid($input, $output, $error->getMessage());
        }
        if ($validate || in_array($action, ['configure', 'start'], true) && !($action === 'start' && $input->getOption('force') === true && $options === [])) {
            try {
                InstallerOptions::fromArray($options);
            } catch (\InvalidArgumentException $error) {
                return $this->invalid($input, $output, $error->getMessage());
            }
        }
        if ($validate) {
            $output->writeln($input->getOption('output') === 'json'
                ? json_encode(['ok' => true, 'valid' => true], JSON_THROW_ON_ERROR)
                : 'Configuration syntax and options are valid. Installation prerequisites have not been checked.', OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
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
