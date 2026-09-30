<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Form;

use Kadupul\CollectorAdministration\Domain\CollectorListCriteria;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CollectorFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['required' => false, 'label' => 'Search collectors', 'attr' => ['maxlength' => 200]])
            ->add('size', ChoiceType::class, ['choices' => array_combine(CollectorListCriteria::PAGE_SIZES, array_map('strval', CollectorListCriteria::PAGE_SIZES)), 'label' => 'Per page'])
            ->add('sort', ChoiceType::class, ['choices' => [
                'Name' => 'name', 'ID' => 'id', 'Hostname' => 'hostname', 'Status' => 'status',
                'Devices' => 'hosts', 'Polling time' => 'polling_time', 'SNMP Gets' => 'snmp',
                'Scripts' => 'script', 'Servers' => 'server', 'Last finished' => 'last_update',
                'Last update' => 'last_status', 'Last sync' => 'last_sync',
            ], 'label' => 'Sort by'])
            ->add('direction', ChoiceType::class, ['choices' => ['Ascending' => 'asc', 'Descending' => 'desc'], 'label' => 'Order'])
            ->add('refresh', ChoiceType::class, ['choices' => [
                'Off' => '0', '5 Seconds' => '5', '10 Seconds' => '10', '20 Seconds' => '20',
                '30 Seconds' => '30', '45 Seconds' => '45', '1 Minute' => '60',
                '2 Minutes' => '120', '5 Minutes' => '300',
            ], 'label' => 'Refresh']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'allow_extra_fields' => false, 'translation_domain' => 'collectors']);
    }

    public function getBlockPrefix(): string
    {
        return 'collector_filter';
    }
}
