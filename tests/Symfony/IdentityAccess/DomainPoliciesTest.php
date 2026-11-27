<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\IdentityAccess;

use Kadupul\IdentityAccess\Domain\GraphAuthMode;
use Kadupul\IdentityAccess\Domain\PasswordComplexity;
use Kadupul\IdentityAccess\Domain\Policy\DeviceVisibilityPolicy;
use Kadupul\IdentityAccess\Domain\Policy\GraphVisibilityPolicy;
use Kadupul\IdentityAccess\Domain\Policy\LockoutPolicy;
use Kadupul\IdentityAccess\Domain\Policy\PasswordComplexityPolicy;
use Kadupul\IdentityAccess\Domain\Policy\PrincipalVisibilityProfile;
use Kadupul\IdentityAccess\Domain\Policy\TreeVisibilityPolicy;
use Kadupul\IdentityAccess\Domain\Policy\VisibilityRuleSet;
use Kadupul\IdentityAccess\Domain\VisibilityDefault;
use PHPUnit\Framework\TestCase;

final class DomainPoliciesTest extends TestCase
{
    public function testDeviceVisibilityEnforcesExceptionsAndAuthModes(): void
    {
        $policy = new DeviceVisibilityPolicy();
        $allowExceptDevice5 = new PrincipalVisibilityProfile(
            graphs: new VisibilityRuleSet(VisibilityDefault::Deny),
            devices: new VisibilityRuleSet(VisibilityDefault::Allow, [5]),
            trees: new VisibilityRuleSet(VisibilityDefault::Allow),
            graphTemplates: new VisibilityRuleSet(VisibilityDefault::Allow),
        );

        // Explicit device exception is never bypassed in DeviceOnly mode.
        $this->assertFalse($policy->canViewDevice(5, GraphAuthMode::DeviceOnly, [$allowExceptDevice5]));
        $this->assertTrue($policy->canViewDevice(6, GraphAuthMode::DeviceOnly, [$allowExceptDevice5]));

        // In Restrictive mode, device 6 with graph 10 on allowed template 2 is visible; device 5 is denied.
        $graphs = [['graphId' => 10, 'graphTemplateId' => 2]];
        $this->assertFalse($policy->canViewDevice(5, GraphAuthMode::Restrictive, [$allowExceptDevice5], $graphs));
        $this->assertTrue($policy->canViewDevice(6, GraphAuthMode::Restrictive, [$allowExceptDevice5], $graphs));

        // An enabled group profile granting device 5 makes it visible through union semantics.
        $groupGrantingDevice5 = new PrincipalVisibilityProfile(
            graphs: new VisibilityRuleSet(VisibilityDefault::Deny),
            devices: new VisibilityRuleSet(VisibilityDefault::Deny, [5]),
            trees: new VisibilityRuleSet(VisibilityDefault::Deny),
            graphTemplates: new VisibilityRuleSet(VisibilityDefault::Deny),
        );
        $this->assertTrue($policy->canViewDevice(5, GraphAuthMode::DeviceOnly, [$allowExceptDevice5, $groupGrantingDevice5]));
    }

    public function testGraphVisibilityAcrossAllFourModes(): void
    {
        $policy = new GraphVisibilityPolicy();
        $profile = new PrincipalVisibilityProfile(
            graphs: new VisibilityRuleSet(VisibilityDefault::Deny),
            devices: new VisibilityRuleSet(VisibilityDefault::Deny, [10]),
            trees: new VisibilityRuleSet(VisibilityDefault::Deny),
            graphTemplates: new VisibilityRuleSet(VisibilityDefault::Deny, [20]),
        );

        // Device 10 allowed, template 99 denied.
        $this->assertTrue($policy->canViewGraph(1, 10, 99, GraphAuthMode::Permissive, [$profile]));
        $this->assertFalse($policy->canViewGraph(1, 10, 99, GraphAuthMode::Restrictive, [$profile]));
        $this->assertTrue($policy->canViewGraph(1, 10, 99, GraphAuthMode::DeviceOnly, [$profile]));
        $this->assertFalse($policy->canViewGraph(1, 10, 99, GraphAuthMode::GraphTemplateOnly, [$profile]));

        // Device 10 AND template 20 both allowed passes Restrictive.
        $this->assertTrue($policy->canViewGraph(1, 10, 20, GraphAuthMode::Restrictive, [$profile]));
    }

    public function testTreeVisibilityAndOwnershipRules(): void
    {
        $policy = new TreeVisibilityPolicy();
        $denyExceptTree4 = new PrincipalVisibilityProfile(
            graphs: new VisibilityRuleSet(VisibilityDefault::Deny),
            devices: new VisibilityRuleSet(VisibilityDefault::Deny),
            trees: new VisibilityRuleSet(VisibilityDefault::Deny, [4]),
            graphTemplates: new VisibilityRuleSet(VisibilityDefault::Deny),
        );

        $this->assertTrue($policy->canViewTree(4, [$denyExceptTree4]));
        $this->assertFalse($policy->canViewTree(5, [$denyExceptTree4]));

        $this->assertTrue($policy->canManageTree(7, 99, true, false));
        $this->assertTrue($policy->canManageTree(7, 7, false, true));
        $this->assertFalse($policy->canManageTree(7, 99, false, true));
        $this->assertFalse($policy->canManageTree(0, 0, true, true));
    }

    public function testLockoutPolicyCapsWindowAndUsesStrictExpiry(): void
    {
        $disabled = new LockoutPolicy(0, 15);
        $this->assertFalse($disabled->isEnabled());
        $this->assertFalse($disabled->shouldLock(100));
        $this->assertFalse($disabled->hasLockoutExpired(0, 999999));

        $capped = new LockoutPolicy(3, 5000);
        $this->assertSame(1440, $capped->unlockMinutes);
        $this->assertSame(86400, $capped->unlockWindowSeconds());
        $this->assertFalse($capped->shouldLock(2));
        $this->assertTrue($capped->shouldLock(3));
        $this->assertFalse($capped->hasLockoutExpired(1000, 87400));
        $this->assertTrue($capped->hasLockoutExpired(1000, 87401));

        $manualUnlock = new LockoutPolicy(3, 0);
        $this->assertFalse($manualUnlock->hasLockoutExpired(0, 999999));
    }

    public function testPasswordComplexityPolicyReportsViolations(): void
    {
        $checker = new PasswordComplexity();
        $strict = new PasswordComplexityPolicy(true, 10, true, true, true);
        $this->assertSame(
            ['min_length', 'require_number', 'require_mixed_case', 'require_special'],
            $strict->violations('short', $checker),
        );
        $this->assertTrue($strict->isSatisfiedBy('ValidPass9!', $checker));

        $relaxed = new PasswordComplexityPolicy(false);
        $this->assertTrue($relaxed->isSatisfiedBy('a', $checker));
    }
}
