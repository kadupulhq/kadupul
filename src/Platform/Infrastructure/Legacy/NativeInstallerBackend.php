<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Kadupul\IdentityAccess\Contract\ConsoleOperator;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Application\Installer\InstallerBackend;
use Kadupul\Platform\Application\Installer\InstallerEngine;
use Kadupul\Platform\Domain\Installer\InstallationPhase;
use Kadupul\Platform\Domain\Installer\InstallerOptions;

/** Runs only behind the authenticated native web shim or the CLI worker. */
final class NativeInstallerBackend implements InstallerBackend
{
    private const JOB = 'installer_v13_job';

    /** @param ?\Closure(): InstallerReviewSigner $signer */
    public function __construct(
        private readonly string $projectDir,
        private readonly ConsoleOperator $operator,
        private readonly NativeInstallerFunctions $functions,
        #[AutowireServiceClosure(InstallerReviewSigner::class)]
        private readonly ?\Closure $signer = null,
    ) {}

    public function authorize(): void
    {
        if (!defined('IN_CACTI_INSTALL') || !function_exists('read_config_option')) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Use the authenticated installation entry point.');
        }
        if (PHP_SAPI === 'cli' && defined('KADUPUL_INSTALLER_OPERATOR')) {
            $username = constant('KADUPUL_INSTALLER_OPERATOR');
            if (!is_string($username) && $username !== null) {
                throw new \RuntimeException('Invalid installer operator.');
            }
            $this->operator->select($username, OperatorDatabase::Local);
            $actor = $this->operator->actor();
            if ($actor !== null && $this->operator->canUpgradeInstallation($actor)) {
                return;
            }
        } elseif (defined('KADUPUL_INSTALLER_WEB') && isset($_SESSION['sess_user_id'])) {
            // The shim ran native authentication. Recheck mutable eligibility and
            // uncached permissions again at every application operation.
            $method = $this->functions->call('read_config_option', 'auth_method');
            if (in_array($this->integer($method), [1, 2, 3, 4], true)
                && $this->functions->call('auth_session_check_eligibility', $method) === 0
                && isset($_SESSION['sess_user_id'])
                && $this->functions->call('is_realm_allowed', 26, $this->integer($_SESSION['sess_user_id'])) === true) {
                return;
            }
        }
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Installation/Upgrades permission is required.');
    }

    public function inspect(): array
    {
        $settings = $this->settings();
        $schema = $this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn();
        if (!is_string($schema) || $schema === '') {
            throw new \RuntimeException('The installation schema is unavailable.');
        }
        $existingStatus = $this->status();
        $recoveryOnly = $schema === $this->targetVersion() && in_array($existingStatus['state'], ['failed', 'partial'], true);
        $checks = [];
        $check = static function (string $name, bool $ok, string $observed, string $remedy) use (&$checks): void {
            $checks[] = ['name' => $name, 'ok' => $ok, 'observed' => $observed, 'remedy' => $remedy];
        };
        $check('PHP runtime', version_compare(PHP_VERSION, '8.4.0', '>='), PHP_VERSION, 'Configure PHP 8.4 or newer for both the web service and CLI.');
        array_push($checks, ...InstallerRuntimeChecks::inspect($this->projectDir, PHP_SAPI !== 'cli'));
        $autocommit = !$this->database()->inTransaction()
            && $this->functions->text($this->query('SELECT @@session.autocommit')->fetchColumn()) === '1';
        $check(
            'Database transaction ownership',
            $autocommit,
            $autocommit ? 'autocommit enabled; no caller-owned transaction' : 'autocommit disabled or caller transaction active',
            'Run installation outside a caller-owned transaction and enable autocommit on its mutation connection.'
        );
        $manifest = $this->functions->map(json_decode((string) file_get_contents($this->projectDir . '/composer.json'), true, 512, JSON_THROW_ON_ERROR));
        foreach (array_keys($this->functions->map($manifest['require'] ?? null)) as $package) {
            if (str_starts_with($package, 'ext-')) {
                $extension = substr($package, 4);
                $check('PHP ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing', 'Enable this extension in the PHP configuration used by this process.');
            }
        }
        $check(
            'Database connection',
            $this->database()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql',
            $this->functions->text($this->database()->getAttribute(\PDO::ATTR_SERVER_VERSION)),
            'Use the supported MySQL/MariaDB database and review the connection configuration.'
        );
        $mode = $recoveryOnly ? 'finish' : ($schema === 'new_install' ? 'install' : ($schema === $this->targetVersion() ? 'complete' : 'upgrade'));
        if ($schema === 'new_install') {
            // Match the native getMode() precedence without constructing it:
            // configured remote database first, then the saved review choice.
            $nativeMode = $settings['install_mode'] ?? ($this->functions->hasRemoteDatabase() ? '2' : '1');
            $mode = $nativeMode === '2' ? 'collector' : 'install';
        }
        $safeVersion = $schema === 'new_install' || $this->functions->call('cacti_version_compare', $schema, $this->targetVersion(), '<=') === true;
        $check('Schema version', $safeVersion, $schema, 'Restore matching application files. Automatic downgrades are not supported.');
        $configuration = $this->functions->configuration();
        foreach (['log', 'cache/boost', 'cache/mibcache', 'cache/realtime', 'cache/spikekill'] as $relative) {
            $path = $this->projectDir . '/' . $relative;
            $check(
                $relative,
                is_dir($path) && is_writable($path),
                is_writable($path) ? 'writable' : 'not writable',
                'Grant the service account access to this directory. Do not make it world-writable.'
            );
        }
        $paths = [];
        if (!$recoveryOnly && $mode !== 'complete') {
            // These destinations are required by the native permission/import
            // step. Finishing an installed schema does not reimport resources.
            foreach (['resource/snmp_queries', 'resource/script_server', 'resource/script_queries', 'scripts'] as $relative) {
                $path = $this->projectDir . '/' . $relative;
                $writable = is_dir($path) && is_writable($path);
                $check(
                    'Import destination: ' . $relative,
                    $writable,
                    $writable ? 'directory writable by this process' : 'missing or not writable',
                    'Restore the release directory and grant the installation account write access for template resource imports. Do not make it world-writable.'
                );
            }
        }
        foreach ($this->functions->map($this->functions->call('install_file_paths')) as $name => $definition) {
            $definition = $this->functions->map($definition);
            if (!str_starts_with($name, 'path_') && $name !== 'settings_sendmail_path') {
                continue;
            }
            $value = $settings[$name] ?? $definition['default'] ?? '';
            $paths[$name] = is_string($value) ? $value : '';
        }
        foreach (['path_php_binary', 'path_rrdtool'] as $name) {
            $path = $paths[$name] ?? '';
            $check($name, is_file($path) && is_executable($path), $path, 'Set a valid executable path in advanced options and check its service-account permissions.');
        }
        $profiles = [];
        foreach ($this->query('SELECT id, name FROM data_source_profiles ORDER BY id LIMIT 501')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row = $this->functions->map($row);
            $profiles[] = ['id' => $this->functions->text($row['id'] ?? null), 'name' => $this->functions->text($row['name'] ?? null)];
        }
        if (count($profiles) > 500) {
            throw new \RuntimeException('Too many collection profiles to display safely.');
        }
        $templates = [];
        foreach ($this->nativeList('install_setup_get_templates') as $template) {
            $template = $this->functions->map($template);
            $template = ['filename' => $this->functions->text($template['filename'] ?? null), 'name' => $this->functions->text($template['name'] ?? null)];
            $key = 'chk_template_' . str_replace('.', '_', $template['filename']);
            $templates[] = ['key' => $key, 'name' => $template['name'],
                'selected' => !isset($settings['install_has_templates']) || ($settings['install_tp_' . str_replace('.', '_', $template['filename'])] ?? '') === $template['filename']];
        }
        $fields = $this->functions->snmpFields();
        $snmp = [];
        foreach ($this->engineClass()::snmpOptionNames() as $name => $key) {
            $definition = $this->functions->map($fields[$key] ?? []);
            $secret = in_array($name, InstallerOptions::SNMP_SECRETS, true);
            $snmp[] = ['name' => $name, 'label' => $this->functions->text($definition['friendly_name'] ?? $name),
                'secret' => $secret, 'choices' => $this->choices($definition['array'] ?? []),
                'value' => $secret ? '' : $this->functions->text($settings['install_snmp_option_' . $key] ?? $definition['default'] ?? ''),
                'limit' => $this->integer($definition['max_length'] ?? 255)];
        }
        $themes = [];
        $themeFiles = glob($this->projectDir . '/include/themes/*/main.css');
        if ($themeFiles === false) {
            throw new \RuntimeException('Unable to read installed themes.');
        }
        foreach ($themeFiles as $file) {
            $themes[] = basename(dirname($file));
        }
        try {
            $revision = $this->revision($settings, $schema);
            $signed = true;
        } catch (\RuntimeException) {
            $revision = '';
            $signed = false;
        }
        $check(
            'Application secret',
            $signed,
            $signed ? 'configured' : 'missing or too short',
            'Provide the same strong APP_SECRET to the web and CLI runtimes before reviewing a plan. Keep it outside the document root.'
        );
        $discovery = !$recoveryOnly && in_array($settings['install_automation_mode'] ?? '', ['on', 'true', '1'], true);
        return [
            'mode' => $mode, 'schema' => $schema, 'target' => $this->targetVersion(),
            'collector' => $this->integer($configuration['poller_id'] ?? 1), 'checks' => $checks,
            'advisories' => [InstallerHealthAssessment::databasePrivileges()],
            'ready' => $mode !== 'complete' && !in_array(false, array_column($checks, 'ok'), true),
            'revision' => $revision, 'reviewed' => $revision !== '' && ($recoveryOnly || ($settings['installer_v13_review'] ?? '') === $revision),
            'discovery' => $discovery, 'range' => $settings['install_automation_range'] ?? '',
            'profile' => $settings['install_profile'] ?? '', 'profiles' => $profiles, 'templates' => $templates,
            'cron' => $settings['cron_interval'] ?? '300', 'paths' => $paths,
            'languages' => $this->choices($this->functions->call('get_installed_locales')), 'language' => $settings['install_language'] ?? 'en-US',
            'themes' => $themes, 'theme' => $settings['install_theme'] ?? 'modern',
            'snmp' => $snmp, 'override' => in_array($settings['install_automation_override'] ?? '', ['on', 'true', '1'], true),
            'tables' => $this->nativeList('install_setup_get_tables'),
            'job' => $existingStatus,
        ];
    }

    public function configure(InstallerOptions $options): array
    {
        return $this->locked(function () use ($options): array {
            $this->authorize();
            $job = $this->job();
            if ($this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn() === $this->targetVersion()) {
                throw new \RuntimeException('The database is already installed. Use the finishing-task recovery instead of changing the installation configuration.');
            }
            if (in_array($job['state'] ?? '', ['waiting', 'running'], true) || $this->integer($this->functions->call('read_config_option', 'install_step', true) ?? 0) === 97) {
                throw new \RuntimeException('Installation is already pending. Read its status before making changes.');
            }
            $db = $this->database();
            if ($db->inTransaction()) {
                throw new \RuntimeException('Installer configuration requires its own transaction.');
            }
            $preferences = [];
            foreach (['selected_theme', 'sess_user_language', 'sess_user_config_array', 'sess_config_array'] as $key) {
                $preferences[$key] = [array_key_exists($key, $_SESSION ?? []), $_SESSION[$key] ?? null];
            }
            $viewer = $_SESSION['sess_user_id'] ?? null;
            $binding = $_SESSION['sess_user_credential'] ?? null;
            if (!$db->beginTransaction()) {
                throw new \RuntimeException('Installer configuration transaction could not be started.');
            }
            try {
                $this->configurationStorage();
                $values = $options->values;
                $expectedMode = $values['Mode'] ?? null;
                unset($values['Mode']);
                if (isset($values['SnmpOptions'])) {
                    $values['SnmpOptions'] = $this->functions->map($values['SnmpOptions']);
                    $existing = $this->settings();
                    foreach ($this->engineClass()::snmpOptionNames() as $name => $key) {
                        if (!array_key_exists($name, $values['SnmpOptions'])
                            && array_key_exists('install_snmp_option_' . $key, $existing)) {
                            $values['SnmpOptions'][$name] = $existing['install_snmp_option_' . $key];
                        }
                    }
                }
                $installer = $this->engineClass()::reviewed($values);
                if ($expectedMode !== null && (!is_int($expectedMode) && !is_string($expectedMode)
                    || preg_match('/\\A[123]\\z/D', (string) $expectedMode) !== 1 || (int) $expectedMode !== $installer->installationResult()['mode'])) {
                    throw new \InvalidArgumentException('The requested mode does not match this database and collector.');
                }
                if ($installer->installationResult()['ready'] !== true) {
                    throw new \InvalidArgumentException('The native installer rejected the configuration. Check the selected profile, paths, templates and prerequisites.');
                }
                $this->write('installer_v13_review', $this->revision());
                if (!$db->commit()) {
                    throw new \RuntimeException('Installer configuration commit could not be confirmed.');
                }
            } catch (\Throwable $error) {
                // Restore preference caches only while the same authenticated
                // identity is still present. Never restore revoked credentials.
                if (($_SESSION['sess_user_id'] ?? null) === $viewer && ($_SESSION['sess_user_credential'] ?? null) === $binding) {
                    foreach ($preferences as $key => [$present, $value]) {
                        if ($present) {
                            $_SESSION[$key] = $value;
                        } else {
                            unset($_SESSION[$key]);
                        }
                    }
                }
                $this->rollbackConfiguration($error);
                throw $error;
            }
            return $this->inspect();
        });
    }

    public function start(string $revision, bool $retry, bool $discoveryConfirmed): void
    {
        $this->locked(function () use ($revision, $retry, $discoveryConfirmed): void {
            $this->authorize();
            $this->requireAutocommit();
            $settings = $this->settings();
            $job = $this->job();
            if (in_array($job['state'] ?? '', ['waiting', 'running'], true)) {
                throw new \RuntimeException('An installation attempt is still active. Confirm that its worker has stopped and recover that exact attempt before retrying.');
            }
            $schema = $this->functions->text($this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn());
            $finish = $schema === $this->targetVersion() && ($job['target'] ?? '') === $this->targetVersion()
                && in_array($job['state'] ?? '', ['failed', 'partial'], true);
            if (!hash_equals($this->revision(), $revision) || (!$finish && ($settings['installer_v13_review'] ?? '') !== $revision)) {
                throw new \InvalidArgumentException('The installation plan changed. Review and save it again.');
            }
            if ($job !== [] && ($job['target'] ?? '') === $this->targetVersion() && !$retry) {
                throw new \RuntimeException('An installation attempt already exists. Read its status before explicitly retrying.');
            }
            if ((($job['state'] ?? '') === 'succeeded' && ($job['target'] ?? '') === $this->targetVersion() && $schema === $this->targetVersion()) || (!$retry && (int) ($settings['install_step'] ?? 0) === 97)) {
                throw new \RuntimeException('An installation is already active or complete.');
            }
            $current = $this->inspect();
            if ($current['discovery'] === true && !$discoveryConfirmed) {
                throw new \InvalidArgumentException('Confirm the discovery scope before starting.');
            }
            if ($current['ready'] !== true) {
                throw new \RuntimeException('Resolve the blocking checks before starting.');
            }
            if (defined('KADUPUL_INSTALLER_OPERATOR')) {
                $operator = $this->operator->actor();
                if ($operator === null || !$this->operator->canUpgradeInstallation($operator)) {
                    throw new \RuntimeException('The installation operator is no longer eligible.');
                }
                $actor = $operator->username;
                $actorId = $operator->id;
            } else {
                $actorId = $this->integer($_SESSION['sess_user_id'] ?? null);
                $actor = $this->functions->text($this->functions->call('db_fetch_cell_prepared', 'SELECT username FROM user_auth WHERE id = ?', [$actorId]));
            }
            if ($actor === '' || $actorId < 1) {
                throw new \RuntimeException('The installation operator is unavailable.');
            }
            if ($job !== []) {
                $this->write('installer_v13_previous_job', json_encode($job, JSON_THROW_ON_ERROR));
            }
            $job = ['token' => bin2hex(random_bytes(16)), 'actor' => $actor, 'actor_id' => $actorId, 'state' => 'waiting', 'target' => $this->targetVersion(), 'revision' => $revision, 'operation' => $finish ? 'finish' : 'install',
                'cleanup_done' => $finish && ($job['cleanup_done'] ?? false),
                'started' => time(), 'attempt' => ($job['attempt'] ?? 0) + 1];
            $this->write(self::JOB, json_encode($job, JSON_THROW_ON_ERROR));
            if (PHP_SAPI === 'cli' && defined('KADUPUL_INSTALLER_OPERATOR')) {
                $this->execute($job['token']);
                return;
            }
            // A waiting state is deliberately not reported as a successful launch.
            // The worker must acquire this same lock and acknowledge the token.
            $this->functions->call(
                'exec_background',
                $this->functions->call('read_config_option', 'path_php_binary', true),
                [$this->projectDir . '/install/background.php', 'v13:' . $job['token']]
            );
        });
    }

    public function recover(int $attempt): void
    {
        $this->locked(function () use ($attempt): void {
            $this->authorize();
            $this->requireAutocommit();
            $job = $this->job();
            if (($job['attempt'] ?? 0) !== $attempt || !in_array($job['state'] ?? '', ['waiting', 'running'], true)) {
                throw new \RuntimeException('The stopped attempt changed. Reload its status before recovering it.');
            }
            $job['state'] = 'failed';
            $job['finished'] = time();
            $this->write(self::JOB, json_encode($job, JSON_THROW_ON_ERROR));
        });
    }

    public function status(): array
    {
        $settings = $this->settings();
        $job = $this->job();
        if (($job['target'] ?? $this->targetVersion()) !== $this->targetVersion() && in_array($job['state'] ?? '', ['succeeded', 'failed', 'partial'], true)) {
            $job = [];
            $settings['install_progress'] = '0';
        }
        $schema = $this->functions->text($this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn());
        if (in_array($job['state'] ?? '', ['succeeded', 'partial'], true) && $schema !== $this->targetVersion()) {
            $job['state'] = 'failed';
        }
        $progress = in_array($job['state'] ?? '', ['succeeded', 'partial'], true) ? 100 : max(0, min(100, $this->integer($settings['install_progress'] ?? 0)));
        return ['state' => $job['state'] ?? 'not_started', 'progress' => $progress,
            'phase' => InstallationPhase::label($progress), 'attempt' => ($job['attempt'] ?? 0),
            'updated' => $settings['install_updated'] ?? null,
            'pending_collectors' => $job['pending_collectors'] ?? 0,
            'error' => ($job['state'] ?? '') === 'partial' ? 'The database is installed, but some collectors still need synchronization. Retry the finishing tasks after checking their status.' : (($job['state'] ?? '') === 'failed' ? 'Installation stopped. Preserve the installer log, correct the cause and explicitly confirm a retry. Some database changes may already be committed. Check plugin cleanup before authorizing a retry.' : null),
            'schema' => $schema,
            'health' => InstallerHealthAssessment::assess($schema, $this->targetVersion(), $job['state'] ?? 'not_started', $job['pending_collectors'] ?? 0, $this->pollerActivity($settings))];
    }

    /** @param array<string, string> $settings
     *  @return array{name: string, status: string, observed: string, remedy: string}|null
     */
    private function pollerActivity(array $settings): ?array
    {
        try {
            $configuration = $this->functions->configuration();
        } catch (\RuntimeException) {
            return null;
        }
        $id = filter_var($configuration['poller_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($id === false) {
            return null;
        }
        return InstallerHealthAssessment::pollerActivity(
            $settings['poller_lastrun_' . $id] ?? null,
            $settings['cron_interval'] ?? '300',
            $settings['poller_interval'] ?? '300',
            time()
        );
    }

    public function execute(string $token): bool
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Only the installation CLI worker may execute a reserved job.');
        }
        return $this->locked(function () use ($token): bool {
            $this->requireAutocommit();
            $job = $this->job();
            if (($job['target'] ?? '') !== $this->targetVersion() || ($job['state'] ?? '') !== 'waiting' || !hash_equals($job['token'] ?? '', $token)) {
                return false;
            }
            $this->authorizeJob($job);
            $job['state'] = 'running';
            $this->write(self::JOB, json_encode($job, JSON_THROW_ON_ERROR));
            try {
                if (!hash_equals($job['revision'] ?? '', $this->revision())) {
                    throw new \RuntimeException('The reserved plan changed before execution. Review it again.');
                }
                $workerPlan = $this->inspect();
                if (!is_array($workerPlan['checks'] ?? null) || $workerPlan['checks'] === []
                    || in_array(false, array_column($workerPlan['checks'], 'ok'), true)) {
                    throw new \RuntimeException('The worker runtime does not pass preflight.');
                }
                if (($job['operation'] ?? 'install') === 'finish') {
                    if ($this->functions->text($this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn()) !== $this->targetVersion()) {
                        throw new \RuntimeException('The installed schema no longer matches the finishing task.');
                    }
                    $pending = $this->engineClass()::synchronizeCollectors();
                    $complete = true;
                } else {
                    $installer = $this->engineClass()::reviewed([]);
                    if ($installer->installationResult()['ready'] !== true) {
                        throw new \RuntimeException('Native validation failed.');
                    }
                    $installer->executeReviewedInstallation();
                    $complete = $installer->installationResult()['complete']
                        && $this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn() === $this->targetVersion()
                        && in_array($this->functions->call('read_config_option', 'install_error', true), ['', false, null], true);
                    $pending = $installer->installationResult()['pending_collectors'];
                }
                if ($complete && !($job['cleanup_done'] ?? false)) {
                    $this->engineClass()::finishReviewedInstallation();
                    $job['cleanup_done'] = true;
                }
                $job['pending_collectors'] = $pending;
                $job['state'] = $complete ? ($pending > 0 ? 'partial' : 'succeeded') : 'failed';
            } catch (\Throwable) {
                $job['state'] = 'failed';
            }
            $job['finished'] = time();
            $this->write(self::JOB, json_encode($job, JSON_THROW_ON_ERROR));
            return $job['state'] === 'succeeded';
        }, 5);
    }

    /** @return array<string, string> */
    private function settings(): array
    {
        $result = [];
        foreach ($this->query('SELECT name, value FROM settings ORDER BY name')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $row = $this->functions->map($row);
            $result[$this->functions->text($row['name'] ?? null)] = $this->functions->text($row['value'] ?? null);
        }
        return $result;
    }

    /** @param array<string, string>|null $settings */
    private function revision(?array $settings = null, ?string $schema = null): string
    {
        $settings ??= $this->settings();
        $selected = [];
        foreach ($settings as $name => $value) {
            if ((str_starts_with($name, 'install_') && !in_array($name, ['install_step', 'install_prev', 'install_next', 'install_updated', 'install_started', 'install_progress', 'install_error', 'install_complete', 'install_version'], true))
                || str_starts_with($name, 'path_') || in_array($name, ['cron_interval', 'default_template'], true)) {
                $selected[$name] = $value;
            }
        }
        $selected['schema'] = $schema ?? $this->functions->text($this->query('SELECT cacti FROM version LIMIT 1')->fetchColumn());
        $selected['target'] = $this->targetVersion();
        if ($this->signer === null) {
            throw new \RuntimeException('The installation review signer is unavailable.');
        }
        return ($this->signer)()->sign(json_encode($selected, JSON_THROW_ON_ERROR));
    }

    /** @return array{state?: string, token?: string, actor?: string, actor_id?: int, target?: string, revision?: string, operation?: string, cleanup_done?: bool, pending_collectors?: int, started?: int, attempt?: int, finished?: int} */
    private function job(): array
    {
        $query = $this->database()->prepare('SELECT value FROM settings WHERE name = ?');
        $query->execute([self::JOB]);
        $value = $query->fetchColumn();
        if ($value === false) {
            return [];
        }
        $job = json_decode($this->functions->text($value), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($job) || !is_string($job['state'] ?? null) || !in_array($job['state'], ['waiting', 'running', 'failed', 'succeeded', 'partial'], true)
            || !is_string($job['token'] ?? null) || preg_match('/\\A[a-f0-9]{32}\\z/D', $job['token']) !== 1
            || !is_string($job['actor'] ?? null) || $job['actor'] === ''
            || !is_int($job['actor_id'] ?? null) || $job['actor_id'] < 1
            || !is_string($job['target'] ?? null) || $job['target'] === ''
            || !is_string($job['revision'] ?? null) || preg_match('/\\A[a-f0-9]{64}\\z/D', $job['revision']) !== 1
            || !is_int($job['started'] ?? null) || $job['started'] < 1
            || !is_int($job['attempt'] ?? null) || $job['attempt'] < 1 || $job['attempt'] === PHP_INT_MAX
            || (isset($job['finished']) && !is_int($job['finished']))
            || (isset($job['operation']) && !in_array($job['operation'], ['install', 'finish'], true))
            || (isset($job['cleanup_done']) && !is_bool($job['cleanup_done']))
            || (isset($job['pending_collectors']) && (!is_int($job['pending_collectors']) || $job['pending_collectors'] < 0))) {
            throw new \RuntimeException('Invalid installation job state.');
        }
        $result = ['state' => $job['state'], 'token' => $job['token'], 'actor' => $job['actor'],
            'revision' => $job['revision'], 'actor_id' => $job['actor_id'], 'target' => $job['target'], 'started' => $job['started'], 'attempt' => $job['attempt']];
        $result['operation'] = $job['operation'] ?? 'install';
        $result['cleanup_done'] = $job['cleanup_done'] ?? false;
        $result['pending_collectors'] = $job['pending_collectors'] ?? 0;
        if (isset($job['finished'])) {
            $result['finished'] = $job['finished'];
        }
        return $result;
    }

    private function write(string $name, string $value): void
    {
        $query = $this->database()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        if (!$query->execute([$name, $value])) {
            throw new \RuntimeException('Installer state could not be saved.');
        }
        $readback = $this->database()->prepare('SELECT value FROM settings WHERE name = ?');
        if (!$readback->execute([$name]) || $readback->fetchColumn() !== $value) {
            throw new \RuntimeException('Installer state persistence could not be confirmed.');
        }
    }

    /** @param array{actor?: string, actor_id?: int} $job */
    private function authorizeJob(array $job): void
    {
        // A reserved job has an attributed actor, not a reusable browser login.
        // Recheck realm 26 on the mutation connection without imposing the
        // console-only realm 8 on an admitted native installer browser user.
        $id = $job['actor_id'] ?? 0;
        $query = $this->database()->prepare('SELECT username, enabled, locked, must_change_password FROM user_auth WHERE id = ?');
        $query->execute([$id]);
        $user = $query->fetch(\PDO::FETCH_ASSOC);
        $method = $this->integer($this->functions->call('read_config_option', 'auth_method'));
        $guest = $this->functions->text($this->functions->call('read_config_option', 'guest_user'));
        if (!is_array($user) || !in_array($method, [1, 2, 3, 4], true)
            || ($user['username'] ?? null) !== ($job['actor'] ?? null)
            || ($user['enabled'] ?? null) !== 'on' || ($user['locked'] ?? null) === 'on'
            || ($user['must_change_password'] ?? null) === 'on'
            || $guest === (string) $id || $guest === ($user['username'] ?? null)
            || $this->functions->call('is_realm_allowed', 26, $id) !== true) {
            throw new \RuntimeException('The reserved installation operator is no longer eligible.');
        }
    }

    private function configurationStorage(): void
    {
        foreach (['settings', 'settings_user'] as $table) {
            $exists = $this->database()->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $exists->execute([$table]);
            if ($exists->fetchColumn() === false && $table === 'settings_user') {
                continue;
            }
            // The read holds the actual table's metadata lock through commit.
            // SHOW CREATE observes a temporary shadow, unlike information_schema.
            $this->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
            $row = $this->query('SHOW CREATE TABLE ' . $table)->fetch(\PDO::FETCH_ASSOC);
            $row = $this->functions->map($row);
            $ddl = $this->functions->text($row['Create Table'] ?? null);
            if (stripos($ddl, 'CREATE TEMPORARY') !== false || preg_match('/\\bENGINE=InnoDB\\b/i', $ddl) !== 1) {
                throw new \RuntimeException('Installer settings and user preferences must use persistent InnoDB tables.');
            }
        }
    }

    private function rollbackConfiguration(\Throwable $cause): void
    {
        $db = $this->database();
        if (!$db->inTransaction()) {
            throw new \RuntimeException('The failed configuration rollback could not be confirmed.', 0, $cause);
        }
        try {
            $rolledBack = $db->rollBack();
        } catch (\Throwable) {
            throw new \RuntimeException('The failed configuration rollback could not be confirmed.', 0, $cause);
        }
        if (!$rolledBack) {
            throw new \RuntimeException('The failed configuration rollback could not be confirmed.', 0, $cause);
        }
    }

    private function query(string $sql): \PDOStatement
    {
        $statement = $this->database()->query($sql);
        if ($statement === false) {
            throw new \RuntimeException('Installer database read failed.');
        }
        return $statement;
    }

    /** @return list<mixed> */
    private function nativeList(string $name): array
    {
        $value = $this->functions->call($name);
        if (!is_array($value)) {
            throw new \RuntimeException('Installer catalog is unavailable.');
        }
        return array_values($value);
    }

    /** @return array<array-key, string> */
    private function choices(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \RuntimeException('Invalid installer choices.');
        }
        $choices = [];
        foreach ($value as $key => $label) {
            $choices[$key] = $this->functions->text($label);
        }
        return $choices;
    }

    private function integer(mixed $value): int
    {
        if ($value === null || $value === false || $value === '') {
            return 0;
        }
        if ((!is_int($value) && !is_string($value)) || preg_match('/\\A[0-9]{1,15}\\z/D', (string) $value) !== 1) {
            throw new \RuntimeException('Invalid installer numeric state.');
        }
        return (int) $value;
    }

    /** @return class-string<InstallerEngine> */
    private function engineClass(): string
    {
        $class = 'Installer';
        if (!class_exists($class) || !is_a($class, InstallerEngine::class, true)) {
            throw new \RuntimeException('The native migration engine is unavailable.');
        }
        return $class;
    }

    private function targetVersion(): string
    {
        if (!defined('CACTI_VERSION')) {
            throw new \RuntimeException('The application version is unavailable.');
        }
        $version = constant('CACTI_VERSION');
        if (!is_string($version) || $version === '') {
            throw new \RuntimeException('The application version is invalid.');
        }
        return $version;
    }

    private function database(): \PDO
    {
        $database = LegacyConnectionRegistry::select(null);
        $local = $GLOBALS['local_db_cnn_id'] ?? null;
        if ($local instanceof \PDO && $local !== $database) {
            throw new \RuntimeException('Installer helpers must share the local mutation connection.');
        }
        if (!$database instanceof \PDO) {
            throw new \RuntimeException('The native installer connection is unavailable.');
        }
        return $database;
    }

    private function requireAutocommit(): void
    {
        // A queued worker cannot observe a reservation in somebody else's
        // transaction. Nor may recovery acknowledge an uncommitted outcome.
        if ($this->database()->inTransaction()
            || $this->functions->text($this->query('SELECT @@session.autocommit')->fetchColumn()) !== '1') {
            throw new \RuntimeException('Installer execution and recovery require autocommit and no caller-owned transaction.');
        }
    }

    /** @template T
     *  @param \Closure(): T $operation
     *  @return T
     */
    private function locked(\Closure $operation, int $waitSeconds = 0): mixed
    {
        $db = $this->database();
        $name = 'kadupul-installer-' . substr(hash('sha256', $this->functions->text($this->query('SELECT DATABASE()')->fetchColumn())), 0, 40);
        // A newly spawned worker can arrive before its reserving HTTP request
        // releases this lock. Give only that worker a bounded handoff window;
        // interactive mutations and recovery remain nonblocking.
        $lock = $db->prepare('SELECT GET_LOCK(?, ?)');
        $lock->execute([$name, $waitSeconds]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('The installation worker is busy. Read its status before retrying.');
        }
        try {
            return $operation();
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }
}
