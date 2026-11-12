<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/**
 * What happens to a removed device's graphs and data sources.
 * Retain keeps the graphs and disables the data sources; Purge removes both.
 */
enum DeviceRemovalPolicy: string
{
    case Retain = 'retain';
    case Purge = 'purge';
}
