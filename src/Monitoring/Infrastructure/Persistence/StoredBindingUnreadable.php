<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Infrastructure\Persistence;

/** A stored binding or claim no longer satisfies the domain rules; it was changed outside this code. */
final class StoredBindingUnreadable extends \RuntimeException {}
