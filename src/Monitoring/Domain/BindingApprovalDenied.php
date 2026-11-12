<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/** Thrown when binding approval has no authorizer, no actor id, or an actor who is not authorized. */
final class BindingApprovalDenied extends \DomainException {}
