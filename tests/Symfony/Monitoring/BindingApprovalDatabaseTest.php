<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Monitoring;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\BindingApprovalPermission;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Monitoring\Application\ApproveBinding;
use Kadupul\Monitoring\Domain\BindingApprovalDenied;
use Kadupul\Monitoring\Domain\BindingKey;
use Kadupul\Monitoring\Domain\BindingStatus;
use Kadupul\Monitoring\Infrastructure\Persistence\DbalBindingRepository;
use Kadupul\Monitoring\Infrastructure\Workflow\BindingApprovalGuard;
use Kadupul\Monitoring\Infrastructure\Workflow\StatusMarkingStore;
use Kadupul\Monitoring\Infrastructure\Workflow\WorkflowBindingApprovalAuthorizer;
use Kadupul\Tests\Monitoring\Fakes\RecordingAuditTrail;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Workflow\Definition;
use Symfony\Component\Workflow\StateMachine;
use Symfony\Component\Workflow\Transition;

/**
 * The real use case, store and state machine against MariaDB or MySQL on a
 * table created from cacti.sql. Skips without a server, like the store tests.
 */
final class BindingApprovalDatabaseTest extends TestCase
{
    private Connection $database;

    private RecordingAuditTrail $audit;

    protected function setUp(): void
    {
        $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN to a task-owned MariaDB or MySQL database; these approval tests were not run.');
        }
        $parameters = ['driver' => 'pdo_mysql', 'user' => getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', 'password' => getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', 'charset' => 'utf8mb4'];
        foreach (explode(';', (string) preg_replace('/^mysql:/', '', $dsn)) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $mapped = ['host' => 'host', 'port' => 'port', 'dbname' => 'dbname', 'unix_socket' => 'unix_socket'][$name] ?? null;
            if ($mapped !== null) {
                $parameters[$mapped] = $mapped === 'port' ? (int) $value : $value;
            }
        }
        $this->database = DriverManager::getConnection($parameters);
        $this->database->executeStatement('DROP TABLE IF EXISTS kadupul_metric_binding');
        $sql = file_get_contents(dirname(__DIR__, 3) . '/cacti.sql');
        self::assertIsString($sql);
        self::assertSame(1, preg_match('/^CREATE TABLE kadupul_metric_binding \(.*?^\) [^;\n]*;/ms', $sql, $create));
        $this->database->executeStatement(rtrim($create[0], ';'));
        $this->audit = new RecordingAuditTrail();
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->executeStatement('DROP TABLE IF EXISTS kadupul_metric_binding');
            $this->database->close();
        }
    }

    /**
     * @return iterable<string, array{?Actor, bool, string}>
     */
    public static function refusals(): iterable
    {
        yield 'signed in without the permission' => [new Actor(7, 'operator'), false, '7'];
        yield 'nobody signed in' => [null, true, '7'];
        yield 'permitted actor named by someone else' => [new Actor(7, 'operator'), true, '8'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testADeniedApprovalLeavesTheStoredBindingUnchanged(?Actor $actor, bool $permitted, string $claimed): void
    {
        $key = BindingFixture::key();
        $repository = new DbalBindingRepository($this->database);
        $repository->save(BindingFixture::binding($key));
        $before = $this->row();

        try {
            $this->approve($actor, $permitted)->approve($key, $claimed);
            self::fail('The approval was not denied.');
        } catch (BindingApprovalDenied) {
        }

        self::assertSame($before, $this->row());
        self::assertSame(BindingStatus::Proposed, $repository->matching($key)[0]->status);
        self::assertCount(1, $this->audit->events);
        self::assertSame('denied', $this->audit->events[0]->decision);
    }

    public function testAnAllowedApprovalStoresTheNewStatusAndIsAudited(): void
    {
        $key = BindingFixture::key();
        $repository = new DbalBindingRepository($this->database);
        $repository->save(BindingFixture::binding($key));

        $this->approve(new Actor(7, 'operator'), true)->approve($key, '7');

        self::assertSame(BindingStatus::Approved, $repository->matching($key)[0]->status);
        self::assertCount(1, $this->audit->events);
        self::assertSame('allowed', $this->audit->events[0]->decision);
    }

    private function approve(?Actor $actor, bool $permitted): ApproveBinding
    {
        $access = new class ($actor) implements ConsoleAccess {
            public function __construct(private ?Actor $actor) {}

            public function consoleActor(): ?Actor
            {
                return $this->actor;
            }

            public function canManageDevices(Actor $actor): bool
            {
                return true;
            }
        };
        $permission = new class ($permitted) implements BindingApprovalPermission {
            public function __construct(private bool $permitted) {}

            public function mayApproveBindings(Actor $actor): bool
            {
                return $this->permitted;
            }
        };
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener('workflow.metric_binding.guard.approve', new BindingApprovalGuard($access, $permission, $this->audit));
        $workflow = new StateMachine(
            new Definition(['proposed', 'approved'], [new Transition('approve', 'proposed', 'approved')], 'proposed'),
            new StatusMarkingStore(),
            $dispatcher,
            'metric_binding',
        );

        return new ApproveBinding(new DbalBindingRepository($this->database), new WorkflowBindingApprovalAuthorizer($workflow));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        $row = $this->database->fetchAssociative('SELECT * FROM kadupul_metric_binding');
        self::assertIsArray($row);

        return $row;
    }
}
