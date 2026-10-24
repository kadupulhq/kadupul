<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\DiscoverDeviceCapabilities;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityDiscoveryResult;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\InvalidCapability;
use Kadupul\Monitoring\Domain\ModelReference;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryDeviceCapabilityStore;
use Kadupul\Telemetry\Contract\CollectionErrorCode;
use Kadupul\Telemetry\Contract\CollectionStatus;
use Kadupul\Tests\Monitoring\Fakes\FakeOpenConfigCapabilityProvider;
use Kadupul\Tests\Monitoring\Fakes\FakeSnmpCapabilityProvider;
use PHPUnit\Framework\TestCase;

final class DiscoverDeviceCapabilitiesTest extends TestCase
{
    private const DEVICE = 42;

    private FakeOpenConfigCapabilityProvider $gnmi;
    private FakeSnmpCapabilityProvider $snmp;
    private InMemoryDeviceCapabilityStore $store;
    private DiscoverDeviceCapabilities $discover;

    protected function setUp(): void
    {
        $this->gnmi = new FakeOpenConfigCapabilityProvider();
        $this->snmp = new FakeSnmpCapabilityProvider();
        $this->store = new InMemoryDeviceCapabilityStore();
        $this->discover = new DiscoverDeviceCapabilities([$this->gnmi, $this->snmp], $this->store, RegistryFixture::catalog());
    }

    public function testResultsFromEveryProviderAreMergedWithRegistryCapabilities(): void
    {
        $this->advertiseOpenConfig();
        $this->snmp->verify(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);

        $result = ($this->discover)(self::DEVICE);

        self::assertSame(CollectionStatus::Succeeded, $result->status());
        self::assertSame([], $result->errors);
        $interfaces = $this->only($result, 'openconfig-interfaces');
        self::assertSame(CapabilityStatus::Advertised, $interfaces->status);
        self::assertSame('gnmi', $interfaces->source);
        self::assertSame(['openconfig-interfaces'], $interfaces->provides);
        $ifMib = $this->only($result, 'IF-MIB');
        self::assertSame(CapabilityStatus::Verified, $ifMib->status);
        self::assertSame('snmp', $ifMib->source);
        self::assertSame(['snmp:if-mib'], $ifMib->provides);
        self::assertCount(3, $this->store->capabilitiesFor(self::DEVICE));
    }

    public function testRepeatingDiscoveryYieldsAnEqualResultAndNoDuplicateRecords(): void
    {
        $this->advertiseOpenConfig();
        $this->snmp->advertise(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);

        $first = ($this->discover)(self::DEVICE);
        $stored = $this->store->capabilitiesFor(self::DEVICE);
        $second = ($this->discover)(self::DEVICE);

        self::assertEquals($first, $second);
        self::assertEquals($stored, $this->store->capabilitiesFor(self::DEVICE));
        self::assertCount(3, $this->store->capabilitiesFor(self::DEVICE));
    }

