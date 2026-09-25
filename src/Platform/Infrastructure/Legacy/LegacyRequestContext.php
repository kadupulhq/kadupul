<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the server values used by legacy page and browser URL helpers.
 */
final readonly class LegacyRequestContext
{
    public function currentPage(Request $request, bool $basename = true): string|false
    {
        $scriptName = $request->server->get('SCRIPT_NAME');

        if (isset($scriptName) && $scriptName !== '') {
            return $basename ? basename($scriptName) : $scriptName;
        }

        $scriptFilename = $request->server->get('SCRIPT_FILENAME');

        if (isset($scriptFilename) && $scriptFilename !== '') {
            return $basename ? basename($scriptFilename) : $scriptFilename;
        }

        return false;
    }

    public function browserQueryString(Request $request): string
    {
        $requestUri = $request->server->get('REQUEST_URI');

        if (!empty($requestUri)) {
            return $requestUri;
        }

        $currentPage = $this->currentPage($request);
        $queryString = $request->server->get('QUERY_STRING');

        return $currentPage . (empty($queryString) ? '' : '?' . $queryString);
    }
}
