<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Validation;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Form/HTTP submission DTO validated at the Symfony boundary before reaching
 * AuthenticateLocalUser or AuthenticateDirectoryUser.
 */
#[Exclude]
final readonly class LoginCredentialsInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public string $username,
        #[Assert\NotBlank]
        #[Assert\Length(max: 4096)]
        #[\SensitiveParameter]
        public string $password,
        #[Assert\PositiveOrZero]
        public int $domainId = 0,
    ) {}
}
