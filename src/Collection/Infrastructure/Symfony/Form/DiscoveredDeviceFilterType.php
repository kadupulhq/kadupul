<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Collection\Infrastructure\Symfony\Form;

use Kadupul\Collection\Application\ReadModel\DiscoveryNetworkChoice;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DiscoveredDeviceFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $networkChoices = ['Any' => '-1'];
        foreach ($options['networks'] as $network) {
            $networkChoices[$network->name . ' (#' . $network->id . ')'] = (string) $network->id;
        }
        $osChoices = ['Any' => ''];
        foreach ($options['operating_systems'] as $os) {
            $osChoices[$os] = $os;
        }

        $builder
            ->add('q', SearchType::class, ['required' => false, 'label' => 'Search discovered devices', 'attr' => ['maxlength' => 200]])
            ->add('network', ChoiceType::class, ['choices' => $networkChoices, 'label' => 'Network'])
            ->add('status', ChoiceType::class, ['choices' => ['Any' => 'all', 'Up' => 'up', 'Down' => 'down'], 'label' => 'Status'])
            ->add('snmp', ChoiceType::class, ['choices' => ['Any' => 'all', 'Up' => 'up', 'Down' => 'down'], 'label' => 'SNMP'])
            ->add('os', ChoiceType::class, ['choices' => $osChoices, 'label' => 'OS'])
            ->add('size', ChoiceType::class, ['choices' => [25 => '25', 50 => '50', 100 => '100'], 'label' => 'Per page'])
            ->add('sort', ChoiceType::class, ['choices' => [
                'Device Name' => 'hostname', 'IP' => 'ip', 'SNMP Name' => 'sysName', 'Location' => 'sysLocation',
                'Contact' => 'sysContact', 'Description' => 'sysDescr', 'OS' => 'os', 'Uptime' => 'time', 'SNMP' => 'snmp', 'Status' => 'up',
            ], 'label' => 'Sort by'])
            ->add('direction', ChoiceType::class, ['choices' => ['Ascending' => 'asc', 'Descending' => 'desc'], 'label' => 'Order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => false,
            'allow_extra_fields' => false,
            'translation_domain' => 'collection',
            'networks' => [],
            'operating_systems' => [],
        ]);
        $resolver->setAllowedTypes('networks', 'array');
        $resolver->setAllowedTypes('operating_systems', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'discovery_filter';
    }
}
