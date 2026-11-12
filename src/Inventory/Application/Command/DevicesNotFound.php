<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Application\Command;

/**
 * Thrown when some of the selected devices do not exist or are not visible to the user.
 */
final class DevicesNotFound extends \RuntimeException {}
