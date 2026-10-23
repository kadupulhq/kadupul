<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\ApproveBinding;
use Kadupul\Monitoring\Domain\BindingApprovalDenied;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Domain\BindingTransitionRejected;
use Kadupul\Monitoring\Domain\WriterOwnership;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\WriterOwnershipRegistry;
use Kadupul\Tests\Monitoring\Fakes\FakeBindingApprovalAuthorizer;
use Kadupul\Tests\Monitoring\Fakes\FixedWriterOwnershipProbe;
use PHPUnit\Framework\TestCase;

final class ApproveBindingTest extends TestCase
{
    private InMemoryBindingRepository $bindings;

    protected function setUp(): void
    {
        $this->bindings = new InMemoryBindingRepository();
        $this->bindings->save(BindingFixture::binding());
    }

    public function testWithoutAnAuthorizerNothingIsApproved(): void
    {
        $this->assertDenied(fn() => (new ApproveBinding($this->bindings))->approve(BindingFixture::key(), 'admin'), 'No binding approval authorizer');
    }

    public function testAnUnauthorizedOrAnonymousActorIsDenied(): void
    {
        $authorizer = new FakeBindingApprovalAuthorizer(['admin']);
        $approve = new ApproveBinding($this->bindings, $authorizer);

        $this->assertDenied(fn() => $approve->approve(BindingFixture::key(), 'guest'), 'guest is not authorized');
        $this->assertDenied(fn() => $approve->approve(BindingFixture::key(), ' '), 'actor id is required');
        self::assertSame(['guest ' . BindingFixture::key()->bindingId()], $authorizer->asked);
    }

    public function testAuthorizationIsCheckedBeforeTheStoreIsRead(): void
    {
        $approve = new ApproveBinding(new InMemoryBindingRepository(), new FakeBindingApprovalAuthorizer([]));

        // An unknown key must not be distinguishable from a denied one.
        $this->assertDenied(fn() => $approve->approve(BindingFixture::key(identity: 'nope'), 'guest'), 'not authorized');
    }

    public function testAnAuthorizedActorApprovesAProposedBinding(): void
    {
        $approved = (new ApproveBinding($this->bindings, new FakeBindingApprovalAuthorizer(['admin'])))->approve(BindingFixture::key(), 'admin');

        self::assertSame(BindingStatus::Approved, $approved->status);
        self::assertSame($approved, $this->bindings->matching(BindingFixture::key())[0]);
    }

    public function testApprovingAnUnknownOrRejectedBindingFails(): void
    {
        $approve = new ApproveBinding($this->bindings, new FakeBindingApprovalAuthorizer(['admin']));
        try {
            $approve->approve(BindingFixture::key(identity: 'eth9'), 'admin');
            self::fail('Approved a binding that does not exist');
        } catch (BindingTransitionRejected $e) {
            self::assertStringContainsString('No binding', $e->getMessage());
        }
        $this->bindings->save(BindingFixture::binding()->withStatus(BindingStatus::Rejected));

        $this->expectException(BindingTransitionRejected::class);
        $approve->approve(BindingFixture::key(), 'admin');
    }

    public function testApprovalNeverChangesWriterOwnership(): void
    {
        $registry = new WriterOwnershipRegistry(new FixedWriterOwnershipProbe([BindingFixture::field()->toString() => WriterOwnership::LegacyPoller]));
        $before = $registry->ownership(BindingFixture::field());

        (new ApproveBinding($this->bindings, new FakeBindingApprovalAuthorizer(['admin'])))->approve(BindingFixture::key(), 'admin');

        self::assertSame(WriterOwnership::LegacyPoller, $before);
        self::assertSame($before, $registry->ownership(BindingFixture::field()));
        self::assertSame([], $registry->claims());
    }

    private function assertDenied(\Closure $approve, string $message): void
    {
        try {
            $approve();
            self::fail('Expected the approval to be denied.');
        } catch (BindingApprovalDenied $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
        self::assertSame(BindingStatus::Proposed, $this->bindings->matching(BindingFixture::key())[0]->status);
    }
}
