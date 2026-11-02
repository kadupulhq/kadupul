<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/** Bind native installation state at the compatibility boundary. */
function prime_default_settings()
{
    global $settings, $config;
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerDefaultSettings::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerDefaultSettings.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    if (is_array($settings) && !isset($_SESSION['settings_primed'])) {
        (new \Kadupul\Platform\Infrastructure\Legacy\InstallerDefaultSettings(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->prime($settings, (string) ($config['cacti_db_version'] ?? ''));
    }
    $_SESSION['settings_primed'] = true;
}

/**
 * prime_ldap_tls_default - the certificate requirement to store when a
 *   database has none saved.
 *
 * The web installer primes settings before upgrade_to_1_2_31() runs, so
 * without this the upgrade would find Demand already stored and an existing
 * install that uses LDAPS or StartTLS would stop logging in. That install gets
 * Never, the level it has always run at. A new install, or one without LDAP
 * encryption, gets the Demand default.
 *
 * @param (int) the default from the setting definition
 *
 * @return (int) the requirement to store
 */
function prime_ldap_tls_default($default)
{
    global $config;
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerDefaultSettings::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerDefaultSettings.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerDefaultSettings(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->ldapDefault($default, (string) ($config['cacti_db_version'] ?? ''));
}

/** Bind native installation state at the compatibility boundary. */
function install_create_csrf_secret($file)
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerCsrfSecret::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerCsrfSecret.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerCsrfSecret(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->create($file);
}

/** Bind native installation state at the compatibility boundary. */
function install_test_local_database_connection()
{
    global $database_type, $database_hostname, $database_username, $database_password, $database_default, $database_type, $database_port, $database_retries, $database_ssl, $database_ssl_key, $database_ssl_cert, $database_ssl_ca;

    if (!isset($database_ssl))      $database_ssl      = false;
    if (!isset($database_ssl_key))  $database_ssl_key  = false;
    if (!isset($database_ssl_cert)) $database_ssl_cert = false;
    if (!isset($database_ssl_ca))   $database_ssl_ca   = false;

    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerDatabaseDiagnostics.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->connection([$database_hostname, $database_username, $database_password, $database_default, $database_type, $database_port, $database_retries, $database_ssl, $database_ssl_key, $database_ssl_cert, $database_ssl_ca]);
}

/** Bind native installation state at the compatibility boundary. */
function install_test_remote_database_connection()
{
    global $rdatabase_type, $rdatabase_hostname, $rdatabase_username, $rdatabase_password, $rdatabase_default, $rdatabase_type, $rdatabase_port, $rdatabase_retries, $rdatabase_ssl, $rdatabase_ssl_key, $rdatabase_ssl_cert, $rdatabase_ssl_ca;

    if (!isset($rdatabase_ssl))      $rdatabase_ssl      = false;
    if (!isset($rdatabase_ssl_key))  $rdatabase_ssl_key  = false;
    if (!isset($rdatabase_ssl_cert)) $rdatabase_ssl_cert = false;
    if (!isset($rdatabase_ssl_ca))   $rdatabase_ssl_ca   = false;

    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerDatabaseDiagnostics.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->connection([$rdatabase_hostname, $rdatabase_username, $rdatabase_password, $rdatabase_default, $rdatabase_type, $rdatabase_port, $rdatabase_retries, $rdatabase_ssl, $rdatabase_ssl_key, $rdatabase_ssl_cert, $rdatabase_ssl_ca]);
}

/** Bind native installation state at the compatibility boundary. */
function install_test_temporary_table()
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerDatabaseDiagnostics.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerDatabaseDiagnostics(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->temporaryTable();
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_execute($sql, $params = array(), $log = true)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->execute($sql, $params, (bool) $log);
}

/** Bind the native diagnostic reset separately from the typed read/outcome rules. */
function db_install_reset_error(): void
{
    global $database_last_error;
    $database_last_error = false;
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_fetch_function($func, $sql, $params = array(), $log = true)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->fetch($func, $sql, $params, (bool) $log);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_fetch_assoc($sql, $params = array(), $log = true)
{
    return db_install_fetch_function('db_fetch_assoc_prepared', $sql, $params, $log);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_fetch_cell($sql, $params = array(), $log = true)
{
    return db_install_fetch_function('db_fetch_cell_prepared', $sql, $params, $log);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_fetch_row($sql, $params = array(), $log = true)
{
    return db_install_fetch_function('db_fetch_row_prepared', $sql, $params, $log);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_add_column($table, $column, $ignore = true)
{
    global $database_last_error;
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    $result = (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->addColumn($table, $column, (bool) $ignore);
    if ($result['warning'] !== null) {
        $database_last_error = $result['warning'];
    }
    db_install_add_cache($result['status'], $result['sql']);
    return $result['status'];
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_add_key($table, $type, $key, $columns, $using = '')
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->addKey($table, $type, $key, $columns, $using);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_drop_key($table, $type, $key)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->dropKey($table, $type, $key);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_drop_table($table)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->dropTable($table);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_rename_table($table, $newname)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->renameTable($table, $newname);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_drop_column($table, $column)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->dropColumn($table, $column);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_add_cache($status, $sql, $params = null)
{
    global $cacti_upgrade_version, $database_last_error, $database_upgrade_status;
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationProgress::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationProgress.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    $database_upgrade_status ??= [];
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationProgress(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->record($status, $sql, is_array($params) ? $params : null, (string) $cacti_upgrade_version, $database_last_error, $database_upgrade_status);
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function find_search_paths($os = 'unix')
{
    global $config;
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerToolPaths.php';
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->searchPaths($os, $config['php_path'] ?? null);
}

/** Retain historical upgrade callers while the typed service owns execution rules. */
function db_install_swap_setting($old_setting, $new_setting)
{
    if (!enum_exists(\Kadupul\Platform\Domain\Installer\MigrationStatus::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Domain/Installer/MigrationStatus.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerMigrationDatabase.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerMigrationDatabase(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->swapSetting($old_setting, $new_setting);
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function find_best_path($binary_name)
{
    global $config;
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerToolPaths.php';
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->bestPath($binary_name, $config['cacti_server_os'], $config['php_path'] ?? null);
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function install_setup_get_templates()
{
    global $config;
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerTemplateCatalog.php';
    $php = extension_loaded('simplexml') && extension_loaded('zlib') ? null : read_config_option('path_php_binary');
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerTemplateCatalog($config['base_path']))->templates($php, (bool) ($config['is_web'] ?? false));
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function install_setup_get_tables()
{
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerTableCatalog.php';
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerTableCatalog(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->tables();
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function to_array($data)
{
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerTemplateCatalog.php';
    return \Kadupul\Platform\Infrastructure\Legacy\InstallerTemplateCatalog::toArray($data);
}

/* Here, we define each name, default value, type, and path check for each value
we want the user to input. The "name" field must exist in the 'settings' table for
this to work. Kadupul also uses different default values depending on what OS it is
running on. */

/** Compatibility boundary; catalog rules live in the typed installation service. */
function install_tool_path($name, $defaultPaths)
{
    global $config, $settings;
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerToolPaths.php';
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->tool($name, $defaultPaths, $config, $settings ?? []);
}

/** Compatibility boundary; catalog rules live in the typed installation service. */
function install_file_paths()
{
    global $config, $settings;
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerToolPaths.php';
    require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->definitions($config, $settings);
}

/** Bind native installation state at the compatibility boundary. */
function remote_update_config_file()
{
    global $config, $rdatabase_type, $rdatabase_hostname, $rdatabase_username,
    $rdatabase_password, $rdatabase_default, $rdatabase_type, $rdatabase_port, $rdatabase_retries,
    $rdatabase_ssl, $rdatabase_ssl_key, $rdatabase_ssl_cert, $rdatabase_ssl_ca;

    global $database_type, $database_hostname, $database_username,
    $database_password, $database_default, $database_type, $database_port, $database_retries,
    $database_ssl, $database_ssl_key, $database_ssl_cert, $database_ssl_ca;

    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorConfiguration::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerCollectorConfiguration.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorConfiguration($config['base_path'], new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->update(
        [$rdatabase_hostname, $rdatabase_username, $rdatabase_password, $rdatabase_default, $rdatabase_type, $rdatabase_port, $rdatabase_retries, $rdatabase_ssl, $rdatabase_ssl_key, $rdatabase_ssl_cert, $rdatabase_ssl_ca],
        ['dbdefault' => $database_default,
            'dbhost' => $database_hostname,
            'dbuser' => $database_username,
            'dbpass' => $database_password,
            'dbport' => $database_port,
            'dbretries' => $database_retries,
            'dbssl' => $database_ssl ? 'on' : '',
            'dbsslkey' => $database_ssl_key,
            'dbsslcert' => $database_ssl_cert,
            'dbsslca' => $database_ssl_ca],
    );
}

/**
 * set_install_config_option - Set a config option into the local database only
 *
 * @param $config_name - the name of the configuration setting as specified $settings array
 * @param $value       - the values to be saved
 *
 * @return null        - nothing is returned
 */
function set_install_config_option($name, $value)
{
    global $config, $local_db_cnn_id;
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerConfigurationWriter::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerConfigurationWriter.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerConfigurationWriter(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->write($name, $value, $config['base_path'] ?? dirname(__DIR__), is_object($local_db_cnn_id) ? $local_db_cnn_id : null);
}

/** Bind native installation state at the compatibility boundary. */
function import_colors()
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerColorImport::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerColorImport.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerColorImport(dirname(__DIR__), new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->import();
}

/** Bind native installation state at the compatibility boundary. */
function log_install_debug($section, $text, $background = false)
{
    log_install_and_file(POLLER_VERBOSITY_DEBUG, $text, $section, $background);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_low($section, $text, $background = false)
{
    log_install_and_file(POLLER_VERBOSITY_LOW, $text, $section, $background);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_medium($section, $text, $background = false)
{
    log_install_and_file(POLLER_VERBOSITY_MEDIUM, $text, $section, $background);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_high($section, $text, $background = false)
{
    log_install_and_file(POLLER_VERBOSITY_HIGH, $text, $section, $background);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_always($section, $text, $background = false)
{
    log_install_and_file(POLLER_VERBOSITY_NONE, $text, $section, $background);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_and_file($level, $text, $section = '', $background = false)
{
    global $config;
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->record($level, $text, $section, (bool) $background, $config['base_path']);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_section_level($section)
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->sectionLevel($section);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_level($option, $default_level)
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->level($option, $default_level);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_level_sanitize($level, $default_level = POLLER_VERBOSITY_NONE, $option = '')
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::normalize($level, $default_level);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_level_name($level)
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::label($level);
}

/** Bind native installation state at the compatibility boundary. */
function log_install_to_file($section, $data, $flags = FILE_APPEND, $level = POLLER_VERBOSITY_DEBUG, $force = false)
{
    global $config;
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerLogger::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerLogger.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerLogger(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->write($section, $data, $flags, $level, (bool) $force, defined('log_install_echo'), $config['base_path']);
}

/** repair_automation() - Repairs mangled automation graph rules based
 *  upon the change in the way that Kadupul imports the Graph Templates after
 *  Kadupul 1.2.4.
 **/
function repair_automation()
{
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerAutomationRepair::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerAutomationRepair.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    (new \Kadupul\Platform\Infrastructure\Legacy\InstallerAutomationRepair(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->repair();
}

/** Bind native installation state at the compatibility boundary. */
function install_full_sync()
{
    global $config;
    include_once($config['base_path'] . '/lib/poller.php');
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorSynchronization::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/InstallerCollectorSynchronization.php';
    }
    if (!class_exists(\Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions::class)) {
        require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/NativeInstallerFunctions.php';
    }
    return (new \Kadupul\Platform\Infrastructure\Legacy\InstallerCollectorSynchronization(new \Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions()))->run();
}
