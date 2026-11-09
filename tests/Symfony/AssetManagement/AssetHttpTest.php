<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\AssetManagement;

use Kadupul\AssetManagement\Application\Port\AssetStore;
use Kadupul\AssetManagement\Application\Port\AssetWriter;
use Kadupul\AssetManagement\Infrastructure\Symfony\WebAssetAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\IdentityAccess\Contract\AssetManagementPermission;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Contract\LocalePreference;
use Kadupul\Platform\Contract\DatabaseConnection;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AssetHttpTest extends TestCase
{
    public static function requests(): iterable
    {
        yield 'render' => ['render', 200];
        yield 'disabled' => ['disabled', 404];
        yield 'anonymous' => ['anonymous', 401];
        yield 'realm denied' => ['denied', 403];
        yield 'remote collector' => ['remote', 403];
        yield 'empty form' => ['empty', 422];
        yield 'nested field' => ['nested', 422];
        yield 'missing CSRF' => ['csrf', 403];
        yield 'accepted post' => ['success', 303];
        yield 'partial edit preserves omissions' => ['partial', 303];
        yield 'unconfirmed write' => ['uncertain', 503];
        yield 'French preference' => ['fr', 200];
    }

    #[DataProvider('requests')]
    public function testRenderedFormsAndIndependentAccessGates(string $mode, int $status): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($mode === 'anonymous' ? null : new Actor(1, 'operator'));
            $permission = $this->createMock(AssetManagementPermission::class);
            $permission->method('mayManageAssets')->willReturn($mode !== 'denied');
            $topology = $this->createMock(CollectorTopology::class);
            $topology->method('isRemoteCollector')->willReturn($mode === 'remote');
            $container->set(WebAssetAccess::class, new WebAssetAccess($mode !== 'disabled', $console, $permission, $topology));
            $store = $this->createMock(AssetStore::class);
            $store->method('snapshot')->willReturn(['revision' => 0, 'policy' => [], 'sources' => [], 'assets' => [], 'identities' => [], 'dependencies' => []]);
            $store->method('history')->willReturn([]);
            $container->set(AssetStore::class, $store);
            $writer = $this->createMock(AssetWriter::class);
            if (in_array($mode, ['success', 'partial'], true)) {
                $writer->expects($this->once())->method('apply')->willReturnCallback(function (array $command) use ($mode): array {
                    if ($mode === 'partial') {
                        $this->assertSame(['owner' => 'New team'], $command['fields']);
                    }
                    return ['request_id' => $command['request_id'], 'revision' => 1];
                });
            } elseif ($mode === 'uncertain') {
                $writer->expects($this->once())->method('apply')->willThrowException(new \Kadupul\AssetManagement\Domain\AssetError('uncertain'));
            } else {
                $writer->expects($this->never())->method('apply');
            }
            $container->set(AssetWriter::class, $writer);
            $request = Request::create('/assets');
            if (in_array($mode, ['disabled', 'anonymous', 'denied', 'remote'], true)) {
                $request->cookies->set('test', 'denied-before-locale');
                $database = $this->createMock(DatabaseConnection::class);
                $database->expects($this->never())->method('get');
                $container->set(DatabaseConnection::class, $database);
            }
            if (in_array($mode, ['empty', 'nested', 'csrf', 'success', 'uncertain', 'partial'], true)) {
                $fields = ['action' => 'configure', 'request_id' => str_repeat('a', 32), 'revision' => '0', 'scope' => 'Production', 'steward' => 'Network operations', 'freshness_seconds' => '86400', '_token' => $container->get(CsrfTokenManagerInterface::class)->getToken('asset_register')->getValue()];
                if ($mode === 'empty') {
                    $fields['scope'] = '';
                } elseif ($mode === 'nested') {
                    $fields['scope'] = ['nested'];
                } elseif ($mode === 'csrf') {
                    unset($fields['_token']);
                } elseif ($mode === 'partial') {
                    $fields = array_intersect_key($fields, array_flip(['action', 'request_id', 'revision', '_token']));
                    $fields['action'] = 'edit';
                    $fields += ['asset_id' => str_repeat('b', 32), 'fields' => ['owner' => 'New team'], 'evidence' => 'Approved handover'];
                }
                $request = Request::create('/assets', 'POST', $fields);
                if ($mode !== 'csrf') {
                    $request->headers->set('Origin', 'http://localhost');
                }
            }
            if ($mode === 'fr') {
                $pdo = new \PDO('sqlite::memory:');
                $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
                $db = $this->createMock(DatabaseConnection::class);
                $db->method('get')->willReturn($pdo);
                $container->set(DatabaseConnection::class, $db);
                $configuration = $this->createMock(LegacyConfiguration::class);
                $configuration->method('values')->willReturn([]);
                $container->set(LegacyConfiguration::class, $configuration);
                $locale = $this->createMock(LocalePreference::class);
                $locale->method('preferredLocale')->willReturn('fr-FR');
                $container->set(LocalePreference::class, $locale);
                $request->cookies->set('test', 'locale-preference');
            }
            $response = $kernel->handle($request);
            $this->assertSame($status, $response->getStatusCode(), substr(strip_tags($response->getContent()), 0, 500));
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            if ($mode === 'uncertain') {
                $this->assertStringContainsString(str_repeat('a', 32), $response->getContent());
                $this->assertStringNotContainsString('<form', $response->getContent());
            }
            if ($mode === 'fr') {
                $this->assertStringContainsString('Registre des actifs', $response->getContent());
                $this->assertStringContainsString('Politique du pilote', $response->getContent());
            }
            if ($mode === 'render') {
                $document = new \DOMDocument();
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                $this->assertSame(1, $xpath->query('//form[@method="post"]//input[@name="_token"]')->length);
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
