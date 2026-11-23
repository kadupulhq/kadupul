<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Native close must confirm handler I/O, not just an active session status. */
final class BrowserWorkerSession
{
    public static function close(): void
    {
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$previous): bool {
            // PHP can return true after a handler write failed with a warning.
            // Never expose handler messages or serialized session data.
            if (in_array($severity, [E_WARNING, E_USER_WARNING], true)) {
                throw new \RuntimeException('Native session handler write could not be confirmed.');
            }
            return $previous !== null && $previous($severity, $message, $file, $line) !== false;
        });
        try {
            // The native return only distinguishes an inactive session; a
            // handler write failure can still return true. Check the actual
            // lifecycle on both sides and retain the I/O warning guard above.
            if (self::status() !== PHP_SESSION_ACTIVE) {
                throw new \RuntimeException('Native session close could not be confirmed.');
            }
            session_write_close();
            if (self::status() !== PHP_SESSION_NONE) {
                throw new \RuntimeException('Native session handler remained active after close.');
            }
        } catch (\Throwable $error) {
            try {
                if (self::status() === PHP_SESSION_ACTIVE) {
                    session_abort();
                }
            } finally {
                // Cleanup must never replace the original persistence failure.
                throw $error;
            }
        } finally {
            restore_error_handler();
        }
    }

    /** Native handler callbacks mutate this state outside PHPStan's function model.
     * @phpstan-impure
     */
    private static function status(): int
    {
        return session_status();
    }

}
