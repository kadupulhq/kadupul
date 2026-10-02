<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace InstallerFailedPollFixture;

require dirname(__DIR__) . '/Helpers/PhpSource.php';
const CACTI_VERSION = '1.2.34';
const DB_STATUS_ERROR = 0, DB_STATUS_WARNING = 1, DB_STATUS_RESTART = 2, DB_STATUS_SUCCESS = 3, DB_STATUS_SKIPPED = 4;
$state = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
function read_config_option($name, $fresh = false)
{
    global $state;
    return $state[$name] ?? '';
}
function get_cacti_version()
{
    global $state;
    return $state['version'];
}
function cacti_version_compare($a, $b, $operator)
{
    return version_compare($a, $b, $operator);
}
function log_install_high(...$args) {}
function log_install_debug(...$args) {}
function clean_up_lines($value)
{
    return $value;
}
function get_installed_locales()
{
    return [];
}
function db_execute($sql)
{
    global $state;
    $state['cleared'] = true;
    foreach (array_keys($state) as $key) {
        if (str_starts_with($key, 'install_')) {
            unset($state[$key]);
        }
    } return true;
}
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/installer.php');
if (!is_string($source)) {
    throw new \RuntimeException('Installer source is unavailable.');
}
$constructor = \test_php_function_source($source, '__construct');
eval('namespace InstallerFailedPollFixture; final class Installer {
 const STEP_NONE=0, STEP_WELCOME=1, STEP_INSTALL=97, STEP_COMPLETE=98, STEP_ERROR=99;
 public $old_cacti_version, $stepError, $stepCurrent, $iconClass, $defaultAutomation,
 $errors, $templates, $eula, $cronInterval, $locales, $stepData, $theme;
 function setRuntime($runtime) {}
 function getStepDefault() { return 1; }
 function setStep($step) { global $state; $this->stepCurrent=$step; $state["install_step"]=$step; }
 function getLanguage() { return "en-US"; }
 function setLanguage($language) {}
 function getTheme() { return "modern"; }
 function setTheme($theme) { $this->theme=$theme; }
 function setDefaults($params) { if (isset($params["Step"])) { $this->setStep($params["Step"]); } }
' . $constructor . '}');
new Installer($state['parameters'] ?? []);
echo json_encode($state, JSON_THROW_ON_ERROR);
