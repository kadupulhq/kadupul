<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Thrown when an install, state change or upgrade breaks the pack lifecycle rules. */
final class PackLifecycleViolation extends \DomainException {}
