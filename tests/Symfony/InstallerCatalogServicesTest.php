<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\InstallerTableCatalog;
use Kadupul\Platform\Infrastructure\Legacy\InstallerTemplateCatalog;
use Kadupul\Platform\Infrastructure\Legacy\InstallerToolPaths;
use Kadupul\Platform\Infrastructure\Legacy\NativeInstallerFunctions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** Native compatibility and failure contracts for the extracted installation catalogs. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InstallerCatalogServicesTest extends TestCase
{
    /** @var array<string, string> Saved options retain explicit zero and missing distinctions. */
    public static array $options = [];
    /** @var mixed Native base-table selection, including injected malformed reads. */
    public static mixed $tables = [];
    /** @var array<string, mixed>|false Native table metadata or a read failure. */
    public static array|false $tableRow = [];
    /** The exact disposable connection used by native table reads. */
    public static ?\PDO $database = null;
    /** Owned filesystem fixture root. */
    private string $directory;

    /** Define native hooks only in the isolated test process. */
    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        require $root . '/include/global_constants.php';
        require $root . '/install/functions.php';
        require $root . '/tests/Fixtures/installer-catalog-before.php';
        $this->directory = sys_get_temp_dir() . '/installer-catalog-' . bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
        $GLOBALS['config'] = ['base_path' => $this->directory, 'cacti_server_os' => 'unix'];
        require $root . '/tests/Fixtures/installer-catalog-hooks.php';
    }

    /** Remove only the fixture tree created by this test. */
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    /** Real bundled metadata must match the former parser byte-for-byte at the array boundary. */
    public function testBundledPackagesMatchTheFrozenNativeCatalog(): void
    {
        $GLOBALS['config'] = ['base_path' => dirname(__DIR__, 2), 'is_web' => false];
        $expected = \installer_before_install_setup_get_templates();
        $catalog = new InstallerTemplateCatalog(dirname(__DIR__, 2));
        $this->assertCount(30, $expected);
        $this->assertSame($expected, $catalog->templates());
        $this->assertSame($expected, \install_setup_get_templates());
        $this->assertSame(['x' => ['name' => 'value']], InstallerTemplateCatalog::toArray((object) ['x' => (object) ['name' => 'value']]));
        $this->assertSame('scalar', \to_array('scalar'));
    }

    /** Missing and malformed release files fail without publishing a partial successful catalog. */
    public function testBrokenPackageMetadataRestoresThePreviousXmlHandler(): void
    {
        mkdir($this->directory . '/install/templates', 0700, true);
        $catalog = new InstallerTemplateCatalog($this->directory);
        foreach ([null, '<invalid', '<package/>', '<package><info><author/><email/><description/><homepage/></info></package>'] as $xml) {
            if ($xml !== null) {
                file_put_contents($this->directory . '/install/templates/ACME.xml.gz', gzencode($xml));
            }
            $previous = libxml_use_internal_errors(false);
            try {
                $catalog->templates(web: true);
                $this->fail('Incomplete release catalog was accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('installation package', $error->getMessage());
            } finally {
                $this->assertFalse(libxml_use_internal_errors());
                libxml_use_internal_errors($previous);
            }
        }
    }

    /** The fallback runs the fixed argv command, checks exit status and bounds both pipes. */
    public function testPackageInfoWorkerUsesLiteralArgumentsAndRejectsFailures(): void
    {
        mkdir($this->directory . '/cli', 0700);
        $script = $this->directory . '/cli/import_package.php';
        $catalog = new InstallerTemplateCatalog($this->directory);
        $worker = new \ReflectionMethod($catalog, 'worker');
        $file = $this->directory . '/literal $(touch INJECTED); package.xml.gz';
        file_put_contents($script, '<?php print json_encode(["argument" => $argv[1], "mode" => $argv[2]]);');
        $this->assertSame(['argument' => '--filename=' . $file, 'mode' => '--info'], $worker->invoke($catalog, $file, PHP_BINARY));
        $this->assertFileDoesNotExist($this->directory . '/INJECTED');
        foreach ([null, 'php', '/missing/php', "bad\0php"] as $php) {
            try {
                $worker->invoke($catalog, $file, $php);
                $this->fail('Invalid interpreter accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('PHP executable', $error->getMessage());
            }
        }
        foreach (['exit(7);', 'fwrite(STDERR,"failed");print "{}";', 'print "false";', 'print "[1]";', 'print "invalid";', 'print str_repeat("x",1048577);', 'fwrite(STDERR,str_repeat("x",65537));'] as $program) {
            file_put_contents($script, '<?php ' . $program);
            try {
                $worker->invoke($catalog, $file, PHP_BINARY);
                $this->fail('Unconfirmed worker response accepted.');
            } catch (\RuntimeException|\JsonException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    /** Path precedence, key retention and field metadata match the frozen implementation. */
    public function testToolSelectionMatchesNativeDefaultsAndExplicitOverrides(): void
    {
        $GLOBALS['config'] = ['base_path' => $this->directory, 'cacti_server_os' => 'unix', 'php_path' => $this->directory];
        $GLOBALS['settings'] = ['path' => [
            'path_spine_config' => ['default' => ''], 'path_cactilog' => ['default' => ''], 'path_stderrlog' => ['default' => ''],
            'path_snmpget' => ['default' => 'metadata-default', 'install_optional' => true],
        ], 'general' => ['rrdtool_version' => ['default' => 'metadata-version']]];
        $paths = new InstallerToolPaths(new NativeInstallerFunctions());
        $beforePath = getenv('PATH');
        $beforeBindir = getenv('PHP_BINDIR');
        try {
            putenv('PATH=' . $this->directory . '::' . $this->directory);
            putenv('PHP_BINDIR=' . $this->directory);
            file_put_contents($this->directory . '/rrdtool', 'owned executable fixture');
            chmod($this->directory . '/rrdtool', 0700);
            foreach ([[], ['path_rrdtool' => $this->directory . '/rrdtool'], ['path_php_binary' => '0', 'path_cactilog' => 'saved.log', 'path_stderrlog' => '']] as $options) {
                self::$options = $options;
                $this->assertSame(\installer_before_install_file_paths(), $paths->definitions($GLOBALS['config'], $GLOBALS['settings']));
                $this->assertSame(\installer_before_install_file_paths(), \install_file_paths());
            }
            foreach (['unix', 'win32'] as $os) {
                putenv('PATH=first\\path;second\\path');
                $this->assertSame(\installer_before_find_search_paths($os), $paths->searchPaths($os, $this->directory));
            }
            $this->assertSame(false, $paths->tool('sendmail', ['unix' => '/mail'], ['cacti_server_os' => 'win32'], []));
            $this->assertSame('', $paths->bestPath('definitely-not-installed', 'unix'));
        } finally {
            putenv($beforePath === false ? 'PATH' : 'PATH=' . $beforePath);
            putenv($beforeBindir === false ? 'PHP_BINDIR' : 'PHP_BINDIR=' . $beforeBindir);
        }
    }

    /** Windows and BSD overrides take precedence even over a saved binary path. */
    public function testPlatformOverridesUseTheInjectedFilesystem(): void
    {
        $files = $this->createMock(Filesystem::class);
        $files->method('exists')->willReturnCallback(static fn(string $path): bool => in_array($path, ['d:/xampp/php/php.exe', '/usr/local/bin/spine'], true));
        $paths = new InstallerToolPaths(new NativeInstallerFunctions(), $files);
        $settings = ['path' => ['path_spine_config' => ['default' => ''], 'path_cactilog' => ['default' => ''], 'path_stderrlog' => ['default' => '']]];
        $windows = $paths->definitions(['base_path' => $this->directory, 'cacti_server_os' => 'win32'], $settings);
        $this->assertSame('d:/xampp/php/php.exe', $windows['path_php_binary']['default']);
        $this->assertArrayNotHasKey('settings_sendmail_path', $windows);
        $unix = $paths->definitions(['base_path' => $this->directory, 'cacti_server_os' => 'unix'], $settings);
        $this->assertSame('/usr/local/bin/spine', $unix['path_spine']['default']);
    }

    /** Metadata read failure remains distinct from a successful empty conversion list. */
    public function testTableCatalogRejectsFailedReadsAndPreservesConversionReasons(): void
    {
        $catalog = new InstallerTableCatalog(new NativeInstallerFunctions());
        self::$tables = false;
        $this->assertFalse($catalog->tables());
        self::$tables = [];
        $this->assertSame([], $catalog->tables());
        foreach (['not-a-list', [42]] as $malformed) {
            self::$tables = $malformed;
            try {
                $catalog->tables();
                $this->fail('Malformed native table catalog accepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('installation table', $error->getMessage());
            }
        }

        self::$tables = ['host'];
        foreach ([false, []] as $row) {
            self::$tableRow = $row;
            try {
                $catalog->tables();
                $this->fail('Failed read became a conversion plan.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('could not be read', $error->getMessage());
            }
        }
        foreach ([['utf8mb4_unicode_ci', 'InnoDB', 'Dynamic', false], ['latin1_swedish_ci', 'InnoDB', 'Dynamic', true],
            ['utf8mb4_unicode_ci', 'MyISAM', 'Fixed', true], ['utf8mb4_unicode_ci', 'InnoDB', 'Compact', true]] as [$collation, $engine, $format, $needs]) {
            self::$tableRow = ['Collation' => $collation, 'Engine' => $engine, 'Row_format' => $format, 'Rows' => 0];
            $result = $catalog->tables();
            $this->assertSame($needs, isset($result['host']));
            if ($needs) {
                $this->assertSame(['Name' => 'host', 'Collation' => $collation, 'Engine' => $engine, 'Rows' => 0, 'Row_format' => $format], $result['host']);
            }
            $this->assertSame($result, \install_setup_get_tables());
        }
    }

    /** Native SHOW metadata selects an exact identity, not a LIKE wildcard match. */
    public function testTableCatalogUsesTheActualSelectedDatabaseAndExactTableNames(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
            $this->markTestSkipped('An explicitly configured disposable MySQL/MariaDB server is required.');
        }
        $database = new \PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_EMULATE_PREPARES => false]);
        $schema = 'installer_catalog_' . bin2hex(random_bytes(10));
        $database->exec('CREATE DATABASE ' . $schema);
        try {
            $database->exec('USE ' . $schema);
            $database->exec('CREATE TABLE exact_name (id INT) ENGINE=InnoDB ROW_FORMAT=DYNAMIC COLLATE=utf8mb4_unicode_ci');
            $database->exec('CREATE TABLE exactXname (id INT) ENGINE=MyISAM COLLATE=latin1_swedish_ci');
            self::$database = $database;
            self::$tables = ['exact_name', 'exactXname'];
            $rows = (new InstallerTableCatalog(new NativeInstallerFunctions()))->tables();
            $this->assertSame(['exactXname'], array_keys($rows));
            $this->assertSame('MyISAM', $rows['exactXname']['Engine']);
            $this->assertSame('latin1_swedish_ci', $rows['exactXname']['Collation']);
            $this->assertFalse($database->inTransaction());
        } finally {
            $database->exec('DROP DATABASE ' . $schema);
            self::$database = null;
        }
    }
}
