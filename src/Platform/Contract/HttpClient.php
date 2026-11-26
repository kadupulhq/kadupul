<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Contract;

/**
 * Outbound HTTP client contract enforcing TLS verification, bounded timeouts,
 * and outbound URL policy.
 */
interface HttpClient
{
    /** @throws HttpClientException */
    public function send(HttpRequest $request): HttpResponse;
}
