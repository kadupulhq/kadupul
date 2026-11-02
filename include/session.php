<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Have Kadupul use the database for PHP session storage.
 * This allows for easier distribution of Web UI.
 */

require_once dirname(__DIR__) . '/lib/session_store.php';
require_once dirname(__DIR__) . '/src/Platform/Infrastructure/Legacy/LegacyConnectionRegistry.php';

/** @param (Closure(): (array{database:PDO,schema:string,key:string}|false))|null $selected */
function cacti_db_session_context(?Closure $selected = null): array|false
{
    static $selection = null;
    if ($selected !== null) {
        $selection = $selected;
        return false;
    }
    return $selection === null ? false : $selection();
}

// Resolve through the same live registry as the native database helpers. The
// callback stays deferred and works when bootstrap is included in another scope.
cacti_db_session_context(static function (): array|false {
    [$hostname, $port, $schema] = \Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry::defaultTarget();
    if (!is_string($hostname) || !is_string($schema) || (!is_string($port) && !is_int($port))) return false;
    $key = "$hostname:$port:$schema";
    if (!\Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry::hasSession($key)) return false;
    $database = \Kadupul\Platform\Infrastructure\Legacy\LegacyConnectionRegistry::session($key);
    return $database instanceof PDO ? ['database' => $database, 'schema' => $schema, 'key' => $key] : false;
});

function cacti_db_session_backend(bool $create = true, bool $fresh = false): CactiSessionStore|false
{
    static $backend = false;
    // PHP has one active native session. Keep its original connection bound
    // through close; a changed collector role must not select another store.
    if (!$fresh && ($backend !== false || !$create)) return $backend;
    $backend = false;
    $context = cacti_db_session_context();
    if ($context === false) return false;
    $serializer = ini_get('session.serialize_handler');
    if (!is_string($serializer)) return false;
    $identity = $context['key'];
    $database = $context['database'];
    $selected = static function () use ($identity, $database): PDO|false {
        $current = cacti_db_session_context();
        return $current !== false && $current['key'] === $identity && $current['database'] === $database ? $database : false;
    };
    $backend = new CactiSessionStore($database, $context['schema'], $serializer, $selected);
    return $backend;
}

function cacti_db_session_write_confirmed(string $id): bool
{
    $backend = cacti_db_session_backend(false);
    return $backend !== false && $backend->writeConfirmed($id);
}

function cacti_db_session_monotonic_activity(#[SensitiveParameter] mixed $stored, #[SensitiveParameter] mixed $data): string|false
{
    if (!is_string($stored) || !is_string($data)) return false;
    $serializer = ini_get('session.serialize_handler');
    return is_string($serializer) ? CactiSessionData::monotonic($stored, $data, $serializer) : false;
}

// Don't run from the database if using the command line
if (php_sapi_name() == 'cli' && !defined('KADUPUL_BROWSER_COMPATIBILITY')) {
    return;
}

function cacti_db_session_check(): bool
{
    $backend = cacti_db_session_backend();
    return $backend !== false && $backend->ensureColumns();
}

function cacti_db_session_open(mixed $savePath = '', mixed $sessionName = ''): bool
{
    $backend = cacti_db_session_backend(true, true);
    return $backend !== false && $backend->open();
}

function cacti_db_session_close(): bool
{
    $backend = cacti_db_session_backend(false);
    return $backend === false || $backend->close();
}

function cacti_db_session_read(mixed $id): string|false
{
    $backend = cacti_db_session_backend();
    return is_string($id) && $backend !== false ? $backend->read($id) : false;
}

function cacti_db_session_write(mixed $id, #[SensitiveParameter] mixed $data): bool
{
    if (!is_string($id) || !is_string($data)) return false;
    $backend = cacti_db_session_backend();
    if ($backend === false) return false;
    $address = get_client_addr();
    // The native address helper uses false for unavailable display metadata.
    if ($address === false) $address = '';
    if (!is_string($address)) return false;
    $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    return $backend->write($id, $data, $address, $agent);
}

function cacti_db_session_destroy(mixed $id): bool
{
    $backend = cacti_db_session_backend();
    return is_string($id) && $backend !== false && $backend->destroy($id);
}

function cacti_db_session_clean(mixed $max): int|false
{
    $backend = cacti_db_session_backend();
    // Preserve the old callback API's successful integer acknowledgement.
    return is_int($max) && $backend !== false && $backend->clean($max) ? 1 : false;
}

// Object registration preserves the native callbacks without PHP 8.4's
// deprecated individual-callback registration API.
final class CactiDatabaseSessionHandler implements SessionHandlerInterface
{
    public function open(string $path, string $name): bool
    {
        return cacti_db_session_open($path, $name);
    }

    public function close(): bool
    {
        return cacti_db_session_close();
    }

    public function read(string $id): string|false
    {
        return cacti_db_session_read($id);
    }

    public function write(string $id, string $data): bool
    {
        return cacti_db_session_write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return cacti_db_session_destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        // The historical callback acknowledges cleanup with true, which the
        // callback API converted to integer 1. Retain that acknowledgement.
        return cacti_db_session_clean($max_lifetime);
    }
}

session_set_save_handler(new CactiDatabaseSessionHandler(), false);

register_shutdown_function('session_write_close');
