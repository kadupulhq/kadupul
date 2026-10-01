<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;

/** A verified authentication transition creates one fresh credential payload. */
final class AuthenticationDatabaseSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public bool $written = false;

    public function __construct(private readonly DatabaseConnection $database, private readonly string $ip = '', private readonly string $agent = '') {}

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $data = BrowserAuthenticationSql::column(BrowserAuthenticationSql::execute($this->database->get(), 'SELECT data FROM sessions WHERE id = ? AND access >= ?', [$id, time() - (int) ini_get('session.gc_maxlifetime')]));
        return $data === false ? '' : (string) $data;
    }

    public function write(string $id, string $data): bool
    {
        $this->written = false;
        $user = $_SESSION['sess_user_id'] ?? null;
        if (!is_int($user) || $user <= 0 || strlen($id) > 32) {
            throw new \RuntimeException('Invalid established authentication session.');
        }
        // Only the newly generated ID is written: never upsert another payload.
        $statement = BrowserAuthenticationSql::execute($this->database->get(), 'INSERT INTO sessions (id, remote_addr, access, data, user_id, user_agent) VALUES (?, ?, ?, ?, ?, ?)', [$id, substr($this->ip, 0, 25), time(), $data, $user, substr($this->agent, 0, 128)]);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Authentication session insertion was not confirmed.');
        }
        return $this->written = true;
    }

    public function destroy(string $id): bool
    {
        BrowserAuthenticationSql::execute($this->database->get(), 'DELETE FROM sessions WHERE id = ?', [$id]);
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        return 0;
    }

    public function validateId(string $id): bool
    {
        return $this->read($id) !== '';
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->write($id, $data);
    }
}
