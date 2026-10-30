<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Kernel;
use Kadupul\Monitoring\Application\ApproveBinding;
use Kadupul\Monitoring\Application\DiscoverDeviceCapabilities;
use Kadupul\Monitoring\Domain\BindingApprovalDenied;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\Capability;
use Kadupul\Monitoring\Domain\CapabilityStatus;
use Kadupul\Monitoring\Domain\ModelReference;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryDeviceCapabilityStore;
use Kadupul\Monitoring\Infrastructure\Workflow\BindingApprovalGuard;
use Kadupul\Monitoring\Infrastructure\Workflow\WorkflowBindingApprovalAuthorizer;
use Kadupul\Tests\Monitoring\BindingFixture;
use Kadupul\Tests\Monitoring\Fakes\FakeSnmpCapabilityProvider;
use Kadupul\Tests\Monitoring\Fakes\RecordingAuditTrail;
use Kadupul\Tests\Monitoring\RegistryFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Dumper\MermaidDumper;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * The workflows are a second statement of rules the domain already enforces.
 * These tests compare them with the domain for every pair of states, so a rule
 * changed on one side only fails here, and they boot the real container to
 * check the authorizer is the one wired.
 */
final class ModelDrivenWorkflowTest extends TestCase
{
    private Kernel $kernel;

    private ContainerInterface $services;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        $this->services = $this->kernel->getContainer()->get('test.service_container');
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    public static function bindingStatePairs(): iterable
    {
        foreach (BindingStatus::cases() as $from) {
            foreach (BindingStatus::cases() as $to) {
                yield $from->value . ' to ' . $to->value => [$from, $to];
            }
        }
    }

    #[DataProvider('bindingStatePairs')]
    public function testTheBindingWorkflowAllowsExactlyTheMovesTheDomainAllows(BindingStatus $from, BindingStatus $to): void
    {
        self::assertSame($from->canMoveTo($to), self::reachable($this->workflow('binding'), $from->value, $to->value));
    }

    public function testTheBindingWorkflowHasExactlyTheDomainStates(): void
    {
        self::assertEqualsCanonicalizing(
            array_map(static fn(BindingStatus $s): string => $s->value, BindingStatus::cases()),
            array_keys($this->workflow('binding')->getDefinition()->getPlaces()),
        );
        self::assertSame([BindingStatus::Proposed->value], $this->workflow('binding')->getDefinition()->getInitialPlaces());
    }

    public static function capabilityStatePairs(): iterable
    {
        foreach (CapabilityStatus::cases() as $from) {
            foreach (CapabilityStatus::cases() as $to) {
                if ($from !== $to) {
                    yield $from->value . ' to ' . $to->value => [$from, $to];
                }
            }
        }
    }

    /**
     * What rediscovery would store: seed the stored state, let a provider
     * report the new one, and see whether the report took effect.
     */
    #[DataProvider('capabilityStatePairs')]
    public function testTheCapabilityWorkflowAllowsExactlyTheChangesDiscoveryStores(CapabilityStatus $from, CapabilityStatus $to): void
    {
        $model = new ModelReference('snmp-mib', RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV);
        $store = new InMemoryDeviceCapabilityStore();
        $store->replace(42, [new Capability($model, 'snmp', $from)]);
        $provider = new FakeSnmpCapabilityProvider();
        $provider->report(42, RegistryFixture::IF_MIB_NS, 'IF-MIB', RegistryFixture::IF_MIB_REV, $to);

        (new DiscoverDeviceCapabilities([$provider], $store, RegistryFixture::catalog()))(42);

        $stored = $store->capabilitiesFor(42);
        self::assertCount(1, $stored);
        self::assertSame(
            $stored[0]->status === $to,
            self::reachable($this->workflow('capability'), $from->value, $to->value),
            sprintf('discovery %s the move from %s to %s', $stored[0]->status === $to ? 'stores' : 'refuses', $from->value, $to->value),
        );
    }

    public function testTheCapabilityWorkflowHasExactlyTheDomainStates(): void
    {
        self::assertEqualsCanonicalizing(
            array_map(static fn(CapabilityStatus $s): string => $s->value, CapabilityStatus::cases()),
            array_keys($this->workflow('capability')->getDefinition()->getPlaces()),
        );
        self::assertSame([CapabilityStatus::Advertised->value], $this->workflow('capability')->getDefinition()->getInitialPlaces());
    }

    public function testApprovalIsDecidedByTheWorkflowGuardAndStillDeniedWithoutASignedInActor(): void
    {
        self::assertInstanceOf(WorkflowBindingApprovalAuthorizer::class, $this->services->get('test.model_driven.binding_authorizer'));
        $dispatcher = $this->kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $guards = array_filter(
            $dispatcher->getListeners('workflow.metric_binding.guard.approve'),
            static fn(mixed $listener): bool => is_array($listener) && $listener[0] instanceof BindingApprovalGuard,
        );
        self::assertCount(1, $guards);

        $approve = $this->services->get('test.model_driven.approve_binding');
        self::assertInstanceOf(ApproveBinding::class, $approve);
        try {
            $approve->approve(BindingFixture::key(), '1');
            self::fail('Approval without a signed-in actor must be denied.');
        } catch (BindingApprovalDenied $denied) {
            self::assertStringContainsString('not authorized', $denied->getMessage());
        }

        $audit = $this->services->get('test.model_driven.audit_trail');
        self::assertInstanceOf(RecordingAuditTrail::class, $audit);
        self::assertCount(1, $audit->events);
        self::assertSame('denied', $audit->events[0]->decision);
    }

    public function testTheRrdWriteGateStaysClosed(): void
    {
        self::assertFalse($this->kernel->getContainer()->getParameter('kadupul.monitoring.rrd_writes_enabled'));
    }

    public function testTheDocumentedDiagramsMatchTheWorkflows(): void
    {
        $document = file_get_contents(dirname(__DIR__, 2) . '/docs/model-driven-monitoring-workflows.md');
        self::assertIsString($document);
        $dumper = new MermaidDumper(MermaidDumper::TRANSITION_TYPE_STATEMACHINE);
        foreach (['capability', 'metric_binding'] as $name) {
            $workflow = $this->services->get($name === 'capability' ? 'test.model_driven.capability_workflow' : 'test.model_driven.binding_workflow');
            self::assertInstanceOf(WorkflowInterface::class, $workflow);
            self::assertStringContainsString(
                trim($dumper->dump($workflow->getDefinition())),
                $document,
                sprintf('docs/model-driven-monitoring-workflows.md is out of date for %s; regenerate it with bin/console workflow:dump.', $name),
            );
        }
    }

    private function workflow(string $which): WorkflowInterface
    {
        $workflow = $this->services->get($which === 'binding' ? 'test.model_driven.binding_workflow' : 'test.model_driven.capability_workflow');
        self::assertInstanceOf(WorkflowInterface::class, $workflow);

        return $workflow;
    }

    private static function reachable(WorkflowInterface $workflow, string $from, string $to): bool
    {
        foreach ($workflow->getDefinition()->getTransitions() as $transition) {
            if (in_array($from, $transition->getFroms(), true) && in_array($to, $transition->getTos(), true)) {
                return true;
            }
        }

        return false;
    }
}
