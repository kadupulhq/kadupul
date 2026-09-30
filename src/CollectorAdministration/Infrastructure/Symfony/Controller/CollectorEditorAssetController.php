<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CollectorEditorAssetController
{
    public function __construct(private string $projectDir) {}

    #[Route('/collector-editor.js', name: 'collector_editor_asset', methods: ['GET', 'HEAD'])]
    public function __invoke(): Response
    {
        $asset = file_get_contents($this->projectDir . '/public/collector-editor.js');
        if (!is_string($asset)) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($asset, Response::HTTP_OK, [
            'Content-Type' => 'text/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
