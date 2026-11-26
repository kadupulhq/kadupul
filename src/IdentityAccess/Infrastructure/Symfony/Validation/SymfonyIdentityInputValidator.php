<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Validation;

use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Thin Symfony Validator boundary returning field-keyed validation messages for
 * IdentityAccess HTTP/Form submission DTOs.
 */
final readonly class SymfonyIdentityInputValidator
{
    public function __construct(
        private ValidatorInterface $validator,
    ) {}

    /** @return array<string, list<string>> */
    public function validate(LoginCredentialsInput|PasswordChangeInput $input): array
    {
        $violations = $this->validator->validate($input);
        $errors = [];
        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath();
            $errors[$field][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
