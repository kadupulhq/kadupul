<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Monitoring\Infrastructure\Workflow;

use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;

/**
 * Keeps the marking in the subject's own status instead of a property reached
 * through PropertyAccess, so the workflow cannot hold a state the domain enum
 * does not name.
 */
final class StatusMarkingStore implements MarkingStoreInterface
{
    #[\Override]
    public function getMarking(object $subject): Marking
    {
        if (!$subject instanceof StatusSubject) {
            throw new \InvalidArgumentException(sprintf('%s cannot hold a marking for %s.', self::class, $subject::class));
        }

        return new Marking([$subject->place() => 1]);
    }

    /** @param array<mixed, mixed> $context */
    #[\Override]
    public function setMarking(object $subject, Marking $marking, array $context = []): void
    {
        if (!$subject instanceof StatusSubject) {
            throw new \InvalidArgumentException(sprintf('%s cannot hold a marking for %s.', self::class, $subject::class));
        }
        $places = array_keys($marking->getPlaces());
        if (count($places) !== 1) {
            throw new \LogicException('A state machine marking names exactly one place.');
        }
        $subject->moveTo((string) $places[0]);
    }
}
