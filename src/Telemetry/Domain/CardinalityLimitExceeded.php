<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Telemetry\Domain;

/**
 * Raised when a metric would gain more distinct series than its limit allows.
 */
final class CardinalityLimitExceeded extends \DomainException {}
