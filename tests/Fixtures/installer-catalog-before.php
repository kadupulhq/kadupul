<?php

// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Frozen catalog behavior from installer base 921b5d333a; never used by production.
function installer_before_find_search_paths($os = 'unix')
{
    global $config;

    if ($os == 'win32') {
        $search_suffix = ';';
        $search_slash  = '\\';
        $search_paths  = array(
            'c:/usr/bin',
            'c:/cacti',
            'c:/rrdtool',
            'c:/spine',
            'c:/php',
            'c:/net-snmp/bin',
            'c:/progra~1/net-snmp/bin',
            'c:/progra~1/php',
            'c:/progra~1/spine',
            'c:/progra~1/spine/bin',
            'd:/usr/bin',
            'd:/cacti',
            'd:/rrdtool',
            'd:/spine',
            'd:/php',
            'd:/net-snmp/bin',
            'd:/progra~1/net-snmp/bin',
            'd:/progra~1/php',
            'd:/progra~1/spine',
            'd:/progra~1/spine/bin'
        );
    } else {
        $search_suffix = ':';
        $search_slash  = '';
        $search_paths  = array(
            '/bin',
            '/sbin',
            '/usr/bin',
            '/usr/sbin',
            '/usr/local/bin',
            '/usr/local/sbin',
            '/usr/local/spine/bin',
            '/usr/spine/bin'
        );
    }

    $env_path = getenv('PATH');
    if ($env_path) {
        $env_paths = explode($search_suffix, $env_path);
        if (!empty($search_slash)) {
            foreach ($env_paths as $env_key => $env_folder) {
                $env_paths[$env_key] = str_replace($search_slash, '/', $env_folder);
            }
        }
        $search_paths = array_merge($env_paths, $search_paths);
    }

    $env_php = getenv('PHP_BINDIR');
    if ($env_php) {
        $search_paths = array_merge(explode($search_suffix, $env_php), $search_paths);
    }

    if (!empty($config['php_path'])) {
        $search_paths = array_merge(explode($search_suffix, $config['php_path']), $search_paths);
    }

    // Filter out any blank lines and then make sure those remaining are unique
    $search_paths = array_unique(array_filter($search_paths, function ($value) {
        return !is_null($value) && $value !== '';
    }));
    return $search_paths;
}

function installer_before_find_best_path($binary_name)
{
    global $config;

    $search_paths = installer_before_find_search_paths($config['cacti_server_os']);

    if (cacti_sizeof($search_paths)) {
        foreach ($search_paths as $path) {
            $desired_path = $path . '/' . $binary_name;
            if ((@file_exists($desired_path)) && (@is_readable($desired_path))) {
                return $desired_path;
            }
        }
    }
    return '';
}

function installer_before_install_tool_path($name, $defaultPaths)
{
    global $config, $settings;

    $os = $config['cacti_server_os'];
    if (!isset($defaultPaths[$os])) {
        return false;
    }

    $tool = array(
        'friendly_name' => $name,
        'description' => __('Path for %s', $name),
        'method' => 'filepath',
        'max_length' => 255,
        'default' => ''
    );

    log_install_debug('file', "$name: Locations ($os), Paths: " . clean_up_lines(var_export($defaultPaths, true)));
    if (isset($settings) && isset($settings['path']) && isset($settings['path']['path_' . $name])) {
        $tool = $settings['path']['path_' . $name];
    } elseif (isset($settings) && isset($settings['mail']) && isset($settings['mail'][$name])) {
        $tool = $settings['mail'][$name];
    }

    $which_tool = '';
    if (config_value_exists('path_' . $name)) {
        $which_tool = read_config_option('path_' . $name, true);
        log_install_high('file', "Using config location: $which_tool");
    }

    if (empty($which_tool) && isset($defaultPaths[$os])) {
        $defaultPath = $defaultPaths[$os];
        $basename = basename($defaultPath);
        log_install_debug('file', "Searching best path with location: $defaultPath");
        $which_tool = installer_before_find_best_path($basename);
        log_install_debug('file', "Searching best path with location return: $which_tool");
    }

    if (empty($which_tool)) {
        $which_tool = $defaultPath;
        log_install_high('file', "Nothing found defaulting to $defaultPath");
    }

    $tool['default'] = $which_tool;
    return $tool;
}

