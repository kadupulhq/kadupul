<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DevicePlacementType extends AbstractType
{
    public const TIMESPANS = ['Last Half Hour', 'Last Hour', 'Last 2 Hours', 'Last 4 Hours', 'Last 6 Hours', 'Last 12 Hours', 'Last Day', 'Last 2 Days', 'Last 3 Days', 'Last 4 Days', 'Last Week', 'Last 2 Weeks', 'Last Month', 'Last 2 Months', 'Last 3 Months', 'Last 4 Months', 'Last 6 Months', 'Last Year', 'Last 2 Years', 'Day Shift', 'This Day', 'This Week', 'This Month', 'This Year', 'Previous Day', 'Previous Week', 'Previous Month', 'Previous Year'];
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $targets = $options['targets'];
        $builder->add('selection', HiddenType::class)->add('target', ChoiceType::class, [
            'label' => 'Placement destination',
            'choices' => array_map('strval', array_keys($targets)),
            'choice_label' => static fn(string $id): string => $targets[$id],
            'choice_value' => static fn(?string $id): string => $id ?? '',
            'choice_translation_domain' => false,
            'placeholder' => 'Select a placement destination',
        ]);
        if ($options['kind'] === 'report') {
            $builder->add('timespan', ChoiceType::class, ['label' => 'Report timespan', 'choices' => array_combine(self::TIMESPANS, range(1, 28))]);
            $builder->add('alignment', ChoiceType::class, ['label' => 'Report alignment', 'choices' => ['Left' => 1, 'Center' => 2, 'Right' => 3]]);
        }
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['targets', 'kind']);
        $resolver->setAllowedTypes('targets', 'array');
        $resolver->setAllowedValues('kind', ['tree', 'report']);
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_placement', 'method' => 'POST']);
    }
}
