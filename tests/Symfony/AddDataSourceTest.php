<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\AddDataSource;
use Kadupul\Graphing\Application\Command\DataSourceAddition;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataSourceCreation;
use PHPUnit\Framework\TestCase;

final class AddDataSourceTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 3);
    }

    private function useCase(InMemoryDataSourceCreation $creation): AddDataSource
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $trail);

        return new AddDataSource($access, $creation, $creation);
    }

    public function testItAddsADataSourceAndAuditsTheNewId(): void
    {
        $creation = new InMemoryDataSourceCreation();
        $result = ($this->useCase($creation))(false, null, '7', '3');

        self::assertTrue($result->succeeded());
        self::assertSame(41, $result->id);
        self::assertSame([[7, 3, true]], $creation->created);
        self::assertSame(['core1', 'Traffic'], [$result->device?->name, $result->dataTemplate?->name]);
        self::assertCount(1, $this->events);
        self::assertSame(['data-source.add', 'data-source', 'local:41', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetType, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testAnIdWrittenAsAFloatStillFindsTheDeviceAndTheTemplate(): void
    {
        $creation = new InMemoryDataSourceCreation();

        self::assertTrue(($this->useCase($creation))(false, null, '7e0', '3.0')->succeeded());
        self::assertSame([[7, 3, true]], $creation->created);
    }

    public function testADeviceOfZeroMeansNoDeviceAndNoLookupForItOrForATemplateOfZero(): void
    {
        $creation = new InMemoryDataSourceCreation();
        $useCase = $this->useCase($creation);

        self::assertTrue($useCase(false, null, '0', '0')->succeeded());
        self::assertTrue($useCase(false, null, '-0', '0.0')->succeeded());
        self::assertTrue($useCase(false, null, '0e5', '3')->succeeded());
        // The original pushed the device's fields out unless the device id was the text "0"; "0.0" and "-0" did.
        self::assertSame([[0, 0, false], [0, 0, true], [0, 3, true]], $creation->created);
    }

    public function testAnUnknownDeviceOrTemplateIsRefusedBeforeAnythingIsWrittenOrAudited(): void
    {
        $creation = new InMemoryDataSourceCreation();

        foreach ([['8', '3', DataSourceAddition::UNKNOWN_DEVICE], ['-7', '3', DataSourceAddition::UNKNOWN_DEVICE], ['7.5', '3', DataSourceAddition::UNKNOWN_DEVICE],
            ['7', '4', DataSourceAddition::UNKNOWN_TEMPLATE], ['7', '-3', DataSourceAddition::UNKNOWN_TEMPLATE]] as [$device, $template, $status]) {
            self::assertSame($status, ($this->useCase($creation))(false, null, $device, $template)->status, $device . ' ' . $template);
        }
        self::assertSame([], $creation->created);
        self::assertSame([], $this->events);
    }

    public function testAFailedWorkerIsReportedNotThrownAndAuditedAsFailed(): void
    {
        $creation = new InMemoryDataSourceCreation(fails: true);
        $result = ($this->useCase($creation))(false, null, '7', '3');

        self::assertSame(DataSourceAddition::FAILED, $result->status);
        self::assertFalse($result->succeeded());
        self::assertSame([[7, 3, true]], $creation->created);
        self::assertSame([AuditEvent::FAILED, 'local:0'], [$this->events[0]->outcome, $this->events[0]->targetId]);
    }

    public function testGraphsAloneAreNotEnoughBecauseTheWebPagesAreHostPhpAndDataSourcesPhp(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5)');
        $creation = new InMemoryDataSourceCreation();

        try {
            ($this->useCase($creation))(false, null, '7', '3');
            self::fail('The operator without the devices realm was admitted.');
        } catch (GraphManagementDenied) {
            self::assertSame([], $creation->created);
            self::assertSame([AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->decision, $this->events));
        }
    }
}