function installer_before_install_file_paths()
{
    global $config, $settings;

    $input = array();

    /* PHP Binary Path */
    $input['path_php_binary'] = installer_before_install_tool_path(
        'php_binary',
        array(
            'unix'  => '/bin/php',
            'win32' => 'c:/php/php.exe'
        )
    );

    // Workaround to support xampp
    if ($config['cacti_server_os'] == 'win32') {
        $paths = array('c:/php/php.exe', 'd:/php/php.exe', 'c:/xampp/php/php.exe', 'd:/xampp/php/php.exe');
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $input['path_php_binary']['default'] = $path;
                break;
            }
        }
    }

    // Preserve registration order and platform-specific defaults.
    $tools = array(
        'path_rrdtool' => array('rrdtool', array('unix' => '/usr/bin/rrdtool', 'win32' => 'c:/rrdtool/rrdtool.exe')),
        'path_snmpwalk' => array('snmpwalk', array('unix' => '/usr/bin/snmpwalk', 'win32' => 'c:/usr/bin/snmpwalk.exe')),
        'path_snmpget' => array('snmpget', array('unix' => '/usr/bin/snmpget', 'win32' => 'c:/usr/bin/snmpget.exe')),
        'path_snmpbulkwalk' => array('snmpbulkwalk', array('unix' => '/usr/bin/snmpbulkwalk', 'win32' => 'c:/usr/bin/snmpbulkwalk.exe')),
        'path_snmpgetnext' => array('snmpgetnext', array('unix' => '/usr/bin/snmpgetnext', 'win32' => 'c:/usr/bin/snmpgetnext.exe')),
        'path_snmptrap' => array('snmptrap', array('unix' => '/usr/bin/snmptrap', 'win32' => 'c:/usr/bin/snmptrap.exe')),
        'settings_sendmail_path' => array('settings_sendmail_path', array('unix' => '/usr/sbin/sendmail')),
    );
    foreach ($tools as $key => [$name, $defaultPaths]) {
        $input[$key] = installer_before_install_tool_path($name, $defaultPaths);
    }

    /* spine Binary Path */
    $input['path_spine'] = installer_before_install_tool_path(
        'spine',
        array(
            'unix'  => '/usr/local/spine/bin/spine',
            'win32' => 'c:/spine/bin/spine.exe'
        )
    );

    // Workaround to support *BSD systems
    if ($config['cacti_server_os'] == 'unix') {
        $paths = array('/usr/local/spine/bin/spine', '/usr/local/bin/spine');
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $input['path_spine']['default'] = $path;
                break;
            }
        }
    }

    $input['path_spine_config'] = $settings['path']['path_spine_config'];

    /* log file path */
    if (!config_value_exists('path_cactilog')) {
        $input['path_cactilog'] = $settings['path']['path_cactilog'];
    } else {
        $input['path_cactilog'] = $settings['path']['path_cactilog'];
        $input['path_cactilog']['default'] = read_config_option('path_cactilog');
    }

    if (empty($input['path_cactilog']['default'])) {
        $input['path_cactilog']['default'] = $config['base_path'] . '/log/cacti.log';
    }

    /* stderr log file path */
    if (!config_value_exists('path_cactilog')) {
        $input['path_stderrlog'] = $settings['path']['path_stderrlog'];
        if (empty($input['path_stderrlog']['default'])) {
            $input['path_stderrlog']['default'] = $config['base_path'] . '/log/cacti.stderr.log';
        }
    } else {
        $input['path_stderrlog'] = $settings['path']['path_stderrlog'];
        $input['path_stderrlog']['default'] = read_config_option('path_stderrlog');
    }

    /* RRDtool Version */
    if ((@file_exists($input['path_rrdtool']['default'])) && (($config['cacti_server_os'] == 'win32') || (is_executable($input['path_rrdtool']['default'])))) {
        $input['rrdtool_version'] = $settings['general']['rrdtool_version'];

        $temp_ver = get_installed_rrdtool_version();

        if (!empty($temp_ver)) {
            $input['rrdtool_version']['default'] = $temp_ver;
        }
    }

    foreach (array_keys($input) as $key) {
        if ($input[$key] === false) {
            unset($input[$key]);
        }
    }

    return $input;
}

