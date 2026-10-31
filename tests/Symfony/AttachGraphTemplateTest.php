<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\AttachGraphTemplate;
use Kadupul\Graphing\Application\Command\GraphCommandAccess;
use Kadupul\Graphing\Application\Command\GraphManagementDenied;
use Kadupul\Graphing\Application\Command\GraphTemplateAttachment;
use Kadupul\IdentityAccess\Contract\AuditTrail;
use Kadupul\IdentityAccess\Contract\CollectorTopology;
use Kadupul\IdentityAccess\Infrastructure\Cli\CliConsoleAccess;
use Kadupul\Tests\Fixtures\ConsoleOperatorDatabase;
use Kadupul\Tests\Fixtures\InMemoryGraphTemplateAssignment;
use PHPUnit\Framework\TestCase;

final class AttachGraphTemplateTest extends TestCase
{
    use ConsoleOperatorDatabase;

    private \Doctrine\DBAL\Connection $db;

    protected function setUp(): void
    {
        $this->db = $this->operatorDatabase(8, 3);
    }

    private function useCase(InMemoryGraphTemplateAssignment $assignment): AttachGraphTemplate
    {
        $access = new GraphCommandAccess(new CliConsoleAccess($this->db, $this->db), $this->createStub(CollectorTopology::class), $this->createStub(AuditTrail::class));

        return new AttachGraphTemplate($access, $assignment, $assignment);
    }

    public function testItAssociatesAKnownTemplateWithAKnownDevice(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment();
        $result = ($this->useCase($assignment))(false, null, '7', '3');

        self::assertTrue($result->succeeded());
        self::assertSame([[7, 3]], $assignment->attached);
        self::assertSame(['core1', 'Traffic'], [$result->device?->hostname, $result->template?->name]);
    }

    public function testADeviceIdThatIsNumericButWrittenAsAFloatStillFindsTheDevice(): void
    {
        self::assertTrue((($this->useCase(new InMemoryGraphTemplateAssignment()))(false, null, '7e0', '3'))->succeeded());
    }

    public function testAnUnknownDeviceOrTemplateIsRefusedBeforeAnythingIsWritten(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment();

        self::assertSame(GraphTemplateAttachment::UNKNOWN_DEVICE, ($this->useCase($assignment))(false, null, '8', '3')->status);
        self::assertSame(GraphTemplateAttachment::UNKNOWN_TEMPLATE, ($this->useCase($assignment))(false, null, '7', '99')->status);
        self::assertSame([], $assignment->attached);
    }

    public function testAnExistingAssociationIsRefusedAndNotRewritten(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment();
        $result = ($this->useCase($assignment))(false, null, '7', '4');

        self::assertSame(GraphTemplateAttachment::ALREADY, $result->status);
        self::assertFalse($result->succeeded());
        self::assertSame([], $assignment->attached);
    }

    public function testAFailedWorkerIsReportedNotThrown(): void
    {
        $assignment = new InMemoryGraphTemplateAssignment(fails: true);
        $result = ($this->useCase($assignment))(false, null, '7', '3');

        self::assertSame(GraphTemplateAttachment::FAILED, $result->status);
        self::assertSame([[7, 3]], $assignment->attached);
    }

    public function testGraphsAloneAreNotEnoughBecauseTheWebPageIsHostPhp(): void
    {
        $this->db->executeStatement('DELETE FROM user_auth_realm WHERE realm_id = 3');
        $this->db->executeStatement('INSERT INTO user_auth_realm (user_id, realm_id) VALUES (1, 5)');
        $assignment = new InMemoryGraphTemplateAssignment();

        $this->expectException(GraphManagementDenied::class);
        try {
            ($this->useCase($assignment))(false, null, '7', '3');
        } finally {
            self::assertSame([], $assignment->attached);
        }
    }
}
