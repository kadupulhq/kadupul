<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\CreateSite;
use Kadupul\Inventory\Application\Port\SiteCreator;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SiteCreateTest extends TestCase
{
    public function testCreationValidatesAndNormalizesAllFields(): void
    {
        $site = new NewSite(['name' => ' 東京 ', 'notes' => str_repeat('🌏', 1024), 'city' => str_repeat('東', 50), 'latitude' => '-90', 'longitude' => '180.0000000000', 'timezone' => 'UTC', 'zoom' => '23']);
        self::assertSame('東京', $site->fields['name']);
        self::assertSame('-90', $site->fields['latitude']);
        self::assertSame('180.0000000000', $site->fields['longitude']);
        self::assertSame('UTC', $site->fields['timezone']);
        self::assertSame(array_keys(NewSite::DEFAULTS), array_keys($site->fields));
        $defaults = new NewSite(['name' => 'New']);
        self::assertSame('0', $defaults->fields['latitude']);
        self::assertSame('12', $defaults->fields['zoom']);
    }

    public static function invalidFields(): iterable
    {
        foreach (['name' => ['', str_repeat('東', 101), "a\0b"], 'notes' => [str_repeat('東', 1025)], 'city' => [str_repeat('x', 51)], 'timezone' => ['Invalid/Zone'], 'latitude' => ['90.1', '-90.1', 'NaN', '1e2', '1.12345678901'], 'longitude' => ['180.1'], 'zoom' => ['24', '-1', '0.5'], 'address1' => [[1], "\xff"]] as $field => $values) {
            foreach ($values as $index => $value) {
                yield $field . $index => [[$field => $value]];
            }
        }
        yield 'unexpected id' => [['id' => '42']];
    }

    #[DataProvider('invalidFields')]
    public function testInvalidDataCannotReachPersistence(array $fields): void
    {
        $creator = $this->createMock(SiteCreator::class);
        $creator->expects(self::never())->method('create');
        $this->expectException(\InvalidArgumentException::class);
        (new CreateSite($this->access(), $creator))(array_replace(['name' => 'Valid'], $fields));
    }

    public function testUseCasePassesActorAndValidatedDataToPort(): void
    {
        $creator = $this->createMock(SiteCreator::class);
        $creator->expects(self::once())->method('create')->with(42, self::callback(static fn(NewSite $site): bool => $site->fields['name'] === 'Tokyo'))->willReturn(7);
        self::assertSame(7, (new CreateSite($this->access(), $creator))(['name' => ' Tokyo ']));
    }

    public function testUnauthorizedCreationDoesNotReachPort(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(null);
        $creator = $this->createMock(SiteCreator::class);
        $creator->expects(self::never())->method('create');
        $this->expectException(InventoryAccessDenied::class);
        (new CreateSite($access, $creator))(['name' => 'Denied']);
    }

    private function access(): ConsoleAccess
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);

        return $access;
    }

    public static function requests(): iterable
    {
        yield 'success' => ['success', 303];
        yield 'extra field' => ['extra', 422];
        yield 'invalid name' => ['invalid', 422];
        yield 'missing csrf' => ['csrf', 422];
        yield 'revoked at persistence' => ['revoked', 403];
        yield 'uncertain write' => ['failure', 502];
    }

    #[DataProvider('requests')]
    public function testSymfonyFormRoutesOnlyValidatedAuthorizedPosts(string $mode, int $expected): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $container->set(ConsoleAccess::class, $this->access());
            $creator = $this->createMock(SiteCreator::class);
            if ($mode === 'revoked') {
                $creator->expects(self::once())->method('create')->willThrowException(new InventoryAccessDenied(false));
            } elseif ($mode === 'failure') {
                $creator->expects(self::once())->method('create')->willThrowException(new \RuntimeException('private-sql-host'));
            } elseif ($mode === 'success') {
                $creator->expects(self::once())->method('create')->willReturn(7);
            } else {
                $creator->expects(self::never())->method('create');
            }
            $container->set(SiteCreator::class, $creator);
            $token = $container->get(CsrfTokenManagerInterface::class)->getToken('inventory_site_create')->getValue();
            $data = ['name' => $mode === 'invalid' ? '' : 'Tokyo', '_token' => $token];
            if ($mode === 'extra') {
                $data['id'] = '99';
            }
            if ($mode === 'csrf') {
                unset($data['_token']);
            }
            $request = Request::create('/inventory/sites/new', 'POST', ['site_create' => $data]);
            if ($mode !== 'csrf') {
                $request->headers->set('Origin', 'http://localhost');
            }
            $response = $kernel->handle($request);
            self::assertSame($expected, $response->getStatusCode(), substr(strip_tags($response->getContent()), 0, 900));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertStringNotContainsString('private-sql-host', $response->getContent());
            if ($mode === 'success') {
                self::assertStringContainsString('/inventory/sites/7/edit', $response->headers->get('Location'));
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
