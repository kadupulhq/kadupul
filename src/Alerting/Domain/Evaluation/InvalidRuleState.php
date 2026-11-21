<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Evaluation;

/** Counters and flags that no sequence of verdicts can produce. */
final class InvalidRuleState extends \InvalidArgumentException {}
