<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;

// The migrated read-only routes may touch expiry or revoke an identity, but
// cannot create credentials or overwrite the legacy session payload.
final readonly class ReadOnlyDatabaseSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    public function __construct(private DatabaseConnection $database) {}
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
        $query = $this->database->get()->prepare('SELECT data FROM sessions WHERE id = ? AND access >= ?');
        $query->execute([$id, time() - (int) ini_get('session.gc_maxlifetime')]);
        $data = $query->fetchColumn();
        return $data === false ? '' : (string) $data;
    }
    public function write(string $id, string $data): bool
    {
        return $this->updateTimestamp($id, $data);
    }
    public function destroy(string $id): bool
    {
        return $this->database->get()->prepare('DELETE FROM sessions WHERE id = ?')->execute([$id]);
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
        return $this->database->get()->prepare('UPDATE sessions SET access = ? WHERE id = ?')->execute([time(), $id]);
    }
}
