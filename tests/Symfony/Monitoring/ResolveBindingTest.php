<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Application\ResolveBinding;
use Kadupul\Monitoring\Contract\BindingCatalog;
use Kadupul\Monitoring\Contract\BindingResolution;
use Kadupul\Monitoring\Contract\ResolutionStatus;
use Kadupul\Monitoring\Contract\RrdDataSourceType as ContractRrdDataSourceType;
use Kadupul\Monitoring\Contract\WriterOwnership as ContractWriterOwnership;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\MetricBinding;
use Kadupul\Monitoring\Domain\OwnershipClaimRefused;
use Kadupul\Monitoring\Domain\RrdDataSourceType;
use Kadupul\Monitoring\Domain\StorageField;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Telemetry\Contract\MetricId;
use Kadupul\Telemetry\Contract\ResourceId;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use PHPUnit\Framework\TestCase;

final class ResolveBindingTest extends TestCase
{
    private InMemoryBindingRepository $bindings;
    private FixedWriterOwnershipProbe $legacy;
    private WriterOwnershipRegistry $owners;

    protected function setUp(): void
    {
        $this->bindings = new InMemoryBindingRepository();
        $this->legacy = new FixedWriterOwnershipProbe();
        $this->owners = new WriterOwnershipRegistry($this->legacy);
    }

    public function testAMissingBindingDoesNotResolve(): void
    {
        $resolution = $this->resolve();

        self::assertSame(ResolutionStatus::NoBinding, $resolution->status);
        self::assertFalse($resolution->isResolved());
        self::assertNull($resolution->storage);
        self::assertNull($resolution->ownership);
        self::assertNull($resolution->bindingId);
        self::assertStringContainsString('No binding', $resolution->reason);
        self::assertSame('interface.in_octets', $resolution->metric->toString());
        self::assertSame('interface/eth0', $resolution->resource->toString());
    }

