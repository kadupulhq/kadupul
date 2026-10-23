<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\ActivateCollectionPlan;
use Kadupul\Monitoring\Application\MonitoringPlanner;
use Kadupul\Monitoring\Domain\ActivationPolicy;
use Kadupul\Monitoring\Domain\CollectionPlan;
use Kadupul\Monitoring\Domain\PackState;
use Kadupul\Monitoring\Domain\PlanActivationDenied;
use Kadupul\Monitoring\Domain\PlanStatus;
use Kadupul\Monitoring\Domain\PlanTransitionRejected;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryCollectionPlanStore;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryPackRepository;
use Kadupul\Tests\Monitoring\Fakes\FakePlanActivationAuthorizer;
use Kadupul\Tests\Monitoring\Fakes\RecordingExistingMonitoring;
use PHPUnit\Framework\TestCase;

final class ActivateCollectionPlanTest extends TestCase
{
    private InMemoryCollectionPlanStore $plans;
    private InMemoryPackRepository $packs;
    private CollectionPlan $proposed;

    protected function setUp(): void
    {
        $this->plans = new InMemoryCollectionPlanStore();
        $this->packs = new InMemoryPackRepository();
        $this->packs->save(PlanningFixture::pack());
        $this->proposed = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request([PlanningFixture::openConfig()]))->plans[0];
    }

    public function testTheDefaultPolicyDeniesEvenAnAuthorizedActor(): void
    {
        $authorizer = new FakePlanActivationAuthorizer(['admin']);
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, new ActivationPolicy(), $authorizer);

        $this->assertDenied(fn() => $activation->approve($this->proposed, 'admin'), 'activation policy');
        self::assertSame([], $authorizer->asked);
        self::assertSame([], $this->plans->forDevice(42));
    }

    public function testWithoutAnAuthorizerNothingIsApproved(): void
    {
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, ActivationPolicy::allowApproved());

        $this->assertDenied(fn() => $activation->approve($this->proposed, 'admin'), 'No plan activation authorizer');
        self::assertSame([], $this->plans->forDevice(42));
    }

    public function testAnUnauthorizedOrAnonymousActorIsDenied(): void
    {
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, ActivationPolicy::allowApproved(), new FakePlanActivationAuthorizer(['admin']));

        $this->assertDenied(fn() => $activation->approve($this->proposed, 'guest'), 'guest is not authorized');
        $this->assertDenied(fn() => $activation->approve($this->proposed, ' '), 'actor');
        self::assertSame([], $this->plans->forDevice(42));
    }

    public function testAnAuthorizedActorApprovesThenActivatesAndThePackBecomesActive(): void
    {
        $authorizer = new FakePlanActivationAuthorizer(['admin']);
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, ActivationPolicy::allowApproved(), $authorizer);

        $approved = $activation->approve($this->proposed, 'admin');
        self::assertSame(PlanStatus::Approved, $approved->status);
        self::assertSame(PackState::Installed, $this->packs->find('openconfig-interfaces')?->state);

        $active = $activation->activate($approved->planId, 'admin');

        self::assertSame(PlanStatus::Active, $active->status);
        self::assertSame(PlanStatus::Active, $this->plans->find($approved->planId)?->status);
        self::assertSame(PackState::Active, $this->packs->find('openconfig-interfaces')?->state);
        self::assertSame(['admin approved ' . $approved->planId, 'admin active ' . $approved->planId], $authorizer->asked);
    }

    public function testActivatingANewerPlanSupersedesTheActiveOne(): void
    {
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, ActivationPolicy::allowApproved(), new FakePlanActivationAuthorizer(['admin']));
        $first = $activation->activate($activation->approve($this->proposed, 'admin')->planId, 'admin');
        $replacement = (new MonitoringPlanner(new RecordingExistingMonitoring()))->plan(PlanningFixture::request([PlanningFixture::ifMib()]))->plans[0];

        $activation->activate($activation->approve($replacement, 'admin')->planId, 'admin');

        self::assertSame(PlanStatus::Superseded, $this->plans->find($first->planId)?->status);
        self::assertSame(PlanStatus::Active, $this->plans->find($replacement->planId)?->status);
    }

    public function testAPlanCanOnlyMoveForwardOneStepAtATime(): void
    {
        $activation = new ActivateCollectionPlan($this->plans, $this->packs, ActivationPolicy::allowApproved(), new FakePlanActivationAuthorizer(['admin']));

        try {
            $activation->activate($this->proposed->planId, 'admin');
            self::fail('A plan that was never approved was activated.');
        } catch (PlanTransitionRejected $e) {
            self::assertStringContainsString('approved', $e->getMessage());
        }
        $approved = $activation->approve($this->proposed, 'admin');
        $this->expectException(PlanTransitionRejected::class);
        $activation->approve($approved, 'admin');
    }

    public function testStatusTransitionsOutsideTheLifecycleAreRejected(): void
    {
        $this->expectException(PlanTransitionRejected::class);
        $this->expectExceptionMessage('proposed to active');

        $this->proposed->withStatus(PlanStatus::Active);
    }

    private function assertDenied(\Closure $action, string $reason): void
    {
        try {
            $action();
            self::fail('The action was allowed.');
        } catch (PlanActivationDenied $e) {
            self::assertStringContainsString($reason, $e->getMessage());
        }
    }
}
