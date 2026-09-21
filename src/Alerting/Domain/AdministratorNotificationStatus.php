<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Domain;

enum AdministratorNotificationStatus
{
    case Sent;
    case NotConfigured;
    case Disabled;
    case InvalidAccount;
    case MissingAddress;
}
