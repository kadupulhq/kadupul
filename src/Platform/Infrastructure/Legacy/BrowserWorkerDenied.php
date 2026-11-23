<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * A browser worker finished with a denied outcome instead of a result.
 *
 * Gateways of workers that opt into realm denials must catch this explicitly
 * and render the access error; it is never a successful envelope.
 */
final class BrowserWorkerDenied extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The browser worker denied the request.');
    }
}
