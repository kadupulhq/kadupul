<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Domain;

final readonly class AdministratorRecipient
{
    public function __construct(public string $email, public string $name) {}
}