function installer_before_install_setup_get_templates()
{
    global $config;

    if ($config['is_web']) {
        ini_set('zlib.output_compression', '0');
    }

    $templates = array(
        'ACME.xml.gz',
        'AKCP_Device.xml.gz',
        'APC_InfraStruXure_InRow_CRAC.xml.gz',
        'APC_InfraStruXure_PDU.xml.gz',
        'Apache_Webserver.xml.gz',
        'ArubaOS_switch.xml.gz',
        'Aruba_OSCX_switch_6x00.xml.gz',
        'Aruba_Instant_AP_Cluster.xml.gz',
        'Aruba_Wireless_Controller.xml.gz',
        'BayTech_PDU.xml.gz',
        'Cacti_Stats.xml.gz',
        'Cisco_Router.xml.gz',
        'Citrix_NetScaler_VPX.xml.gz',
        'Clearpass_policy_manager.xml.gz',
        'Dell_iDrac.xml.gz',
        'ESXi_Device.xml.gz',
        'Fortigate.xml.gz',
        'Generic_SNMP_Device.xml.gz',
        'HPE_iLO.xml.gz',
        'HPE_NimbleAlletra_storage.xml.gz',
        'Local_Linux_Machine.xml.gz',
        'MikroTik_Device.xml.gz',
        'MikroTik_Switch_SWOS.xml.gz',
        'Motorola_SB6141.xml.gz',
        'NetSNMP_Device.xml.gz',
        'PING_Advanced_Ping.xml.gz',
        'SNMP_Printer.xml.gz',
        'SNMP_UPS.xml.gz',
        'Synology_NAS.xml.gz',
        'Windows_Device.xml.gz'
    );

    $path = $config['base_path'] . '/install/templates';
    $info = array();
    $canUnpack = (extension_loaded('simplexml') && extension_loaded('zlib'));

    foreach ($templates as $xmlfile) {
        if ($canUnpack) {
            //Loading Template Information from package
            $filename = "compress.zlib://$path/$xmlfile";

            $xml    = file_get_contents($filename);
            ;
            $xmlget = simplexml_load_string($xml);
            $data   = installer_before_to_array($xmlget);

            if (is_array($data['info']['author'])) {
                $data['info']['author'] = '1';
            }

            if (is_array($data['info']['email'])) {
                $data['info']['email'] = '2';
            }

            if (is_array($data['info']['description'])) {
                $data['info']['description'] = '3';
            }

            if (is_array($data['info']['homepage'])) {
                $data['info']['homepage'] = '4';
            }

            $data['info']['filename'] = $xmlfile;
            $data['info']['name']     = $xmlfile;
            $info[] = $data['info'];
        } else {
            // Loading Template Information from package
            $myinfo = @json_decode(shell_exec(cacti_escapeshellcmd(read_config_option('path_php_binary')) . ' -q ' . cacti_escapeshellarg($config['base_path'] . '/cli/import_package.php') . ' --filename=' . cacti_escapeshellarg("/$path/$xmlfile") . ' --info'), true);
            $myinfo['filename'] = $xmlfile;
            $myinfo['name']     = $xmlfile;
            $info[] = $myinfo;
        }
    }

    return $info;
}

function installer_before_install_setup_get_tables()
{
    /* ensure all tables are utf8 enabled */
    $db_tables = get_cacti_base_tables();
    if ($db_tables === false) {
        return false;
    }

    $t = array();
    foreach ($db_tables as $table) {
        $table_status = db_fetch_row("SHOW TABLE STATUS LIKE '$table'");

        $collation  = '';
        $engine     = '';
        $rows       = 0;
        $row_format = '';

        if ($table_status !== false) {
            if (isset($table_status['Collation']) && $table_status['Collation'] != 'utf8mb4_unicode_ci') {
                $collation = $table_status['Collation'];
            }

            if (isset($table_status['Engine']) && $table_status['Engine'] == 'MyISAM') {
                $engine = $table_status['Engine'];
            }

            if (isset($table_status['Rows'])) {
                $rows = $table_status['Rows'];
            }

            if (isset($table_status['Row_format']) && $table_status['Row_format'] == 'Compact' && $table_status['Engine'] == 'InnoDB') {
                $row_format = 'Dynamic';
            }
        }

        if ($table_status === false || $collation != '' || $engine != '' || $row_format != '') {
            $t[$table]['Name']       = $table;
            $t[$table]['Collation']  = $table_status['Collation'];
            $t[$table]['Engine']     = $table_status['Engine'];
            $t[$table]['Rows']       = $rows;
            $t[$table]['Row_format'] = $table_status['Row_format'];
        }
    }

    return $t;
}

function installer_before_to_array($data)
{
    if (is_object($data)) {
        $data = get_object_vars($data);
    }
    return (is_array($data)) ? array_map(__FUNCTION__, $data) : $data;
}
