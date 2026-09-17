<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace LegacyQueueUpgradeTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/install/upgrades/1_1_6.php'), 'upgrade_to_1_1_6'));
function db_install_execute($sql)
{
    $GLOBALS['legacy_upgrade_sql'][] = $sql;
}
function db_install_add_key(...$args) {}
function db_index_exists(...$args)
{
    return true;
}

test('the pre-1.1.6 upgrade keeps the poller queue durable', function () {
    $GLOBALS['legacy_upgrade_sql'] = array();
    try {
        upgrade_to_1_1_6();
        $queue = array_values(array_filter($GLOBALS['legacy_upgrade_sql'], static function ($sql) {
            return strpos($sql, 'ALTER TABLE poller_output') !== false;
        }));
        expect($queue)->toHaveCount(1)
            ->and($queue[0])->toContain('ENGINE=InnoDB')->toContain('ROW_FORMAT=Dynamic')->toContain('MODIFY COLUMN output');
    } finally {
        unset($GLOBALS['legacy_upgrade_sql']);
    }
});


test('legacy upgrade completion verifies the final queue engine', function ($engine) {
    $root = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/legacy-installer-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $script = '<?php ';
    if ($coverage !== null) {
        $script .= 'define("RRD_TEST_INSTALLER_COVERAGE",true);define("RRD_TEST_COVERAGE_DIRECTORY",__DIR__);require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    $script .= '$root=' . var_export($root, true) . ';$engine=' . var_export($engine, true) . ';';
    $script .= <<<'INSTALLER'
$config=array('base_path'=>$root);
require $root.'/include/global_constants.php';
function __($message,...$args){return $args?vsprintf($message,$args):$message;}
function read_config_option(...$args){return '';}
function log_install_always(...$args){}
function set_install_config_option($key,$value){if ($key==='install_cache_db'){$GLOBALS['cache_file']=$value;}}
function get_cacti_cli_version(){return '1.1.5';}
function cacti_version_compare($a,$b,$op){return version_compare($a,$b,$op);}
function cacti_sizeof($value){return is_array($value)?count($value):0;}
function db_install_execute($sql){$GLOBALS['statements'][]=$sql;}
function db_install_add_key(...$args){}
function db_index_exists(...$args){return true;}
function db_execute(...$args){}
function db_fetch_cell_prepared(...$args){return $GLOBALS['engine'];}
require $root.'/lib/installer.php';
$cacti_version_codes=array('1.1.6'=>'fixture');
$reflection=new ReflectionClass('Installer');$installer=$reflection->newInstanceWithoutConstructor();
$property=$reflection->getProperty('old_cacti_version');$property->setAccessible(true);$property->setValue($installer,'1.1.5');
$method=$reflection->getMethod('upgradeDatabase');$method->setAccessible(true);
ob_start();
try { $result=$method->invoke($installer); }
finally {
    ob_end_clean();
    $cacheCreated=isset($GLOBALS['cache_file']) && is_file($GLOBALS['cache_file']);
    $cacheRemoved=$cacheCreated && unlink($GLOBALS['cache_file']);
}
echo json_encode(array($result,$statements,$cacheRemoved));
INSTALLER;
    try {
        file_put_contents($dir . '/probe.php', $script);
        $process = proc_open(array(PHP_BINARY,'-d','pcov.directory=' . $root,'-d','pcov.exclude=~/(include/vendor|tests)/~',$dir . '/probe.php'), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0)->and($err)->toBe('');
        $result = json_decode($out, true);
        expect($result[2])->toBeTrue('The installer cache file must be created and removed by the native probe.');
        if ($engine === 'InnoDB') {
            expect($result[0])->toBeFalse();
        } else {
            expect($result[0])->toContain('poller_output queue must use InnoDB');
        }
        expect(implode(';', $result[1]))->toContain('ALTER TABLE poller_output')->toContain('ENGINE=InnoDB');
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }rmdir($dir);
    }
})->with(array('InnoDB','MEMORY',false));
