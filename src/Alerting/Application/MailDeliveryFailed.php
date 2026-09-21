<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application;

final class MailDeliveryFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Test mail could not be confirmed. Check the installation mail settings and SMTP server logs before retrying.');
    }
}
