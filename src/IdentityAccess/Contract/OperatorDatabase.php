<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Contract;

/** The database a command-line operator's account and realms are read from. */
enum OperatorDatabase
{
    case Local;
    case Main;
}
