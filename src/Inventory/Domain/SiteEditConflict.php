<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * Thrown when a site changed after the caller loaded it, so the edit must be reloaded.
 */
final class SiteEditConflict extends \RuntimeException {}
