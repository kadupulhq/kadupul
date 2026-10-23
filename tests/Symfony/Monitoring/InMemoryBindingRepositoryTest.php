<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Monitoring;

use Kadupul\Monitoring\Application\Port\BindingRepository;
use Kadupul\Monitoring\Infrastructure\Persistence\InMemoryBindingRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryBindingRepositoryTest extends TestCase
{
    use BindingRepositoryContract;

    protected function repository(): BindingRepository
    {
        return new InMemoryBindingRepository();
    }
}