    public function testAnAdvertisementDoesNotWeakenAStoredVerificationOutcome(): void
    {
        $this->snmp->verify(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $this->snmp->report(self::DEVICE, 'urn:example:ent', 'ENTITY-MIB', '2005-08-10', CapabilityStatus::Unsupported, 'entPhysicalTable returned noSuchObject');
        ($this->discover)(self::DEVICE);

        $this->snmp->advertise(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $this->snmp->report(self::DEVICE, 'urn:example:ent', 'ENTITY-MIB', '2005-08-10', CapabilityStatus::Unknown);
        ($this->discover)(self::DEVICE);

        $stored = $this->storedByName();
        self::assertSame(CapabilityStatus::Verified, $stored['IF-MIB']->status);
        self::assertSame(CapabilityStatus::Unsupported, $stored['ENTITY-MIB']->status);
        self::assertSame('entPhysicalTable returned noSuchObject', $stored['ENTITY-MIB']->reason);
    }

    public function testANewVerificationOutcomeReplacesAnEarlierOne(): void
    {
        $this->snmp->verify(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        ($this->discover)(self::DEVICE);
        $this->snmp->report(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV, CapabilityStatus::Unsupported, 'ifXTable timed out');

        $result = ($this->discover)(self::DEVICE);

        self::assertSame(CapabilityStatus::Unsupported, $this->only($result, 'IF-MIB')->status);
        self::assertSame('ifXTable timed out', $this->only($result, 'IF-MIB')->reason);
    }

    public function testAFailingProviderIsReportedAndDoesNotAbortTheOthers(): void
    {
        $this->snmp->verify(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $this->advertiseOpenConfig();
        ($this->discover)(self::DEVICE);
        $this->gnmi->failWith(new \RuntimeException('connection refused on port 9339'));

        $result = ($this->discover)(self::DEVICE);

        self::assertSame(2, $this->snmp->calls);
        self::assertSame(CollectionStatus::Partial, $result->status());
        self::assertCount(1, $result->errors);
        self::assertSame(CollectionErrorCode::Unavailable, $result->errors[0]->code);
        self::assertStringContainsString('gnmi', $result->errors[0]->message);
        self::assertStringContainsString('connection refused on port 9339', $result->errors[0]->message);
        // The previous gNMI results stay as they were.
        self::assertSame(CapabilityStatus::Advertised, $this->only($result, 'openconfig-interfaces')->status);
        self::assertSame(CapabilityStatus::Verified, $this->only($result, 'IF-MIB')->status);
    }

    public function testEveryProviderFailingIsAFailedDiscovery(): void
    {
        $this->gnmi->failWith(new \RuntimeException('timeout'));
        $this->snmp->failWith(new \RuntimeException('no response from agent'));

        $result = ($this->discover)(self::DEVICE);

        self::assertSame(CollectionStatus::Failed, $result->status());
        self::assertCount(2, $result->errors);
    }

    public function testAProviderAnsweringForAnotherDeviceIsAnErrorNotData(): void
    {
        $this->snmp->verify(7, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $wrong = new class implements \Kadupul\Monitoring\Application\Port\CapabilityProvider {
            public function protocol(): string
            {
                return 'snmp';
            }

            public function discoverCapabilities(int $deviceId): CapabilityDiscoveryResult
            {
                return new CapabilityDiscoveryResult(7, []);
            }
        };
        $discover = new DiscoverDeviceCapabilities([$wrong], $this->store, RegistryFixture::catalog());

        $result = $discover(self::DEVICE);

        self::assertSame(CollectionStatus::Failed, $result->status());
        self::assertStringContainsString('device 7', $result->errors[0]->message);
    }

    public function testAnUnregisteredModelIsUnknownWithAReason(): void
    {
        $this->gnmi->advertise(self::DEVICE, 'http://example.net/yang/vendor-qos', 'vendor-qos', '2023-01-01');

        $result = ($this->discover)(self::DEVICE);

        $qos = $this->only($result, 'vendor-qos');
        self::assertSame(CapabilityStatus::Unknown, $qos->status);
        self::assertSame([], $qos->provides);
        self::assertStringContainsString('not registered', (string) $qos->reason);
        // The device's own claim is kept as it was reported.
        self::assertSame(CapabilityStatus::Advertised, $this->storedByName()['vendor-qos']->status);
    }

    public function testAnUnregisteredRevisionOfAKnownModelIsUnknown(): void
    {
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', '2015-01-01');

        $qos = $this->only(($this->discover)(self::DEVICE), 'openconfig-interfaces');

        self::assertSame(CapabilityStatus::Unknown, $qos->status);
        self::assertStringContainsString('2015-01-01', (string) $qos->reason);
    }

    public function testAMissingDependencyMakesTheCapabilityPartial(): void
    {
        $this->gnmi->verify(self::DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV);

        $interfaces = $this->only(($this->discover)(self::DEVICE), 'openconfig-interfaces');

        self::assertSame(CapabilityStatus::Partial, $interfaces->status);
        self::assertStringContainsString('openconfig-types', (string) $interfaces->reason);
        self::assertStringContainsString('does not advertise', (string) $interfaces->reason);
        self::assertSame(['openconfig-interfaces'], $interfaces->provides);
        // Once the device advertises the dependency the assessment recovers; the
        // stored verification was never downgraded.
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_TYPES_NS, 'openconfig-types', RegistryFixture::OC_TYPES_REV);
        self::assertSame(CapabilityStatus::Verified, $this->only(($this->discover)(self::DEVICE), 'openconfig-interfaces')->status);
    }

    public function testADependencyBelowTheRequiredRevisionMakesTheCapabilityUnsupported(): void
    {
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV);
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_TYPES_NS, 'openconfig-types', '2017-01-01');

        $interfaces = $this->only(($this->discover)(self::DEVICE), 'openconfig-interfaces');

        self::assertSame(CapabilityStatus::Unsupported, $interfaces->status);
        self::assertStringContainsString('2019-04-16', (string) $interfaces->reason);
        self::assertStringContainsString('2017-01-01', (string) $interfaces->reason);
    }

    public function testAWithdrawnAdvertisementBecomesUnknownButAVerifiedModelIsKept(): void
    {
        $this->advertiseOpenConfig();
        $this->snmp->verify(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        ($this->discover)(self::DEVICE);
        $this->gnmi->withdraw(self::DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces');
        $this->snmp->withdraw(self::DEVICE, RegistryFixture::IF_MIB_NS, 'IF-MIB');

        $result = ($this->discover)(self::DEVICE);

        $interfaces = $this->only($result, 'openconfig-interfaces');
        self::assertSame(CapabilityStatus::Unknown, $interfaces->status);
        self::assertSame('No longer advertised over gnmi.', $interfaces->reason);
        self::assertSame(CapabilityStatus::Verified, $this->only($result, 'IF-MIB')->status);
        self::assertSame(['IF-MIB'], array_map(static fn(Capability $c): string => $c->model->name, $result->notReported));
    }

    public function testAProviderMayOnlyReportItsOwnProtocol(): void
    {
        $this->expectException(InvalidCapability::class);
        $this->expectExceptionMessage('protocol');

        new Capability(new ModelReference('yang', 'urn:x', 'x', '2020-01-01'), 'SNMP v2', CapabilityStatus::Advertised);
    }

    public function testAModelReferenceRejectsAnUnknownFamily(): void
    {
        $this->expectException(InvalidCapability::class);

        new ModelReference('mib', 'urn:x', 'x', '1');
    }

    private function advertiseOpenConfig(): void
    {
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_INTERFACES_NS, 'openconfig-interfaces', RegistryFixture::OC_INTERFACES_REV);
        $this->gnmi->advertise(self::DEVICE, RegistryFixture::OC_TYPES_NS, 'openconfig-types', RegistryFixture::OC_TYPES_REV);
    }

    private function only(CapabilityDiscoveryResult $result, string $name): Capability
    {
        $matches = array_values(array_filter($result->capabilities, static fn(Capability $c): bool => $c->model->name === $name));
        self::assertCount(1, $matches, $name);

        return $matches[0];
    }

    /** @return array<string, Capability> */
    private function storedByName(): array
    {
        $byName = [];
        foreach ($this->store->capabilitiesFor(self::DEVICE) as $capability) {
            $byName[$capability->model->name] = $capability;
        }

        return $byName;
    }
}
