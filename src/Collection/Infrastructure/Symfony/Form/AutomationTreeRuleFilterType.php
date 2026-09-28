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

final class AutomationTreeRuleFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('q', SearchType::class, ['required' => false, 'label' => 'Search tree rules', 'attr' => ['maxlength' => 200]])
            ->add('status', ChoiceType::class, ['choices' => ['Any' => 'all', 'Enabled' => 'enabled', 'Disabled' => 'disabled'], 'label' => 'Status'])
            ->add('size', ChoiceType::class, ['choices' => [25 => '25', 50 => '50', 100 => '100'], 'label' => 'Per page'])
            ->add('sort', ChoiceType::class, ['choices' => [
                'Rule name' => 'name', 'Tree' => 'tree', 'Subtree' => 'subtree', 'Rule type' => 'leaf_type',
                'Host grouping' => 'host_grouping_type', 'Status' => 'enabled', 'ID' => 'id',
            ], 'label' => 'Sort by'])
            ->add('direction', ChoiceType::class, ['choices' => ['Ascending' => 'asc', 'Descending' => 'desc'], 'label' => 'Order']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => false, 'allow_extra_fields' => false, 'translation_domain' => 'collection']);
    }

    public function getBlockPrefix(): string
    {
        return 'automation_tree_rule_filter';
    }
}
