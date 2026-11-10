<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * BaselineSource::prune() could not remove old history, for example on a
 * lock wait timeout, a deadlock, a lost connection or an unreadable row. The
 * rows stay and the next run retries them. The adapter wraps every database
 * exception as the previous exception, so the evaluator needs no persistence
 * types to contain it; a dead connection is contained here too and surfaces
 * at the run's next database call.
 */
final class BaselinePruneFailed extends \RuntimeException
{
    public static function because(\Throwable $failure): self
    {
        return new self('Baseline history could not be pruned.', 0, $failure);
    }
}
