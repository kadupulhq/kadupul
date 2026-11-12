<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Contract;

/**
 * Raised when a telemetry value object is given input it does not accept.
 */
final class InvalidTelemetryValue extends \InvalidArgumentException {}
