<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class NetworkFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['required' => false, 'label' => 'Search networks', 'attr' => ['maxlength' => 200]])
            ->add('size', ChoiceType::class, ['choices' => [25 => '25', 50 => '50', 100 => '100'], 'label' => 'Per page'])
            ->add('sort', ChoiceType::class, ['choices' => [
                'Network name' => 'name', 'Data collector' => 'data_collector', 'Schedule' => 'sched_type',
                'Total IPs' => 'total_ips', 'Threads' => 'threads', 'Last runtime' => 'last_runtime', 'Last started' => 'last_started',
            ], 'label' => 'Sort by'])
            ->add('direction', ChoiceType::class, ['choices' => ['Ascending' => 'asc', 'Descending' => 'desc'], 'label' => 'Order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'allow_extra_fields' => false, 'translation_domain' => 'collection']);
    }

    public function getBlockPrefix(): string
    {
        return 'network_filter';
    }
}
