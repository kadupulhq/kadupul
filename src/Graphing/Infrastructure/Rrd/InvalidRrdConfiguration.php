<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Graphing\Infrastructure\Rrd;

/** Raised while wiring the RRD write adapters, before any sample is handled, so it names the setting to fix. */
final class InvalidRrdConfiguration extends \InvalidArgumentException {}
