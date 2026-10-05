<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseUpgradeSequenceTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, list<string>, bool}> */
    public static function outcomes(): iterable
    {
        yield 'recognized scriptless releases' => ['success', ['1.2.33'], ['1.2.31', '1.2.33'], false];
        yield 'required migration absent' => ['missing', ['1.2.31'], ['1.2.31'], true];
        yield 'required function absent' => ['missing-function', ['1.2.31'], ['1.2.31'], true];
        yield 'migration reports error' => ['migration-error', ['1.2.31'], ['1.2.31'], true];
        yield 'first marker rejected' => ['first-marker', ['1.2.28'], [], true];
        yield 'second marker rejected' => ['second-marker', ['1.2.31'], ['1.2.31'], true];
        yield 'second marker readback changed' => ['readback', ['1.2.31'], ['1.2.31'], true];
    }

    #[DataProvider('outcomes')]
    public function testWebUpgradeConfirmsEachIntermediateMarkerAndStopsAtFailure(string $outcome, array $versions, array $markers, bool $failed): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-upgrade-sequence-' . bin2hex(random_bytes(12));
        $root = dirname(__DIR__, 2);
        $collectCoverage = \PHPUnit\Runner\CodeCoverage::instance()->isActive();
        $sources = ['composer.lock', 'tests/Helpers/NativeChildCoverageEvidence.php', 'include/global_constants.php', 'lib/installer.php', 'lib/poller.php', 'lib/rrd_maintenance.php', 'lib/boost.php'];
        $producer = 'tests/Symfony/DatabaseUpgradeSequenceTest.php';
        $scenario = 'web-upgrade-' . $outcome;
        $completion = ['web-upgrade-state-readback', 'web-upgrade-loop-completed'];
        foreach (['', '/install', '/install/upgrades'] as $suffix) {
            self::assertTrue(mkdir($directory . $suffix, 0700));
        }
        try {
            foreach (['1.2.31', '1.2.33', '1.2.34'] as $version) {
                if ($outcome === 'missing' && $version === '1.2.33') {
                    continue;
                }
                $function = 'upgrade_to_' . str_replace('.', '_', $version);
                $body = '$GLOBALS["steps"][]=' . var_export($version, true) . ';';
                if ($outcome === 'migration-error' && $version === '1.2.33') {
                    $body .= '$GLOBALS["database_upgrade_status"]["1.2.33"]=array(array("status"=>DB_STATUS_ERROR,"sql"=>"ALTER TABLE fixture"));';
                }
                $source = $outcome === 'missing-function' && $version === '1.2.33'
                    ? '<?php // Deliberately omit the required migration function.'
                    : '<?php function ' . $function . '() {' . $body . '}';
                self::assertNotFalse(file_put_contents($directory . '/install/upgrades/' . str_replace('.', '_', $version) . '.php', $source));
            }
            $script = '<?php $root=' . var_export($root, true) . ';$outcome=' . var_export($outcome, true) . ';';
            if ($collectCoverage) {
                $script .= 'require $root."/include/vendor/autoload.php";require $root."/tests/Helpers/NativeChildCoverageEvidence.php";'
                    . '$snapshot=NativeChildCoverageEvidence::snapshot($root,' . var_export($producer, true) . ',' . var_export($scenario, true) . ',' . var_export($sources, true) . ');'
                    . '$filter=new SebastianBergmann\\CodeCoverage\\Filter();'
                    . '$filter->includeFile($root."/lib/installer.php");$filter->includeFile($root."/lib/rrd_maintenance.php");'
                    . '$measured=new SebastianBergmann\\CodeCoverage\\CodeCoverage((new SebastianBergmann\\CodeCoverage\\Driver\\Selector())->forLineCoverage($filter),$filter);'
                    . '$measured->start(' . var_export($scenario, true) . ');';
            }
            $script .= <<<'PHP'
define('CACTI_VERSION','1.2.34');
require $root.'/include/global_constants.php';
$config=array('base_path'=>__DIR__,'poller_id'=>2,'connection'=>'offline');
$database_hostname='sequence';$database_port=0;$database_default='owned';
$versionDatabase=new PDO('sqlite::memory:',options:array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT));
$versionDatabase->exec("CREATE TABLE version (cacti TEXT); INSERT INTO version VALUES('1.2.28'); CREATE TABLE version_log (value TEXT); CREATE TRIGGER log_version AFTER UPDATE ON version BEGIN INSERT INTO version_log VALUES(NEW.cacti); END");
$database_sessions=array('sequence:0:owned'=>$versionDatabase);
if(in_array($outcome,array('first-marker','second-marker'),true)){
    $refused=$outcome==='first-marker'?'1.2.31':'1.2.33';
    $versionDatabase->exec("CREATE TRIGGER refuse_marker BEFORE UPDATE ON version WHEN NEW.cacti='$refused' BEGIN SELECT RAISE(ABORT,'marker refused'); END");
}elseif($outcome==='readback'){
    $versionDatabase->exec("CREATE TRIGGER replace_marker AFTER UPDATE ON version WHEN NEW.cacti='1.2.33' BEGIN UPDATE version SET cacti='unconfirmed'; END");
}
function __($message,...$args){return $args?vsprintf($message,$args):$message;}
function read_config_option(...$args){return '';}
function log_install_always($section,$message){$GLOBALS['messages'][]=$message;}
function log_install_debug($section,$message){$GLOBALS['messages'][]=$message;}
function log_install_medium($section,$message){$GLOBALS['messages'][]=$message;}
function set_install_config_option($key,$value){if($key==='install_cache_db'){$GLOBALS['owned_cache']=$value;}}
function get_cacti_cli_version(){return $GLOBALS['versionDatabase']->query('SELECT cacti FROM version')->fetchColumn();}
function cacti_version_compare($left,$right,$operator){return version_compare($left,$right,$operator);}
function cacti_sizeof($value){return is_array($value)?count($value):0;}
function clean_up_lines($value){return $value;}
function db_fetch_cell_prepared(...$args){return 'InnoDB';}
function db_execute($sql){return $GLOBALS['versionDatabase']->exec($sql)!==false;}
require $root.'/lib/installer.php';
$cacti_version_codes=array_fill_keys(array('1.2.28','1.2.29','1.2.30','1.2.31','1.2.32','1.2.33','1.2.34'),'fixture');
$reflection=new ReflectionClass('Installer');
$installer=$reflection->newInstanceWithoutConstructor();
$reflection->getProperty('old_cacti_version')->setValue($installer,'1.2.28');
ob_start();
try{
    $result=$reflection->getMethod('upgradeDatabase')->invoke($installer);
}finally{
    ob_end_clean();
    if(isset($GLOBALS['owned_cache'])&&is_file($GLOBALS['owned_cache'])){unlink($GLOBALS['owned_cache']);}
}
echo json_encode(array('result'=>$result,'versions'=>$versionDatabase->query('SELECT cacti FROM version')->fetchAll(PDO::FETCH_COLUMN),'markers'=>$versionDatabase->query('SELECT value FROM version_log ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN),'transaction'=>$versionDatabase->inTransaction(),'steps'=>$GLOBALS['steps']??array(),'messages'=>$GLOBALS['messages']??array()),JSON_THROW_ON_ERROR);
PHP;
            if ($collectCoverage) {
                $script .= '$measured->stop();$bytes=serialize($measured);'
                    . 'if(file_put_contents(__DIR__."/native.coverage",$bytes)!==strlen($bytes)){throw new RuntimeException("Incomplete upgrade coverage report");}'
                    . 'NativeChildCoverageEvidence::write(__DIR__."/native.coverage",$root,$snapshot,' . var_export($completion, true) . ');';
            }
            self::assertNotFalse(file_put_contents($directory . '/probe.php', $script));
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'pcov.directory=' . $root, $directory . '/probe.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errors . (string) $output);
            self::assertSame('', $errors);
            $state = json_decode((string) $output, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($versions, $state['versions']);
            self::assertSame($markers, $state['markers']);
            self::assertFalse($state['transaction']);
            if ($failed) {
                self::assertSame('WARNING: One or more upgrades failed to install correctly', $state['result']);
                self::assertNotContains('1.2.34', $state['steps']);
                self::assertStringContainsString($outcome === 'migration-error' ? 'FAIL: ALTER TABLE fixture' : 'ERROR:', implode("\n", $state['messages']));
            } else {
                self::assertFalse($state['result']);
                self::assertSame(['1.2.31', '1.2.33', '1.2.34'], $state['steps']);
            }
            if ($collectCoverage) {
                require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
                $arguments = [$directory . '/native.coverage', $root, $producer, $scenario, $sources, $completion, ['lib/installer.php']];
                $measured = \NativeChildCoverageEvidence::load(...$arguments);
                self::assertSame(count($sources) + count($completion) + 10, \NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/boost.php']));
                \PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->merge($measured);
            }
        } finally {
            foreach ([$directory . '/native.coverage', $directory . '/native.coverage.json'] as $report) {
                if (is_file($report)) {
                    unlink($report);
                }
            }
            foreach (glob($directory . '/install/upgrades/*') ?: [] as $path) {
                unlink($path);
            }
            if (is_file($directory . '/probe.php')) {
                unlink($directory . '/probe.php');
            }
            rmdir($directory . '/install/upgrades');
            rmdir($directory . '/install');
            rmdir($directory);
        }
    }
}
