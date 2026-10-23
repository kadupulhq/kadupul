<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\ModelRegistry\Domain;

/**
 * Raised when a registered identity and revision is offered again with different
 * content, which means the published model changed or the source was tampered with.
 */
final class ModelChecksumConflict extends \DomainException
{
    public function __construct(ModelIdentity $identity, ModelRevision $revision, Checksum $registered, Checksum $offered)
    {
        parent::__construct(sprintf(
            'Model %s@%s is already registered with checksum %s; refusing checksum %s.',
            $identity->key(),
            $revision->value(),
            $registered->value(),
            $offered->value(),
        ));
    }
}
