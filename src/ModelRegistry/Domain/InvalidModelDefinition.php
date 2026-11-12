<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * Raised when a model, identity, revision or related value is malformed.
 */
final class InvalidModelDefinition extends \InvalidArgumentException {}
