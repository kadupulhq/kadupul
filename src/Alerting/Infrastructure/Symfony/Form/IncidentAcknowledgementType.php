<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Acknowledgement carries nothing but its CSRF token; the incident is the route's.
 *
 * @extends AbstractType<null>
 */
final class IncidentAcknowledgementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void {}

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'alerting',
            'csrf_protection' => true,
            'csrf_token_id' => 'alerting_incident_acknowledge',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'alert_incident_acknowledge';
    }
}
