<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Console;

/**
 * How the running command should present its result. A cli/ shim switches it
 * to legacy output through the kernel before the command runs, so commands
 * need no hidden options for it.
 */
final class CliPresentation
{
    public private(set) OutputMode $mode = OutputMode::Human;
    public private(set) LegacyRequest $legacy = LegacyRequest::Run;

    public function forLegacy(LegacyRequest $request): void
    {
        $this->mode = OutputMode::Legacy;
        $this->legacy = $request;
    }
}
