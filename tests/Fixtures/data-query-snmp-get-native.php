<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// The actual query module, XML parser and cache writer run unchanged. Only
// SNMP transport and database syntax are ports; no network request is made.
function db_fetch_row_prepared($sql, $parameters)
{
    $statement = $GLOBALS['queryDatabase']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_cell_prepared($sql, $parameters)
{
    $statement = $GLOBALS['queryDatabase']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}
function db_execute_prepared($sql, $parameters)
{
    $GLOBALS['querySql'][] = preg_replace('/\s+/', ' ', trim($sql));
    $statement = $GLOBALS['queryDatabase']->prepare($sql);
    return $statement->execute($parameters);
}
function db_execute($sql)
{
    $GLOBALS['querySql'][] = $sql;
    if ($GLOBALS['queryDatabase']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        // Exact dialect translation, using the shipped composite primary key.
        $suffix = ' ON DUPLICATE KEY UPDATE field_value=VALUES(field_value), oid=VALUES(oid), present=VALUES(present)';
        if (!str_starts_with($sql, 'INSERT INTO host_snmp_cache ') || !str_ends_with($sql, $suffix)) {
            throw new RuntimeException('Unexpected native SNMP cache write');
        }
        $sql = substr($sql, 0, -strlen($suffix)) . ' ON CONFLICT(host_id,snmp_query_id,field_name,snmp_index) DO UPDATE SET field_value=excluded.field_value, oid=excluded.oid, present=excluded.present';
    }
    return $GLOBALS['queryDatabase']->exec($sql) !== false;
}
function db_qstr($value) { return $GLOBALS['queryDatabase']->quote($value); }
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function cacti_count($value) { return count($value); }
function __($message, ...$values) { return $values === array() ? $message : sprintf($message, ...$values); }
function __esc($message, ...$values) { return __($message, ...$values); }
function debug_log_insert($section, $message) { $GLOBALS['queryDebug'][] = array($section, $message); }
function cacti_snmp_session(...$arguments)
{
    $GLOBALS['queryTransport'][] = array('session', $arguments);
    return $GLOBALS['querySession'] = new class {
        public bool $closed = false;
        public function close(): void { $this->closed = true; }
    };
}
function cacti_snmp_session_walk($session, $oid)
{
    if ($session !== $GLOBALS['querySession'] || $oid !== '.1.3.6.1.2') throw new RuntimeException('Unexpected SNMP index walk');
    $GLOBALS['queryTransport'][] = array('walk', $oid);
    return array('.1.3.6.1.2.7' => '7', '.1.3.6.1.2.9' => '9');
}
function cacti_snmp_session_get($session, $oid)
{
    if ($session !== $GLOBALS['querySession']) throw new RuntimeException('SNMP session identity changed');
    $GLOBALS['queryTransport'][] = array('session-get', $oid);
    return array_key_exists('queryReturnOverride', $GLOBALS) ? $GLOBALS['queryReturnOverride'] : 'sample-' . substr($oid, strrpos($oid, '.') + 1);
}
function cacti_snmp_get(...$arguments)
{
    $GLOBALS['queryTransport'][] = array('get', $arguments);
    $oid = $arguments[2];
    return array_key_exists('queryReturnOverride', $GLOBALS) ? $GLOBALS['queryReturnOverride'] : 'sample-' . substr($oid, strrpos($oid, '.') + 1);
}

function native_query_snmp_get(string $root, string $directory, array $scenario, ?PDO $database = null): array
{
    set_error_handler(static function ($severity, $message, $file, $line): never {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require_once $root . '/include/global_constants.php';
        // The values are the exact fallback constants in lib/snmp.php.
        foreach (array('GUESS' => 1, 'ASCII' => 2, 'HEX' => 3) as $name => $value) {
            if (!defined('SNMP_STRING_OUTPUT_' . $name)) define('SNMP_STRING_OUTPUT_' . $name, $value);
        }
        require_once $root . '/lib/xml.php';
        require_once $root . '/lib/data_query.php';
        $schema = file_get_contents($root . '/cacti.sql');
        if ($schema === false) throw new RuntimeException('Canonical SNMP schema unavailable');
        $database ??= new PDO('sqlite:' . $directory . '/query.sqlite', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        foreach (array('host', 'host_snmp_query', 'host_snmp_cache') as $table) {
            if (preg_match('/CREATE TABLE `?' . $table . '`? \((.*?)\)\s+ENGINE=/s', $schema, $match) !== 1) throw new RuntimeException('Missing canonical SNMP table');
            if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
                $database->exec('CREATE TABLE ' . $table . ' (' . $match[1] . ') ENGINE=InnoDB');
                continue;
            }
            $columns = array();
            foreach (explode("\n", $match[1]) as $line) {
                $line = rtrim(trim($line), ',');
                if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
                $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)\(\d+\)(?: unsigned)?/i', 'INTEGER', $line);
                $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
                $columns[] = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
            }
            $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
        }
        $host = array('hostname' => '192.0.2.7', 'snmp_community' => 'fictional-community', 'snmp_version' => 3,
            'snmp_username' => 'fictional-user', 'snmp_password' => 'fictional-auth', 'snmp_auth_protocol' => 'SHA',
            'snmp_priv_passphrase' => 'fictional-privacy', 'snmp_priv_protocol' => 'AES', 'snmp_context' => 'fixture-context',
            'snmp_engine_id' => 'fixture-engine', 'snmp_port' => 1161, 'snmp_timeout' => 1501,
            'ping_retries' => 2, 'max_oids' => 10, 'bulk_walk_size' => 5);
        $database->prepare('INSERT INTO host (id,' . implode(',', array_keys($host)) . ') VALUES (' . implode(',', array_fill(0, count($host) + 1, '?')) . ')')->execute(array(4, ...array_values($host)));
        $database->exec("INSERT INTO host_snmp_query(host_id,snmp_query_id,sort_field) VALUES(4,10,'index')");
        $database->exec("INSERT INTO host_snmp_cache(host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES(4,10,'value','old','7','old-oid'),(4,10,'stale','old','99','old-oid'),(5,10,'adjacent','untouched','7','adjacent-oid')");
        $GLOBALS['queryDatabase'] = $database;
        $GLOBALS['queryTransport'] = $GLOBALS['querySql'] = $GLOBALS['queryDebug'] = array();
        // Metadata enters through the existing parsed-XML cache, not a second
        // implementation of the query. The real XML parser supplies both fields.
        $xml = '<query><oid_index>.1.3.6.1.2</oid_index><index_order>index</index_order><fields><index><source>index</source><direction>input</direction></index>';
        foreach (array('value', 'capture') as $field) {
            $xml .= '<' . $field . '><source>' . ($field === 'value' ? 'value' : 'VALUE/REGEXP:sample-(.*)') . '</source><direction>input</direction><method>get</method><oid>.1.3.6.1.' . ($field === 'value' ? '3' : '4') . '</oid>';
            if (array_key_exists('format', $scenario) && $scenario['format'] !== null) $xml .= '<output_format>' . htmlspecialchars($scenario['format'], ENT_XML1) . '</output_format>';
            if ($scenario['suffix']) $xml .= '<oid_suffix>5</oid_suffix>';
            $xml .= '</' . $field . '>';
        }
        $xml .= '</fields></query>';
        $parsed = xml2array($xml);
        if (($scenario['format'] ?? false) === null) {
            foreach (array('value', 'capture') as $field) $parsed['fields'][$field]['output_format'] = null;
        }
        if ($scenario['rewrite']) {
            $parsed['fields']['value']['oid_rewrite_pattern'] = 'OID/REGEXP:^\\.1\\.3\\.6\\.1\\.3';
            $parsed['fields']['value']['oid_rewrite_replacement'] = '.1.3.6.1.30';
        }
        $GLOBALS['data_query_xml_arrays'] = array(10 => $parsed);
        mkdir($directory . '/library', 0700);
        foreach (array('snmp.php', 'xml.php') as $file) {
            if (file_put_contents($directory . '/library/' . $file, '<?php // Capabilities were loaded from the registered native producer.') === false) throw new RuntimeException('Cannot create owned capability bootstrap');
        }
        $GLOBALS['config'] = array('library_path' => $directory . '/library');
        $result = query_snmp_host(4, 10);
        $rows = $database->query('SELECT host_id,snmp_query_id,field_name,field_value,snmp_index,oid,present FROM host_snmp_cache ORDER BY host_id,field_name,snmp_index')->fetchAll(PDO::FETCH_ASSOC);
        if ($result !== true || !$GLOBALS['querySession']->closed) throw new RuntimeException('Query did not complete and close its admitted session');
        $expectedRows = array();
        foreach (array('capture', 'index', 'value') as $field) {
            foreach (array(7, 9) as $index) {
                $oid = $field === 'index' ? '' : '.1.3.6.1.' . ($field === 'capture' ? '4' : ($scenario['rewrite'] ? '30' : '3')) . '.' . $index . ($scenario['suffix'] ? '.5' : '');
                $expectedRows[] = array('host_id' => 4, 'snmp_query_id' => 10, 'field_name' => $field,
                    'field_value' => $field === 'index' ? (string) $index : ($field === 'value' ? 'sample-' : '') . ($scenario['suffix'] ? '5' : (string) $index),
                    'snmp_index' => (string) $index, 'oid' => $oid, 'present' => 1);
            }
        }
        $expectedRows[] = array('host_id' => 5, 'snmp_query_id' => 10, 'field_name' => 'adjacent', 'field_value' => 'untouched', 'snmp_index' => '7', 'oid' => 'adjacent-oid', 'present' => 1);
        if ($rows !== $expectedRows) throw new RuntimeException('Actual query cache identity, regexp extraction or stale/adjacent preservation failed');
        $expectedTransport = array(array('session', array('192.0.2.7', 'fictional-community', 3, 'fictional-user', 'fictional-auth', 'SHA', 'fictional-privacy', 'AES', 'fixture-context', 'fixture-engine', 1161, 1501, 2, 10, 5)), array('walk', '.1.3.6.1.2'));
        foreach (array('value', 'capture') as $field) {
            foreach (array(7, 9) as $index) {
                $oid = '.1.3.6.1.' . ($field === 'capture' ? '4' : ($scenario['rewrite'] ? '30' : '3')) . '.' . $index . ($scenario['suffix'] ? '.5' : '');
                $format = $scenario['format'] ?? null;
                $expectedTransport[] = $format === null ? array('session-get', $oid) : array('get', array('192.0.2.7', 'fictional-community', $oid, 3, 'fictional-user', 'fictional-auth', 'SHA', 'fictional-privacy', 'AES', 'fixture-context', 1161, 1501, SNMP_POLLER, 'fixture-engine', $format === 'hex' ? SNMP_STRING_OUTPUT_HEX : ($format === 'ascii' ? SNMP_STRING_OUTPUT_ASCII : SNMP_STRING_OUTPUT_GUESS)));
            }
        }
        if ($GLOBALS['queryTransport'] !== $expectedTransport || count($GLOBALS['querySql']) !== 3) throw new RuntimeException('Actual query changed transport tuples, ordering or cache write budget');
        if ($database->query('SELECT ' . implode(',', array_keys($host)) . ' FROM host WHERE id=4')->fetch(PDO::FETCH_ASSOC) !== $host) throw new RuntimeException('Fixed walk unexpectedly mutated host settings');
        $GLOBALS['nativeChildCoverageMarkers'] = array('actual-snmp-query-completed', 'actual-snmp-cache-readback', 'actual-session-close');
        return array('rows' => $rows, 'transport' => $GLOBALS['queryTransport'], 'sql' => $GLOBALS['querySql'], 'host' => $host);
    } finally {
        restore_error_handler();
    }
}
