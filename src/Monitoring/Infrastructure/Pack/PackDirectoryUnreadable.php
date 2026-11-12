<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Pack;

/** Thrown when a pack directory or file cannot be found, listed or read. */
final class PackDirectoryUnreadable extends \RuntimeException {}
