<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Domain\OwnershipClaimRefused;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryWriterClaimStore;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterClaimStore;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use PHPUnit\Framework\TestCase;

/** The claim rules, independent of where claims are kept. */
final class WriterOwnershipRegistryTest extends TestCase
{
    private FixedWriterOwnershipProbe $legacy;
    private InMemoryWriterClaimStore $store;
    private WriterOwnershipRegistry $registry;

    protected function setUp(): void
    {
        $this->legacy = new FixedWriterOwnershipProbe([BindingFixture::field(7, 'traffic_in')->toString() => WriterOwnership::LegacyPoller]);
        $this->store = new InMemoryWriterClaimStore();
        $this->registry = new WriterOwnershipRegistry($this->legacy, $this->store);
    }

    public function testWithoutAClaimTheLegacyAnswerStands(): void
    {
        self::assertSame(WriterOwnership::LegacyPoller, $this->registry->ownership(BindingFixture::field(7, 'traffic_in')));
        self::assertSame(WriterOwnership::Unassigned, $this->registry->ownership(BindingFixture::field(9, 'traffic_in')));
        self::assertSame([], $this->store->all());
    }

    public function testALegacyFieldIsRefusedAndNothingIsRecorded(): void
    {
        try {
            $this->registry->claim(BindingFixture::field(7, 'traffic_in'));
            self::fail('Claimed a field the legacy poller writes.');
        } catch (OwnershipClaimRefused $e) {
            self::assertStringContainsString('no handoff', $e->getMessage());
        }
        self::assertSame([], $this->store->all());
        self::assertSame(WriterOwnership::LegacyPoller, $this->registry->ownership(BindingFixture::field(7, 'traffic_in')));
    }

    public function testAClaimIsKeptByTheStoreAndSeenByAnotherRegistry(): void
    {
        $field = BindingFixture::field(9, 'traffic_in');

        self::assertSame(WriterOwnership::ModelDriven, $this->registry->claim($field));
        self::assertSame(WriterOwnership::ModelDriven, (new WriterOwnershipRegistry($this->legacy, $this->store))->ownership($field));
        self::assertSame(WriterOwnership::ModelDriven, $this->registry->claim($field), 'claiming again changes nothing');
        self::assertCount(1, $this->store->all());
        self::assertTrue($this->store->all()[0]->equals($field));
    }

    public function testAClaimLostToAConcurrentWriterReportsTheStoredOwner(): void
    {
        $field = BindingFixture::field(9, 'traffic_in');
        $raced = new class ($field) implements WriterClaimStore {
            public int $recorded = 0;

            public function __construct(private StorageField $winner) {}

            public function holds(StorageField $field): bool
            {
                // The other writer commits between this check and the insert.
                return $this->recorded > 0;
            }

            public function record(StorageField $field): bool
            {
                ++$this->recorded;

                return false;
            }

            public function all(): array
            {
                return [$this->winner];
            }
        };

        self::assertSame(WriterOwnership::ModelDriven, (new WriterOwnershipRegistry($this->legacy, $raced))->claim($field));
        self::assertSame(1, $raced->recorded);
    }

    public function testALegacyWriterAppearingAfterTheClaimIsAConflict(): void
    {
        $field = BindingFixture::field(9, 'traffic_in');
        $this->registry->claim($field);
        $this->legacy->owners[$field->toString()] = WriterOwnership::LegacyPoller;

        self::assertSame(WriterOwnership::Conflict, $this->registry->ownership($field));
        self::assertSame(WriterOwnership::Conflict, $this->registry->claim($field));
    }

    public function testTheInMemoryStoreTellsFieldsOfOneFileApart(): void
    {
        self::assertTrue($this->store->record(BindingFixture::field(9, 'traffic_in')));
        self::assertFalse($this->store->record(BindingFixture::field(9, 'traffic_in')));
        self::assertFalse($this->store->holds(BindingFixture::field(9, 'Traffic_in')));
        self::assertTrue($this->store->record(BindingFixture::field(9, 'Traffic_in')));
        self::assertCount(2, $this->store->all());
    }
}
