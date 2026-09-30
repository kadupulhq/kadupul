<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AggregateGraphSettingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fieldType = match ($options['value_kind']) {
            'checkbox' => CheckboxType::class,
            'choice' => ChoiceType::class,
            default => TextType::class,
        };
        $valueOptions = ['label' => false, 'required' => false];
        if ($options['value_kind'] === 'text') {
            $valueOptions += ['trim' => false, 'attr' => ['maxlength' => $options['max_length']]];
        }
        if ($options['value_kind'] === 'choice') {
            $valueOptions += ['choices' => $options['choices'], 'choice_translation_domain' => false, 'placeholder' => false];
        }
        $builder->add('value', $fieldType, $valueOptions)
            ->add('override', CheckboxType::class, ['label' => 'Override this value', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'aggregate_template', 'value_kind' => 'text', 'choices' => [], 'max_length' => 255]);
        $resolver->setAllowedValues('value_kind', ['text', 'checkbox', 'choice']);
        $resolver->setAllowedTypes('choices', 'array');
        $resolver->setAllowedTypes('max_length', 'int');
    }
}
