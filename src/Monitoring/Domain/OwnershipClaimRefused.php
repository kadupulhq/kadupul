<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Thrown when model-driven collection may not claim a storage field because another writer owns it. */
final class OwnershipClaimRefused extends \DomainException {}
