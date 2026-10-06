<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once __DIR__ . '/NativeDevicePresentation.php';

/** Admit the actual comma-table SELECT which the shared metadata port cannot classify. */
final class NativePresentationContextConnection
{
    public function __construct(private NativeDeviceConnection $native) {}
    public function prepare(string $sql): NativeDeviceStatement|LegacyFormGoldenStatement|PDOStatement
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $sql));
        if (str_starts_with($normalized, 'SELECT data_template.id, data_template.name FROM (data_template, data_template_rrd, graph_templates_item) ')) {
            $this->native->queries[] = $normalized;
            $statement = $this->native->database->prepare($sql);
            return $this->native->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? $statement : new NativeDeviceStatement($statement);
        }
        return $this->native->prepare($sql);
    }
    public function quote(string $value): string|false
    {
        return $this->native->quote($value);
    }
    public function inTransaction(): bool
    {
        return $this->native->inTransaction();
    }
    public function errorCode(): string
    {
        return $this->native->errorCode();
    }
    public function errorInfo(): array
    {
        return $this->native->errorInfo();
    }
    public function lastInsertId(): string|false
    {
        return $this->native->lastInsertId();
    }
}

final class PresentationSinkContextEvidence
{
    public static function sources(): array
    {
        return [
            'automation_snmp.php',
            'cacti.sql',
            'cli/refresh_csrf.php',
            'color_templates.php',
            'composer.json',
            'composer.lock',
            'config/icons.json',
            'data_queries.php',
            'data_sources.php',
            'data_templates.php',
            'include/csrf.php',
            'include/global_arrays.php',
            'include/global_constants.php',
            'include/global_form.php',
            'include/global_languages.php',
            'include/global_session.php',
            'include/global_settings.php',
            'include/plugins.php',
            'include/runtime.php',
            'include/vendor/composer/installed.json',
            'include/vendor/csrf/csrf-conf.php',
            'include/vendor/csrf/csrf-magic.php',
            'lib/aggregate.php',
            'lib/api_aggregate.php',
            'lib/api_automation.php',
            'lib/api_data_source.php',
            'lib/api_device.php',
            'lib/api_graph.php',
            'lib/api_tree.php',
            'lib/auth.php',
            'lib/data_query.php',
            'lib/data_source_profile_integrity.php',
            'lib/database.php',
            'lib/export.php',
            'lib/functions.php',
            'lib/graph_fonts.php',
            'lib/graph_data_removal.php',
            'lib/graph_item_editor.php',
            'lib/graph_template_input.php',
            'lib/graphs.php',
            'lib/headers_secure.php',
            'lib/html.php',
            'lib/html_filter.php',
            'lib/html_form.php',
            'lib/html_form_template.php',
            'lib/html_graph.php',
            'lib/html_reports.php',
            'lib/html_tree.php',
            'lib/html_utility.php',
            'lib/html_validate.php',
            'lib/import.php',
            'lib/mib_cache.php',
            'lib/path_helpers.php',
            'lib/plugins.php',
            'lib/poller.php',
            'lib/reference_write.php',
            'lib/reports.php',
            'lib/rrd.php',
            'lib/rrd_maintenance.php',
            'lib/snmp.php',
            'lib/snmpagent.php',
            'lib/template.php',
            'lib/time.php',
            'lib/timespan_settings.php',
            'lib/utility.php',
            'lib/variables.php',
            'lib/xml.php',
            'src/Graphing/Domain/Font/GraphFont.php',
            'src/Graphing/Domain/Font/GraphFontMethod.php',
            'src/Graphing/Domain/Font/GraphFontProfile.php',
            'src/Graphing/Domain/Font/GraphFontResolver.php',
            'src/Graphing/Infrastructure/Fontconfig/InstalledFontFamilies.php',
            'src/IdentityAccess/Infrastructure/Legacy/PermissionMutation.php',
            'src/Platform/Contract/IconRegistry.php',
            'src/Platform/Infrastructure/Legacy/HostDataSubstitution.php',
            'src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php',
            'src/Platform/Infrastructure/Legacy/LegacyRequestContext.php',
            'tests/Fixtures/legacy-form-golden-scenarios.php',
            'tests/Fixtures/legacy-form-golden.php',
            'tests/Fixtures/presentation-sink-context-native.php',
            'tests/Helpers/NativeChildCoverageEvidence.php',
            'tests/Helpers/NativeDevicePresentation.php',
            'tests/Helpers/PresentationSinkContextEvidence.php',
            'tests/Unit/PresentationSinkContextNativeTest.php',
        ];
    }
    public static function extraTables(): array
    {
        return ['color_templates', 'color_template_items', 'colors', 'automation_snmp', 'automation_snmp_items', 'data_template', 'data_input', 'data_input_fields', 'data_input_data', 'data_source_profiles', 'data_source_profiles_rra', 'data_source_profiles_cf', 'snmp_query_graph_rrd', 'snmp_query_graph_rrd_sv', 'snmp_query_graph_sv', 'user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_realm', 'user_auth_group_realm', 'user_auth_perms', 'user_auth_group_perms', 'settings', 'settings_user', 'graph_templates_graph'];
    }
    public static function markers(): array
    {
        return ['actual-presentation-context-completed', 'canonical-records-preserved', 'actual-request-outcome-verified'];
    }
    public static function database(string $root, string $directory): PDO
    {
        $database = NativeDevicePresentation::database($root, $directory);
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) throw new RuntimeException('Canonical presentation context schema unavailable');
        foreach (self::extraTables() as $table) {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\)\s+ENGINE=[^;]+;/s', $schema, $match) !== 1) throw new RuntimeException('Missing canonical presentation context table: ' . $table);
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $database->exec($match[0]);
            } else {
                $columns = [];
                foreach (explode("\n", $match[1]) as $line) {
                    $line = rtrim(trim($line), ',');
                    if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                    $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
                    $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                    $columns[] = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
                }
                $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
            }
        }
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $database->sqliteCreateFunction('CONCAT', static fn(...$values) => in_array(null, $values, true) ? null : implode('', $values));
        }
        return $database;
    }
    public static function snapshot(PDO $database): array
    {
        $state = NativeDevicePresentation::snapshot($database);
        foreach (self::extraTables() as $table) {
            $rows = $database->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
            usort($rows, static fn(array $a, array $b): int => json_encode($a) <=> json_encode($b));
            $state[$table] = $rows;
        }
        return $state;
    }
}
