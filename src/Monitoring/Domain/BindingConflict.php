<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** A registration that contradicts a binding already recorded for the same key or storage field. */
final class BindingConflict extends \DomainException {}
