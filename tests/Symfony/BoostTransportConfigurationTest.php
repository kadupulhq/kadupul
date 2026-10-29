<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests;

use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The Doctrine transport connects to the installation database when it is
 * built, so the default DSN is read from the compiled definition instead.
 */
final class BoostTransportConfigurationTest extends TestCase
{
    private const DSN_VARIABLE = 'KADUPUL_BOOST_TRANSPORT_DSN';

    private ?Kernel $kernel = null;
    private string|false $previousGetenv = false;
    private bool $hadEnv = false;
    private mixed $previousEnv = null;
    private bool $hadServer = false;
    private mixed $previousServer = null;

    protected function setUp(): void
    {
        $this->previousGetenv = getenv(self::DSN_VARIABLE);
        $this->hadEnv = array_key_exists(self::DSN_VARIABLE, $_ENV);
        $this->previousEnv = $_ENV[self::DSN_VARIABLE] ?? null;
        $this->hadServer = array_key_exists(self::DSN_VARIABLE, $_SERVER);
        $this->previousServer = $_SERVER[self::DSN_VARIABLE] ?? null;
        $this->setDsn(null);
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        if (false === $this->previousGetenv) {
            putenv(self::DSN_VARIABLE);
        } else {
            putenv(self::DSN_VARIABLE . '=' . $this->previousGetenv);
        }
        if ($this->hadEnv) {
            $_ENV[self::DSN_VARIABLE] = $this->previousEnv;
        } else {
            unset($_ENV[self::DSN_VARIABLE]);
        }
        if ($this->hadServer) {
            $_SERVER[self::DSN_VARIABLE] = $this->previousServer;
        } else {
            unset($_SERVER[self::DSN_VARIABLE]);
        }
    }

    public function testBoostTransportDefaultsToDoctrineAndHonoursDsn(): void
    {
        $container = $this->compiledContainer();

        $dsn = $container->resolveEnvPlaceholders(
            $container->getDefinition('messenger.transport.boost')->getArguments()[0],
            true,
        );
        self::assertSame(
            'doctrine://local?queue_name=boost&table_name=kadupul_messenger_messages&auto_setup=false&redeliver_timeout=15600',
            $dsn,
        );

        $this->setDsn('in-memory://');
        $this->kernel = new Kernel('test', true);
        $this->kernel->boot();
        self::assertInstanceOf(
            InMemoryTransport::class,
            $this->kernel->getContainer()->get('test.service_container')->get('messenger.transport.boost'),
        );
    }

    public function testBoostTransportDoesNotRetry(): void
    {
        $retries = $this->compiledContainer()
            ->getDefinition('messenger.retry.multiplier_retry_strategy.boost')
            ->getArguments()[0];

        self::assertSame(0, $retries);
    }

    private function compiledContainer(): ContainerBuilder
    {
        $kernel = new Kernel('test', true);
        (new \ReflectionMethod($kernel, 'initializeBundles'))->invoke($kernel);
        $container = (new \ReflectionMethod($kernel, 'buildContainer'))->invoke($kernel);
        $container->compile();

        return $container;
    }

    private function setDsn(?string $dsn): void
    {
        if (null === $dsn) {
            putenv(self::DSN_VARIABLE);
            unset($_ENV[self::DSN_VARIABLE], $_SERVER[self::DSN_VARIABLE]);

            return;
        }
        putenv(self::DSN_VARIABLE . '=' . $dsn);
        $_ENV[self::DSN_VARIABLE] = $dsn;
        $_SERVER[self::DSN_VARIABLE] = $dsn;
    }
}
