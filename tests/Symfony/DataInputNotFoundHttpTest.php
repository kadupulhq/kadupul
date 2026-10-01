<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\DataInput\Infrastructure\Legacy\LegacyDataInputGateway;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DataInputNotFoundHttpTest extends TestCase
{
    #[DataProvider('requests')]
    public function testNativeWorkerGatewayAndHttpControllerDistinguishMissingFromMalformed(string $url, int $expectedStatus, string $message): void
    {
        $root = dirname(__DIR__, 2);
        $directory = sys_get_temp_dir() . '/data-input-not-found-' . bin2hex(random_bytes(8));
        foreach (['', '/bin', '/include', '/lib'] as $folder) {
            mkdir($directory . $folder, 0700);
        }
        copy($root . '/bin/legacy-data-input.php', $directory . '/bin/legacy-data-input.php');
        copy($root . '/tests/Fixtures/data-input-not-found-bootstrap.php', $directory . '/include/cli_check.php');
        file_put_contents($directory . '/source.json', json_encode($root, JSON_THROW_ON_ERROR));
        foreach (['api_data_source', 'poller', 'template', 'utility'] as $library) {
            file_put_contents($directory . '/lib/' . $library . '.php', '<?php');
        }
        $worker = new \PDO('sqlite:' . $directory . '/worker.sqlite');
        $worker->exec("CREATE TABLE settings(name TEXT,value TEXT); INSERT INTO settings VALUES('auth_method','1');
            CREATE TABLE user_auth(id INTEGER,username TEXT,enabled TEXT,locked TEXT,must_change_password TEXT,password_change TEXT);
            INSERT INTO user_auth VALUES(9,'operator','on','','','');
            CREATE TABLE user_auth_realm(user_id INTEGER,realm_id INTEGER); INSERT INTO user_auth_realm VALUES(9,8),(9,2);
            CREATE TABLE data_input(id INTEGER,hash TEXT,name TEXT,type_id INTEGER,input_string TEXT);
            INSERT INTO data_input VALUES(3,'fixture-hash','Fixture',1,''),(4,'3eb92bb845b9660a7445cf9740726522','Protected',1,'');
            CREATE TABLE data_input_fields(id INTEGER,data_input_id INTEGER);
            CREATE TABLE data_template_data(local_data_id INTEGER,data_input_id INTEGER);");
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(9, 'operator');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(DataInputAccess::class);
            $access->method('authorize')->willReturn($actor);
            $container->set(DataInputAccess::class, $access);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings(name TEXT,value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?,?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $container->set(DataInputGateway::class, new LegacyDataInputGateway($database, $this->createMock(AuditTrail::class), $directory));
            $response = $kernel->handle(Request::create($url));
            self::assertSame($expectedStatus, $response->getStatusCode(), $response->getContent());
            self::assertStringContainsString($message, $response->getContent());
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame(2, (int) $worker->query('SELECT COUNT(*) FROM data_input')->fetchColumn());
        } finally {
            $kernel->shutdown();
            $worker = null;
            foreach (glob($directory . '/*/*') as $file) {
                unlink($file);
            }
            foreach (glob($directory . '/*') as $file) {
                is_dir($file) ? rmdir($file) : unlink($file);
            }
            rmdir($directory);
        }
    }
    public static function requests(): iterable
    {
        yield 'missing method' => ['/data-inputs/999/edit', 404, 'Data input not found.'];
        yield 'protected method' => ['/data-inputs/4/edit', 404, 'Data input not found.'];
        yield 'missing bulk member' => ['/data-inputs/actions/delete?ids[]=999', 404, 'Data input not found.'];
        yield 'protected bulk member' => ['/data-inputs/actions/delete?ids[]=4', 404, 'Data input not found.'];
        yield 'foreign field editor' => ['/data-inputs/3/fields/77', 404, 'Field not found.'];
        yield 'foreign field deletion' => ['/data-inputs/3/field_delete?field=77', 404, 'Field does not belong to this input.'];
        yield 'malformed field' => ['/data-inputs/3/field_delete?field[]=77', 400, 'Invalid field.'];
        yield 'malformed selection' => ['/data-inputs/actions/delete?ids[]=bad', 400, 'Invalid selection.'];
        yield 'malformed direction' => ['/data-inputs/3/fields/0?direction=bad', 400, 'Invalid field direction.'];
    }
}
