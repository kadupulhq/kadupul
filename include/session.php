<?php

declare(strict_types=1);
/*
  +-------------------------------------------------------------------------+
  | Copyright (C) 2004-2026 The Cacti Group                                 |
  | Copyright (C) 2026 The Kadupul project and contributors                 |
  |                                                                         |
  | This program is free software; you can redistribute it and/or           |
  | modify it under the terms of the GNU General Public License             |
  | as published by the Free Software Foundation; either version 2          |
  | of the License, or (at your option) any later version.                  |
  |                                                                         |
  | This program is distributed in the hope that it will be useful,         |
  | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
  | GNU General Public License for more details.                            |
  +-------------------------------------------------------------------------+
  | Cacti: The Complete RRDTool-based Graphing Solution                     |
  +-------------------------------------------------------------------------+
  | This code is designed, written, and maintained by the Cacti Group. See  |
  | about.php and/or the AUTHORS file for specific developer information.   |
  +-------------------------------------------------------------------------+
  | http://www.cacti.net/                                                   |
  +-------------------------------------------------------------------------+
*/

/*
 * Have Cacti use the database for PHP session storage.
 * This allows for easier distribution of Web UI.
 */

require_once __DIR__ . '/../lib/session_store.php';

// Don't run from the database if using the command line
if (php_sapi_name() == 'cli') {
	return;
}

function cacti_db_session_context(): array|false {
    global $database_sessions, $database_hostname, $database_port, $database_default;
    if (!is_array($database_sessions) || !is_string($database_hostname) || !is_string($database_default)
        || (!is_string($database_port) && !is_int($database_port))) return false;
    $key = "$database_hostname:$database_port:$database_default";
    $database = $database_sessions[$key] ?? null;
    return $database instanceof PDO ? ['database' => $database, 'schema' => $database_default, 'key' => $key] : false;
}

function cacti_db_session_backend(bool $create = true, bool $fresh = false): CactiSessionStore|false {
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

function cacti_db_session_write_confirmed(string $id): bool {
    $backend = cacti_db_session_backend(false);
    return $backend !== false && $backend->writeConfirmed($id);
}

function cacti_db_session_check(): bool {
    $backend = cacti_db_session_backend();
    return $backend !== false && $backend->ensureColumns();
}

function cacti_db_session_open(mixed $savePath = '', mixed $sessionName = ''): bool {
    $backend = cacti_db_session_backend(true, true);
    return $backend !== false && $backend->open();
}

function cacti_db_session_close(): bool {
    $backend = cacti_db_session_backend(false);
    return $backend === false || $backend->close();
}

function cacti_db_session_read(mixed $id): string|false {
    $backend = cacti_db_session_backend();
    return is_string($id) && $backend !== false ? $backend->read($id) : false;
}

/**
 * Keep the newer sess_last_activity when a slow request writes an older one.
 *
 * Database sessions have no request lock, so two requests for one session can
 * finish out of order. The idle check reads this value, and an older write
 * would expire a session whose newer request was still inside the limit.
 *
 * @param  (string) $stored The session blob already stored
 * @param  (string) $data   The blob this request is about to store
 *
 * @return (string) $data, with sess_last_activity raised when the stored one is later
 */
function cacti_db_session_monotonic_activity(#[SensitiveParameter] mixed $stored, #[SensitiveParameter] mixed $data): string|false {
    if (!is_string($stored) || !is_string($data)) return false;
    $serializer = ini_get('session.serialize_handler');
    return is_string($serializer) ? CactiSessionData::monotonic($stored, $data, $serializer) : false;
}

function cacti_db_session_write(mixed $id, #[SensitiveParameter] mixed $data): bool {
    if (!is_string($id) || !is_string($data)) return false;
    $backend = cacti_db_session_backend();
    if ($backend === false) return false;
    $address = get_client_addr();
    if ($address === false) $address = '';
    if (!is_string($address)) return false;
    $agent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown';
    return $backend->write($id, $data, $address, $agent);
}

function cacti_db_session_destroy(mixed $id): bool {
    $backend = cacti_db_session_backend();
    return is_string($id) && $backend !== false && $backend->destroy($id);
}

function cacti_db_session_clean(mixed $max): int|false {
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

