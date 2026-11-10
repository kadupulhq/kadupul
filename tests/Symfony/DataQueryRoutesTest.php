<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\DataInputConflict;
use Kadupul\DataInput\Application\DataInputDenied;
use Kadupul\DataInput\Application\Port\DataQueryAccess;
use Kadupul\DataInput\Application\Port\DataQueryGateway;
use Kadupul\DataInput\Domain\DataInputNotFound;
use Kadupul\DataInput\Domain\DataQueryRejected;
use Kadupul\DataInput\Domain\DataQuerySettings;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Kernel;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DataQueryRoutesTest extends TestCase
{
    private const string REVISION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const array FIELDS = ['name' => 'Interfaces <b>', 'description' => 'Stored <i>', 'xml_path' => '<path_cacti>/resource/snmp_queries/interface.xml',
        'data_input_id' => '2', 'revision' => self::REVISION, '_token' => 'csrf-token'];

    private Kernel $kernel;
    private ?Actor $actor;
    private bool $denied = false;
    private int $reads = 0;
    private ?\Throwable $failure = null;
    private bool $partial = false;
    /** @var array<string, mixed> */
    private array $configuration = ['collector_id' => 1];
    /** @var list<array{int, int, DataQuerySettings, string}> */
    private array $writes = [];

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $container = $this->kernel->getContainer()->get('test.service_container');
        $this->actor = new Actor(42, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturnCallback(fn(): ?Actor => $this->actor);
        $container->set(ConsoleAccess::class, $console);
        $access = $this->createMock(DataQueryAccess::class);
        $access->method('authorize')->willReturnCallback(fn(): Actor => $this->denied ? throw new DataInputDenied() : $this->actor);
        $container->set(DataQueryAccess::class, $access);

        $gateway = $this->createMock(DataQueryGateway::class);
        $gateway->method('find')->willReturnCallback(function (int $actor, int $id): array {
            $this->reads++;
            $inputs = [2 => 'Get SNMP Data (Indexed)', 5 => 'Script <u>'];
            if ($id === 0) {
                return ['query' => null, 'revision' => '', 'xml' => 'none', 'inputs' => $inputs];
            }
            if ($id !== 7) {
                throw new DataInputNotFound('Data query not found.');
            }
            return ['query' => ['id' => 7, 'name' => 'Interfaces <b>', 'description' => 'Stored <i>', 'xml_path' => '<path_cacti>/resource/snmp_queries/interface.xml', 'data_input_id' => 2],
                'revision' => self::REVISION, 'xml' => 'missing', 'inputs' => $inputs];
        });
        $gateway->method('save')->willReturnCallback(function (int $actor, int $id, DataQuerySettings $settings, string $revision): array {
            if ($this->failure !== null) {
                throw $this->failure;
            }
            $this->writes[] = [$actor, $id, $settings, $revision];
            return ['id' => $id > 0 ? $id : 8, 'partial' => $this->partial];
        });
        $container->set(DataQueryGateway::class, $gateway);
        $configuration = $this->createMock(LegacyConfiguration::class);
        $configuration->method('values')->willReturnCallback(fn(): array => $this->configuration);
        $container->set(LegacyConfiguration::class, $configuration);
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public function testAnonymousAndUnauthorizedRequestsStopBeforeTheWorker(): void
    {
        foreach ([[null, false, 401], [new Actor(42, 'operator'), true, 403]] as [$actor, $denied, $status]) {
            $this->actor = $actor;
            $this->denied = $denied;
            foreach (['/data-collection/queries/new', '/data-collection/queries/7/edit'] as $path) {
                self::assertSame($status, $this->get($path)->getStatusCode(), $path);
                self::assertSame($status, $this->post($path, self::FIELDS)->getStatusCode(), $path);
            }
        }
        self::assertSame(0, $this->reads);
        self::assertSame([], $this->writes);
    }

    public function testEditorEscapesStoredTextAndLinksTheAssociations(): void
    {
        $response = $this->get('/data-collection/queries/7/edit');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $page = (string) $response->getContent();
        foreach (['Data Queries [edit: Interfaces &lt;b&gt;]', 'value="Stored &lt;i&gt;"', 'Script &lt;u&gt;', 'Could not locate XML file.',
            'data_queries.php?action=edit&amp;id=7', 'value="&lt;path_cacti&gt;/resource/snmp_queries/interface.xml"'] as $text) {
            self::assertStringContainsString($text, $page);
        }
        self::assertStringNotContainsString('Interfaces <b>', $page);
        self::assertMatchesRegularExpression('/<option value="2" selected="selected">Get SNMP Data \(Indexed\)<\/option>/', $page);
        foreach (['/data-collection/queries/9/edit', '/data-collection/queries/0/edit', '/data-collection/queries/99999999/edit', '/data-collection/queries/999999999/edit'] as $missing) {
            self::assertSame(404, $this->get($missing)->getStatusCode(), $missing);
        }
        $new = (string) $this->get('/data-collection/queries/new')->getContent();
        self::assertStringContainsString('Data Queries [new]', $new);
        // Legacy offered the resource directory as the starting XML path.
        self::assertStringContainsString('value="&lt;path_cacti&gt;/resource/"', $new);
        self::assertStringNotContainsString('data_queries.php?action=edit', $new);
        self::assertSame(200, $this->get('/data-collection/queries/7/edit?saved[]=1')->getStatusCode());
        self::assertStringContainsString('Data query saved.', (string) $this->get('/data-collection/queries/7/edit?saved=1')->getContent());
        self::assertStringContainsString('Perform a Full Sync', (string) $this->get('/data-collection/queries/7/edit?saved=partial')->getContent());
        self::assertSame('', (string) $this->kernel->handle(Request::create('/data-collection/queries/7/edit', 'HEAD'))->getContent());
    }

    public function testInvalidSubmissionsAreRefusedBeforeTheWorker(): void
    {
        $cases = [
            'missing token' => array_diff_key(self::FIELDS, ['_token' => true]),
            'extra field' => ['extra' => '1'] + self::FIELDS,
            'empty name' => ['name' => ' '] + self::FIELDS,
            'multi-line name' => ['name' => "a\nb"] + self::FIELDS,
            'name too long' => ['name' => str_repeat('é', 101)] + self::FIELDS,
            'description too long' => ['description' => str_repeat('d', 256)] + self::FIELDS,
            'empty XML path' => ['xml_path' => ''] + self::FIELDS,
            'unlisted Data Input Method' => ['data_input_id' => '3<script>'] + self::FIELDS,
            'no Data Input Method' => ['data_input_id' => ''] + self::FIELDS,
            'array where a scalar is expected' => ['xml_path' => ['x']] + self::FIELDS,
        ];
        foreach ($cases as $name => $fields) {
            $response = $this->post('/data-collection/queries/7/edit', $fields);
            self::assertSame(422, $response->getStatusCode(), $name);
            self::assertStringNotContainsString('<script>', (string) $response->getContent(), $name);
        }
        self::assertSame([], $this->writes);
    }

    public function testWorkerOutcomesMapToResponses(): void
    {
        $this->failure = new DataInputConflict('The data query changed since you opened this form. Reload before saving.');
        self::assertSame(409, $this->post('/data-collection/queries/7/edit', self::FIELDS)->getStatusCode());
        // The worker refuses an XML file outside the resource tree on its field.
        $this->failure = new DataQueryRejected('Enter the path of a readable XML file under the resource directory.', [], 'xml_path');
        $rejected = $this->post('/data-collection/queries/7/edit', ['xml_path' => '/etc/passwd'] + self::FIELDS);
        self::assertSame(422, $rejected->getStatusCode());
        self::assertStringContainsString('Enter the path of a readable XML file under the resource directory.', (string) $rejected->getContent());
        $this->failure = new DataInputNotFound('Data query not found.');
        self::assertSame(404, $this->post('/data-collection/queries/7/edit', self::FIELDS)->getStatusCode());
        $this->failure = new DataInputDenied();
        self::assertSame(403, $this->post('/data-collection/queries/7/edit', self::FIELDS)->getStatusCode());
        $this->failure = new \RuntimeException('Operation outcome is unknown. Reload before retrying.');
        $unknown = $this->post('/data-collection/queries/7/edit', self::FIELDS);
        self::assertSame(502, $unknown->getStatusCode());
        self::assertStringContainsString('Save outcome is uncertain.', (string) $unknown->getContent());
        self::assertSame([], $this->writes);
    }

    public function testValidSubmissionsSaveThroughTheWorker(): void
    {
        $saved = $this->post('/data-collection/queries/7/edit', ['name' => ' Interfaces ', 'data_input_id' => '5'] + self::FIELDS);
        self::assertSame(303, $saved->getStatusCode());
        self::assertSame('/data-collection/queries/7/edit?saved=1', $saved->headers->get('Location'));
        [$actor, $id, $settings, $revision] = $this->writes[0];
        self::assertSame([42, 7, self::REVISION], [$actor, $id, $revision]);
        self::assertSame(['name' => 'Interfaces', 'description' => 'Stored <i>', 'xml_path' => '<path_cacti>/resource/snmp_queries/interface.xml', 'data_input_id' => 5], $settings->columns());

        $this->partial = true;
        $created = $this->post('/data-collection/queries/new', ['revision' => ''] + self::FIELDS);
        self::assertSame('/data-collection/queries/8/edit?saved=partial', $created->headers->get('Location'));
        self::assertSame([0, ''], [$this->writes[1][1], $this->writes[1][3]]);
    }

    public function testTheEditorRendersInTheConfiguredLocale(): void
    {
        // The locale subscriber selects the forced installation locale for a signed-in browser, as production does.
        $this->configuration['forced_locale'] = 'fr-FR';
        $container = $this->kernel->getContainer()->get('test.service_container');
        $pdo = new \PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE settings(name TEXT, value TEXT)');
        $database = $this->createMock(DatabaseConnection::class);
        $database->method('get')->willReturn($pdo);
        $container->set(DatabaseConnection::class, $database);
        $container->set(LocalePreference::class, $this->createMock(LocalePreference::class));
        $page = (string) $this->kernel->handle(Request::create('/data-collection/queries/7/edit', 'GET', [], ['Cacti' => 'fixture']))->getContent();
        self::assertStringContainsString('<html lang="fr">', $page);
        foreach (['Requêtes de données [modifier : Interfaces &lt;b&gt;]', 'Méthode d’acquisition des données', 'Chemin XML', 'Impossible de trouver le fichier XML.'] as $text) {
            self::assertStringContainsString($text, $page);
        }
    }

    private function get(string $uri): Response
    {
        return $this->kernel->handle(Request::create($uri));
    }

    /** @param array<string, mixed> $fields */
    private function post(string $uri, array $fields): Response
    {
        return $this->kernel->handle(Request::create($uri, 'POST', ['data_query' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost']));
    }
}