    public function testAProposedOrRejectedBindingIsNotApprovedAndCarriesNoStorage(): void
    {
        $this->bindings->save(BindingFixture::binding());
        self::assertSame(ResolutionStatus::NotApproved, $this->resolve()->status);

        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Rejected));
        $resolution = $this->resolve();

        self::assertSame(ResolutionStatus::NotApproved, $resolution->status);
        self::assertNull($resolution->storage);
        self::assertSame(BindingFixture::key()->bindingId(), $resolution->bindingId);
        self::assertStringContainsString('rejected', $resolution->reason);
    }

    public function testAnApprovedLegacyFieldResolvesButStaysOwnedByTheLegacyPoller(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
        $this->legacy->owners[BindingFixture::field()->toString()] = WriterOwnership::LegacyPoller;

        $resolution = $this->resolve();

        self::assertSame(ResolutionStatus::Resolved, $resolution->status);
        self::assertSame(ContractWriterOwnership::LegacyPoller, $resolution->ownership);
        self::assertFalse($resolution->permitsModelDrivenWrites());
        self::assertSame('traffic_in', $resolution->rrdDataSourceName);
        self::assertSame(ContractRrdDataSourceType::Counter, $resolution->rrdDataSourceType);
        self::assertSame('openconfig-interfaces', $resolution->packName);
        self::assertSame('1.0.0', $resolution->packVersion);
    }

    public function testOnlyAnExplicitClaimMakesAFieldModelDriven(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
        self::assertSame(ContractWriterOwnership::Unassigned, $this->resolve()->ownership);

        self::assertSame(WriterOwnership::ModelDriven, $this->owners->claim(BindingFixture::field()));
        self::assertSame(WriterOwnership::ModelDriven, $this->owners->claim(BindingFixture::field()), 'claiming again is a no-op');

        $resolution = $this->resolve();
        self::assertSame(ContractWriterOwnership::ModelDriven, $resolution->ownership);
        self::assertTrue($resolution->permitsModelDrivenWrites());
    }

    public function testAClaimOnALegacyFieldIsRefused(): void
    {
        foreach ([WriterOwnership::LegacyPoller, WriterOwnership::Conflict] as $legacy) {
            $this->legacy->owners[BindingFixture::field()->toString()] = $legacy;
            try {
                $this->owners->claim(BindingFixture::field());
                self::fail('Claimed a field reported as ' . $legacy->value);
            } catch (OwnershipClaimRefused $e) {
                self::assertStringContainsString('no handoff', $e->getMessage());
            }
        }
        self::assertSame([], $this->owners->claims());
    }

    public function testWhenBothSidesClaimTheFieldItIsAConflict(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved)->withStatus(BindingStatus::Verified));
        $this->owners->claim(BindingFixture::field());
        $this->legacy->owners[BindingFixture::field()->toString()] = WriterOwnership::LegacyPoller;

        self::assertSame(WriterOwnership::Conflict, $this->owners->claim(BindingFixture::field()));
        self::assertSame(ContractWriterOwnership::Conflict, $this->resolve()->ownership);
        self::assertFalse($this->resolve()->permitsModelDrivenWrites());
    }

    public function testDuplicateStoredBindingsAreAmbiguousNotPicked(): void
    {
        $approved = BindingFixture::binding()->withStatus(BindingStatus::Approved);
        $duplicates = new class ([$approved, BindingFixture::binding(field: BindingFixture::field(8, 'traffic_in'))->withStatus(BindingStatus::Approved)]) implements BindingRepository {
            /** @param list<MetricBinding> $found */
            public function __construct(private readonly array $found) {}

            public function matching(BindingKey $key): array
            {
                return $this->found;
            }

            public function writingTo(StorageField $field): array
            {
                return [];
            }

            public function forDevice(int $deviceId): array
            {
                return $this->found;
            }

            public function save(MetricBinding $binding): void
            {
                throw new \LogicException('Resolution must not write.');
            }
        };

        $resolution = (new ResolveBinding($duplicates, $this->owners))->resolve(42, new MetricId('interface.in_octets'), new ResourceId('interface', 'eth0'), 'snmp');

        self::assertSame(ResolutionStatus::Ambiguous, $resolution->status);
        self::assertNull($resolution->storage);
        self::assertStringContainsString('7:traffic_in', $resolution->reason);
        self::assertStringContainsString('8:traffic_in', $resolution->reason);
    }

    public function testAnInvalidRequestIsReportedAsNoBinding(): void
    {
        $resolution = (new ResolveBinding($this->bindings, $this->owners))->resolve(42, new MetricId('interface.in_octets'), new ResourceId('interface', 'eth0'), 'telnet');

        self::assertSame(ResolutionStatus::NoBinding, $resolution->status);
        self::assertStringContainsString('telnet', $resolution->reason);
    }

    public function testTheStorageReferenceNamesTheFileOpaquelyAndNeverAPath(): void
    {
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Approved));
        $this->bindings->save(BindingFixture::binding(key: BindingFixture::key(metric: 'interface.out_octets'), field: BindingFixture::field(7, 'traffic_out'))->withStatus(BindingStatus::Approved));

        $in = $this->resolve();
        $out = $this->resolve('interface.out_octets');

        self::assertNotNull($in->storage);
        self::assertNotNull($out->storage);
        self::assertTrue($in->storage->equals($out->storage), 'two fields of one RRD file share the file reference');
        self::assertNotSame($in->rrdDataSourceName, $out->rrdDataSourceName);
        self::assertDoesNotMatchRegularExpression('/path_rra|\.rrd|\//', $in->storage->toString());
    }

    public function testContractEnumsMirrorTheDomainEnums(): void
    {
        self::assertSame(array_column(WriterOwnership::cases(), 'value'), array_column(ContractWriterOwnership::cases(), 'value'));
        self::assertSame(array_column(RrdDataSourceType::cases(), 'value'), array_column(ContractRrdDataSourceType::cases(), 'value'));
        self::assertInstanceOf(BindingCatalog::class, new ResolveBinding($this->bindings, $this->owners));
    }

    private function resolve(string $metric = 'interface.in_octets'): BindingResolution
    {
        return (new ResolveBinding($this->bindings, $this->owners))->resolve(42, new MetricId($metric), new ResourceId('interface', 'eth0'), 'snmp');
    }
}
