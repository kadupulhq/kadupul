<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\AssociateDataQuery;
use Kadupul\Graphing\Application\Command\DataQueryAssociation;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Domain\ReindexMethod;
use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryDataQueryAssociation;
use PHPUnit\Framework\TestCase;

final class AssociateDataQueryTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    /** @var list<AuditEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 3);
    }

    private function useCase(InMemoryDataQueryAssociation $association): AssociateDataQuery
    {
        $trail = $this->createStub(AuditTrail::class);
        $trail->method('record')->willReturnCallback(function (AuditEvent $event): void {
            $this->events[] = $event;
        });
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $trail);

        return new AssociateDataQuery($access, $association, $association);
    }

    private static function method(string $typed): ReindexMethod
    {
        return ReindexMethod::parse($typed) ?? throw new \LogicException('The method parses.');
    }

    public function testItAssociatesAKnownQueryWithAKnownDeviceAndAuditsIt(): void
    {
        $association = new InMemoryDataQueryAssociation();
        $result = ($this->useCase($association))(false, null, '7', '3', self::method('uptime'));

        self::assertTrue($result->succeeded());
        self::assertSame([[7, 3, 1]], $association->associated);
        self::assertSame(['core1', 'Interfaces'], [$result->device?->hostname, $result->dataQuery?->name]);
        self::assertCount(1, $this->events);
        self::assertSame(['device.data-query.associate', 'local:7.3', AuditEvent::SUCCEEDED], [$this->events[0]->action, $this->events[0]->targetId, $this->events[0]->outcome]);
    }

    public function testADeviceIdThatIsNumericButWrittenAsAFloatStillFindsTheDevice(): void
    {
        self::assertTrue((($this->useCase(new InMemoryDataQueryAssociation()))(false, null, '7e0', '3', self::method('0')))->succeeded());
    }

    public function testAnUnknownDeviceOrQueryIsRefusedBeforeAnythingIsWrittenOrAudited(): void
    {
        $association = new InMemoryDataQueryAssociation();

        self::assertSame(DataQueryAssociation::UNKNOWN_DEVICE, ($this->useCase($association))(false, null, '8', '3', self::method('1'))->status);
        self::assertSame(DataQueryAssociation::UNKNOWN_QUERY, ($this->useCase($association))(false, null, '7', '99', self::method('1'))->status);
        self::assertSame([], $association->associated);
        self::assertSame([], $this->events);
    }

    public function testAnExistingAssociationWithTheSameMethodIsRefusedAndNotRewritten(): void
    {
        $association = new InMemoryDataQueryAssociation();
        $result = ($this->useCase($association))(false, null, '7', '4', self::method('uptime'));

        self::assertSame(DataQueryAssociation::ALREADY, $result->status);
        self::assertFalse($result->succeeded());
        self::assertSame([], $association->associated);
    }

    public function testAnExistingAssociationWithAnotherMethodIsReplaced(): void
    {
        $association = new InMemoryDataQueryAssociation();
        $result = ($this->useCase($association))(false, null, '7', '4', self::method('3'));

        self::assertTrue($result->succeeded());
        self::assertSame([[7, 4, 3]], $association->associated);
    }

    public function testAFailedWorkerIsReportedNotThrownAndAuditedAsFailed(): void
    {
        $association = new InMemoryDataQueryAssociation(fails: true);
        $result = ($this->useCase($association))(false, null, '7', '3', self::method('1'));

        self::assertSame(DataQueryAssociation::FAILED, $result->status);
        self::assertSame([[7, 3, 1]], $association->associated);
        self::assertSame(AuditEvent::FAILED, $this->events[0]->outcome);
    }

    public function testGraphsAloneAreNotEnoughBecauseTheWebPageIsHostPhp(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5)');
        $association = new InMemoryDataQueryAssociation();

        try {
            ($this->useCase($association))(false, null, '7', '3', self::method('1'));
            self::fail('The operator without the devices realm was admitted.');
        } catch (GraphManagementDenied) {
            self::assertSame([], $association->associated);
            self::assertSame([AuditEvent::DENIED], array_map(static fn(AuditEvent $event): string => $event->decision, $this->events));
        }
    }
}
