<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

enum DeviceRemovalPolicy: string
{
    case Retain = 'retain';
    case Purge = 'purge';
}
