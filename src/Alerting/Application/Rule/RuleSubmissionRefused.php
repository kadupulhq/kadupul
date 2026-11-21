<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Rule;

/** A submitted rule that cannot be saved. The message is fixed text, safe to show and translate. */
final class RuleSubmissionRefused extends \InvalidArgumentException {}
