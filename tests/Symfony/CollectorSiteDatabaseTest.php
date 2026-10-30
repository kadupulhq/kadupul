<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CollectorSiteDatabase;
use Kadupul\Platform\Infrastructure\Legacy\InstallationConfiguration;
use Kadupul\Tests\Fixtures\RealMariaDb;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

require_once __DIR__ . '/Fixtures/RealMariaDb.php';

final class CollectorSiteDatabaseTest extends TestCase
{
    use RealMariaDb;

    /** @return iterable<string,array{?string,bool}> */
    public static function recoveryStates(): iterable
    {
        yield 'both queues empty' => [null, true];
        yield 'collector installation incomplete' => ['local-installing', false];
        yield 'primary installation incomplete' => ['primary-installing', false];
        yield 'ordinary samples pending' => ['poller_output', false];
        yield 'Boost samples pending' => ['poller_output_boost', false];
        yield 'ordinary queue unreadable' => ['missing:poller_output', false];
        yield 'Boost queue unreadable' => ['missing:poller_output_boost', false];
    }

    #[DataProvider('recoveryStates')]
    public function testPrimaryAdmissionMatchesBothNativeRecoveryQueues(?string $state, bool $admitted): void
    {
        $database = $this->realMariaDb();
        $schemas = [];
        $directory = sys_get_temp_dir() . '/collector-recovery-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/include', 0700, true));
        try {
            $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
            self::assertIsString($source);
            $database->executeStatement("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
            foreach (['local', 'primary'] as $role) {
                $schema = 'collector_' . $role . '_' . bin2hex(random_bytes(8));
                $database->executeStatement('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
                $schemas[$role] = $schema;
                $database->executeStatement('USE `' . $schema . '`');
                foreach (['version', 'poller_output', 'poller_output_boost'] as $table) {
                    self::assertSame(1, preg_match('/CREATE TABLE `?' . $table . '`?\s*\([^;]*;/s', $source, $match));
                    $database->executeStatement($match[0]);
                }
                $database->executeStatement("INSERT INTO version (cacti) VALUES ('1.2.38')");
            }
            $database->executeStatement('USE `' . $schemas['local'] . '`');
            if (in_array($state, ['local-installing', 'primary-installing'], true)) {
                $role = $state === 'local-installing' ? 'local' : 'primary';
                $database->executeStatement('UPDATE `' . $schemas[$role] . "`.version SET cacti='new_install'");
            } elseif ($state !== null) {
                $table = str_replace('missing:', '', $state);
                if (str_starts_with($state, 'missing:')) {
                    $database->executeStatement('DROP TABLE `' . $table . '`');
                } else {
                    $database->executeStatement('INSERT INTO `' . $table . '` (local_data_id,rrd_name,time,output) VALUES (1,\'sample\',\'2026-10-30 00:00:00\',\'42\')');
                }
            }
            $params = $database->getParams();
            $local = ['host' => $params['host'] ?? 'localhost', 'port' => $params['port'] ?? 3306, 'database' => $schemas['local'], 'username' => $params['user'] ?? '', 'password' => $params['password'] ?? '', 'ssl' => false];
            $primary = array_replace($local, ['database' => $schemas['primary']]);
            $configuration = ['poller_id' => 2, 'database_hostname' => $local['host'], 'database_port' => $local['port'], 'database_default' => $local['database'], 'database_username' => $local['username'], 'database_password' => $local['password'], 'rdatabase_hostname' => $primary['host'], 'rdatabase_port' => $primary['port'], 'rdatabase_default' => $primary['database'], 'rdatabase_username' => $primary['username'], 'rdatabase_password' => $primary['password']];
            $php = "<?php\n";
            foreach ($configuration as $key => $value) {
                $php .= '$' . $key . '=' . var_export($value, true) . ";\n";
            }
            self::assertNotFalse(file_put_contents($directory . '/include/config.php', $php));
            self::assertTrue(chmod($directory . '/include/config.php', 0600));
            foreach (['inventory_sites', 'navigation_links', 'graph_vdefs'] as $route) {
                $requests = new RequestStack();
                $request = Request::create('/');
                $request->attributes->set('_route', $route);
                $requests->push($request);
                $values = null;
                $refusal = null;
                try {
                    $values = (new InstallationConfiguration($directory, $requests))->values();
                } catch (\RuntimeException $error) {
                    $refusal = $error->getMessage();
                }
                if ($admitted) {
                    self::assertNull($refusal, 'A healthy online collector must retain primary administration.');
                    self::assertNotNull($values);
                    self::assertSame($schemas['primary'], $values['database']);
                    self::assertSame(2, $values['collector_id']);
                } else {
                    self::assertNull($values, 'Pending or unreadable queues must refuse primary routing.');
                    self::assertSame('Sites administration requires an online collector with a reachable primary.', $refusal);
                }
            }
            if ($admitted) {
                CollectorSiteDatabase::verify($local, $primary);
                self::assertTrue(true);
            }
        } finally {
            foreach ($schemas as $schema) {
                $database->executeStatement('DROP DATABASE `' . $schema . '`');
            }
            $database->close();
            if (is_file($directory . '/include/config.php')) unlink($directory . '/include/config.php');
            rmdir($directory . '/include');
            rmdir($directory);
        }
    }
}
