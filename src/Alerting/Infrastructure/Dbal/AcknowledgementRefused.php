<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;

/** Ends an acknowledgement that writes nothing, so its transaction rolls back and its audit records a failure. */
final class AcknowledgementRefused extends \RuntimeException
{
    public function __construct(public readonly AcknowledgeOutcome $outcome)
    {
        parent::__construct('The incident was not acknowledged.');
    }
}
