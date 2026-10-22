<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Package;

/** Its message is shown to the operator, so it never carries key material. */
final class PackageSigningFailed extends \RuntimeException {}
