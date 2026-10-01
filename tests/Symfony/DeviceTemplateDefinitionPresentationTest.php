<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceTemplateDefinitions;
use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class DeviceTemplateDefinitionPresentationTest extends TestCase
{
    public function testAnonymousRequestsCannotReachFeatureAccessOrParseMalformedFields(): void
    {
        foreach (['/inventory/device-templates?q[x]=1', '/inventory/device-templates/new', '/inventory/device-templates/7/edit', '/inventory/device-templates/action/delete?ids[x]=bad', '/inventory/device-templates/7/association/graph/add', '/inventory/device-templates/legacy?action[x]=1'] as $path) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(null);
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMock(DeviceTemplateDefinitions::class);
                $port->expects(self::never())->method('authorize');
                $port->expects(self::never())->method('find');
                $container->set(DeviceTemplateDefinitions::class, $port);
                self::assertSame(401, $kernel->handle(Request::create($path))->getStatusCode());
            } finally {
                $kernel->shutdown();
            }
        }
    }
    public function testConsoleOnlyActorIsRejectedBeforeSelectionParsing(): void
    {
        foreach (['/inventory/device-templates?q[x]=1', '/inventory/device-templates/new', '/inventory/device-templates/7/edit', '/inventory/device-templates/action/delete?ids[x]=bad', '/inventory/device-templates/7/association/graph/add', '/inventory/device-templates/legacy?action[x]=1'] as $path) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(new Actor(42, 'console-only'));
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMock(DeviceTemplateDefinitions::class);
                $port->expects(self::once())->method('authorize')->with(42)->willThrowException(new \Kadupul\Inventory\Application\Query\InventoryAccessDenied(false));
                foreach (['find', 'execute', 'defaults', 'remember', 'list', 'graphChoices', 'choices', 'hooks'] as $method) {
                    $port->expects(self::never())->method($method);
                }
                $container->set(DeviceTemplateDefinitions::class, $port);
                self::assertSame(403, $kernel->handle(Request::create($path))->getStatusCode(), $path);
            } finally {
                $kernel->shutdown();
            }
        }
    }
    public function testLegacyDeviceLinksUseInstallationPathThroughAllFrontControllers(): void
    {
        foreach ([['/app.php', '/'], ['/public/index.php', '/'], ['/cacti/app.php', '/cacti/'], ['/cacti/public/index.php', '/cacti/'], ['/app.php', '//evil.invalid/'], ['/app.php', 'relative/'], ['/app.php', '/x?y/'], ['/app.php', '/x#y/'], ['/app.php', "/x\n/"], ['/app.php', '/x\\y/']] as [$front, $prefix]) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $configuration = $this->createMock(LegacyConfiguration::class);
                $configuration->method('values')->willReturn(['url_path' => $prefix, 'forced_locale' => 'en-US']);
                $container->set(LegacyConfiguration::class, $configuration);
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMock(DeviceTemplateDefinitions::class);
                $port->method('defaults')->willReturn([]);
                $port->method('list')->willReturn(['rows' => [['id' => 7, 'name' => 'attached', 'class' => 'router', 'hosts' => 2]], 'hasNext' => false]);
                $port->expects(self::never())->method('choices');
                $port->expects(self::once())->method('graphChoices')->willReturn([]);
                $port->method('hooks')->willReturn([]);
                $container->set(DeviceTemplateDefinitions::class, $port);
                $response = $kernel->handle(Request::create($front . '/inventory/device-templates', 'GET', [], [], [], ['SCRIPT_FILENAME' => '/var/www/html' . $front, 'SCRIPT_NAME' => $front, 'PHP_SELF' => $front . '/inventory/device-templates']));
                if (!in_array($prefix, ['/', '/cacti/'], true)) {
                    self::assertSame(502, $response->getStatusCode(), $prefix);
                    self::assertStringNotContainsString('evil.invalid', $response->getContent());
                    continue;
                }
                self::assertSame(200, $response->getStatusCode(), $front);
                self::assertStringContainsString('href="' . $prefix . 'host.php?reset=true&amp;host_template_id=7"', $response->getContent());
                self::assertStringContainsString('<td>Yes</td>', $response->getContent());
                self::assertStringNotContainsString('/public/host.php', $response->getContent());
            } finally {
                $kernel->shutdown();
            }
        }
    }
    public function testUnexpectedNestedFieldsNeverReachMutation(): void
    {
        foreach ([['/inventory/device-templates/new', 'device_template_definition'], ['/inventory/device-templates/7/edit', 'device_template_definition'], ['/inventory/device-templates/action/delete?ids[]=7', 'device_template_action'], ['/inventory/device-templates/7/association/graph/add', 'device_template_association']] as [$path, $formName]) {
            $kernel = new Kernel('test', true);
            try {
                $kernel->boot();
                $container = $kernel->getContainer()->get('test.service_container');
                $configuration = $this->createMock(LegacyConfiguration::class);
                $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
                $container->set(LegacyConfiguration::class, $configuration);
                $pdo = new \PDO('sqlite::memory:');
                $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
                $database = $this->createMock(DatabaseConnection::class);
                $database->method('get')->willReturn($pdo);
                $container->set(DatabaseConnection::class, $database);
                $access = $this->createMock(ConsoleAccess::class);
                $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
                $container->set(ConsoleAccess::class, $access);
                $port = $this->createMock(DeviceTemplateDefinitions::class);
                $port->method('find')->willReturn(new DeviceTemplateDefinition(7, 'existing', 'router'));
                $port->method('choices')->willReturn(['graphs' => [2 => 'graph'], 'add_graphs' => [2 => 'graph'], 'queries' => []]);
                $port->method('hooks')->willReturn([]);
                $port->expects(self::never())->method('execute');
                $container->set(DeviceTemplateDefinitions::class, $port);
                $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
                self::assertSame(200, $response->getStatusCode(), $path);
                $document = new \DOMDocument();
                @$document->loadHTML($response->getContent());
                $fields = [];
                foreach ((new \DOMXPath($document))->query('//input[@name]') as $input) {
                    if (preg_match('/^' . $formName . '\[([^]]+)\]$/D', $input->getAttribute('name'), $match)) {
                        $fields[$match[1]] = $input->getAttribute('value');
                    }
                }
                if ($formName === 'device_template_definition') {
                    $fields['name'] = 'changed';
                    $fields['class'] = 'router';
                } elseif ($formName === 'device_template_association') {
                    $fields['child'] = '2';
                }
                $fields['unexpected'] = ['id' => 999];
                $request = Request::create(strtok($path, '?'), 'POST', [$formName => $fields], ['Cacti' => 'fixture']);
                $request->headers->set('Origin', 'http://localhost');
                $rejected = $kernel->handle($request);
                self::assertSame(422, $rejected->getStatusCode(), $path);
                self::assertStringContainsString($formName === 'device_template_definition' ? 'Champs du modèle d’appareil invalides.' : 'Sélection de modèles d’appareils invalide.', $rejected->getContent());
            } finally {
                $kernel->shutdown();
            }
        }
    }
    public function testFrenchEditorEscapesStoredNamesAndPreservesTrustedInstalledHookMarkup(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $configuration = $this->createMock(LegacyConfiguration::class);
            $configuration->method('values')->willReturn(['forced_locale' => 'fr-FR']);
            $container->set(LegacyConfiguration::class, $configuration);
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $container->set(DatabaseConnection::class, $database);
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $access);
            $row = new DeviceTemplateDefinition(7, '<script>stored</script>', 'router', [2,3], [4]);
            $port = $this->createMock(DeviceTemplateDefinitions::class);
            $port->method('find')->willReturn($row);
            $port->method('defaults')->willReturn([]);
            $port->method('list')->willReturn(['rows' => [['id' => 7, 'name' => 'attached', 'class' => 'router', 'hosts' => 2]], 'hasNext' => false]);
            $port->method('choices')->willReturn(['graphs' => [2 => '<same>', 3 => '<same>'], 'queries' => [4 => '<query>']]);
            $port->method('hooks')->willReturn(['device_template_top' => '<aside id="plugin-top">Installed plugin</aside>', 'device_template_edit' => '<label for="plugin-control">Plugin field</label><input id="plugin-control" name="plugin_field">']);
            $port->expects(self::once())->method('execute')->with(42, 'save', ['id' => 7, 'revision' => $row->revision(), 'data' => ['name' => 'changed', 'class' => 'router']])->willReturn(['ids' => [7], 'status' => 'ok']);
            $container->set(DeviceTemplateDefinitions::class, $port);
            $path = '/inventory/device-templates/7/edit';
            $response = $kernel->handle(Request::create($path, 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $response->getStatusCode());
            $body = $response->getContent();
            self::assertStringContainsString('Modèle d’appareil', $body);
            self::assertStringContainsString('<button>Enregistrer</button>', $body);
            self::assertStringContainsString('maxlength="100"', $body);
            self::assertStringContainsString('&lt;script&gt;stored&lt;/script&gt;', $body);
            self::assertStringNotContainsString('<script>stored</script>', $body);
            self::assertStringContainsString('<aside id="plugin-top">', $body);
            self::assertStringContainsString('<input id="plugin-control"', $body);
            $document = new \DOMDocument();
            @$document->loadHTML($body);
            $xpath = new \DOMXPath($document);
            $token = $xpath->evaluate('string(//input[@name="device_template_definition[_token]"]/@value)');
            $fields = ['name' => 'changed', 'class' => 'router', 'revision' => $row->revision(), '_token' => $token];
            foreach (['http://evil.invalid', null] as $origin) {
                $request = Request::create($path, 'POST', ['device_template_definition' => $fields]);
                if ($origin !== null) {
                    $request->headers->set('Origin', $origin);
                }
                self::assertSame(422, $kernel->handle($request)->getStatusCode());
            }
            foreach ([str_repeat('x', 101), str_repeat('é', 101)] as $oversized) {
                $invalid = Request::create($path, 'POST', ['device_template_definition' => ['name' => $oversized] + $fields]);
                $invalid->headers->set('Origin', 'http://localhost');
                self::assertSame(422, $kernel->handle($invalid)->getStatusCode());
            }
            $request = Request::create($path, 'POST', ['device_template_definition' => $fields]);
            $request->headers->set('Origin', 'http://localhost');
            self::assertSame(303, $kernel->handle($request)->getStatusCode());
            $association = $kernel->handle(Request::create('/inventory/device-templates/7/association/graph/remove', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $association->getStatusCode());
            self::assertStringContainsString('<button>Continuer</button>', $association->getContent());
            self::assertStringContainsString('value="2">&lt;same&gt;', $association->getContent());
            self::assertStringContainsString('value="3">&lt;same&gt;', $association->getContent());
            $listing = $kernel->handle(Request::create('/inventory/device-templates', 'GET', [], ['Cacti' => 'fixture']));
            self::assertSame(200, $listing->getStatusCode());
            self::assertStringContainsString('<td>Oui</td>', $listing->getContent());
            self::assertSame(409, $kernel->handle(Request::create('/inventory/device-templates/legacy', 'POST', ['action' => 'actions']))->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
