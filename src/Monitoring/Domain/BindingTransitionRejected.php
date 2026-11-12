<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Thrown when a binding status change is not allowed from the current status or the stored state. */
final class BindingTransitionRejected extends \DomainException {}
