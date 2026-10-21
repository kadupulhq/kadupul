<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
$entry = $argv[1];
$scenario = $argv[2];
$directory = sys_get_temp_dir() . '/installer cache ' . bin2hex(random_bytes(8));
$filesystem = new Filesystem();
$filesystem->mkdir([$directory . '/config/packages', $directory . '/bin', $directory . '/lib', $directory . '/include', $directory . '/cli', $directory . '/install', $directory . '/var/cache/dev'], 0700);

function cacheProbe(string $directory): string
{
    $process = new Process([PHP_BINARY, $directory . '/probe.php'], $directory);
    $process->mustRun();
    return $process->getOutput();
}

function read_config_option(string $name, mixed ...$args): string
{
    return $GLOBALS['cache_php'];
}

function __(string $message, mixed ...$args): string
{
    return sprintf($message, ...$args);
}

function log_install_always(mixed ...$args): void {}
function log_install_medium(mixed ...$args): void {}
function log_install_high(mixed ...$args): void {}
function log_install_debug(mixed ...$args): void {}
function clean_up_lines(string $value): string { return $value; }

final class CacheMutationReached extends RuntimeException {}

function set_install_config_option(string $name, mixed $value): void
{
    $GLOBALS['cache_seen_at_mutation'] = cacheProbe($GLOBALS['directory']);
    throw new CacheMutationReached();
}

try {
    $filesystem->dumpFile($directory . '/composer.json', '{"name":"kadupulhq/cache-fixture"}');
    $filesystem->dumpFile($directory . '/config/bundles.php', '<?php return [Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle::class => ["all" => true]];');
    $filesystem->dumpFile($directory . '/config/routes.yaml', '');
    $filesystem->dumpFile($directory . '/config/packages/framework.yaml', "framework:\n    secret: 'owned-cache-fixture'\n    translator: { fallbacks: ['en'] }\n");
    $filesystem->symlink($root . '/locales', $directory . '/locales');
    $filesystem->dumpFile($directory . '/var/cache/dev/owned-sentinel', 'keep');
    $bootstrap = '<?php require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
        . '$kernel=new \\Kadupul\\Kernel("prod",false);'
        . '(new \\ReflectionProperty(\\Symfony\\Component\\HttpKernel\\Kernel::class,"projectDir"))->setValue($kernel,__DIR__);';
    $filesystem->dumpFile($directory . '/probe.php', $bootstrap . '$kernel->boot();$container=$kernel->getContainer();$value=$container->get("cache_probe.service")->value;if($value==="new"&&!$container->has("cache_probe.added")){throw new \\RuntimeException("New service was not compiled");}echo $value;$kernel->shutdown();');
    $console = $bootstrap . 'file_put_contents(dirname(__DIR__)."/console-env.json",json_encode([getenv("APP_ENV"),getenv("APP_DEBUG")]));'
        . '$application=new \\Symfony\\Bundle\\FrameworkBundle\\Console\\Application($kernel);exit($application->run());';
    $filesystem->dumpFile($directory . '/bin/console', str_replace('setValue($kernel,__DIR__)', 'setValue($kernel,dirname(__DIR__))', $console));
    $services = static fn(string $value): string => "parameters:\n    cache_probe: '$value'\nservices:\n    cache_probe.service:\n        class: stdClass\n        public: true\n        properties: { value: '%cache_probe%' }\n" . ($value === 'new' ? "    cache_probe.added:\n        class: stdClass\n        public: true\n" : '');
    $value = $scenario === 'fresh' ? 'new' : 'old';
    $filesystem->dumpFile($directory . '/config/services.yaml', $services($value));
    $before = null;
    if ($scenario !== 'fresh') {
        $before = cacheProbe($directory);
        // Model an existing deployment, rather than a container built by this request.
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory . '/var/cache/prod', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                touch($file->getPathname(), time() - 10);
            }
        }
        $filesystem->dumpFile($directory . '/config/services.yaml', $services('new'));
        if (cacheProbe($directory) !== 'old') {
            throw new RuntimeException('The fixture did not reproduce a stale production container');
        }
    }
    if ($scenario === 'command-failure') {
        $filesystem->dumpFile($directory . '/bin/console', '<?php echo "private-child-output";fwrite(STDERR,"private-child-error");exit(61);');
    }
    $cache_php = match ($scenario) {
        'configured-other-php' => $directory . '/missing php',
        'fallback' => '',
        default => PHP_BINARY,
    };
    $config = ['base_path' => $directory, 'poller_id' => 2, 'connection' => 'offline'];
    $error = null;
    $exit = null;
    $output = '';
    if ($entry === 'installer') {
        define('CACTI_VERSION', trim(file_get_contents($root . '/include/cacti_version')));
        require $root . '/lib/installer.php';
        $reflection = new ReflectionClass(Installer::class);
        $installer = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('mode')->setValue($installer, Installer::MODE_POLLER);
        try {
            $reflection->getMethod('install')->invoke($installer);
        } catch (CacheMutationReached) {
            // First install-state mutation: no conversion or schema write has run.
        } catch (Throwable $failure) {
            $error = $failure->getMessage();
        }
    } else {
        $filesystem->copy($root . '/cli/upgrade_database.php', $directory . '/cli/upgrade_database.php');
        $filesystem->copy($root . '/lib/installer.php', $directory . '/lib/installer.php');
        foreach (['poller', 'rrd_maintenance'] as $library) {
            $filesystem->dumpFile($directory . '/lib/' . $library . '.php', '<?php require ' . var_export($root . '/lib/' . $library . '.php', true) . ';');
        }
        foreach (['lib/data_query.php', 'lib/utility.php', 'install/functions.php'] as $file) {
            $filesystem->dumpFile($directory . '/' . $file, '<?php');
        }
        $filesystem->dumpFile($directory . '/include/cli_check.php', '<?php require ' . var_export($root . '/include/vendor/autoload.php', true) . ';'
            . '$config=' . var_export($config, true) . ';define("CACTI_VERSION",' . var_export(trim(file_get_contents($root . '/include/cacti_version')), true) . ');'
            . '$cacti_version_codes=[CACTI_VERSION=>"fixture"];'
            . 'function cacti_sizeof($value){return is_array($value)?count($value):0;}'
            . 'function get_cacti_version(){return CACTI_VERSION;}'
            . 'function read_config_option(...$args){return ' . var_export($cache_php, true) . ';}'
            . 'function __($message,...$args){return sprintf($message,...$args);}');
        $process = new Process([PHP_BINARY, $directory . '/cli/upgrade_database.php', ...($scenario === 'invalid-version' ? ['--forcever=not-a-version'] : [])], $directory, ['APP_ENV' => 'test', 'APP_DEBUG' => '1']);
        $exit = $process->run();
        $error = $process->getErrorOutput();
        $output = $process->getOutput();
    }
    echo json_encode([
        'before' => $before,
        'after' => cacheProbe($directory),
        'mutation' => $cache_seen_at_mutation ?? null,
        'error' => $error,
        'exit' => $exit,
        'output' => $output,
        'console_env' => is_file($directory . '/console-env.json') ? json_decode(file_get_contents($directory . '/console-env.json'), true, flags: JSON_THROW_ON_ERROR) : null,
        'dev' => file_get_contents($directory . '/var/cache/dev/owned-sentinel'),
    ], JSON_THROW_ON_ERROR);
} finally {
    $filesystem->remove($directory);
}
