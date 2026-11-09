<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

/** Bounded session display metadata, never an authentication identity. */
final class CactiSessionMetadata
{
    /** @return array{address:string,agent:string}|false */
    public static function forStorage(string $address, string $agent): array|false
    {
        // The shipped address column has 25 characters. Authentication and
        // throttling continue to use their original full request address.
        if (preg_match('/\A.{0,128}/us', $agent, $match) !== 1) return false;
        return ['address' => substr($address, 0, 25), 'agent' => $match[0]];
    }
}
