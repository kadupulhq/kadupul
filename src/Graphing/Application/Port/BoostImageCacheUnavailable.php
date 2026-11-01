<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** The cache directory is not configured, is not a usable subdirectory of the installation, or its settings cannot be read. */
final class BoostImageCacheUnavailable extends \RuntimeException {}
