<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

/** Confirm the native file handler's write result before granting an identity. */
final class AuthenticationFileSessionHandler extends \SessionHandler implements \SessionUpdateTimestampHandlerInterface
{
    public bool $written = false;

    public function write(string $id, string $data): bool
    {
        return $this->written = parent::write($id, $data);
    }

    public function validateId(string $id): bool
    {
        return parent::read($id) !== '';
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
