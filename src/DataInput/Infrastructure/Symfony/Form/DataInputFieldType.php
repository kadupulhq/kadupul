<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataInputFieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['label' => 'Friendly name','trim' => false,'attr' => ['maxlength' => 200]]);
        if ($options['placeholders'] !== null) {
            $builder->add('data_name', ChoiceType::class, ['label' => 'Field name','choices' => array_combine($options['placeholders'], $options['placeholders'])]);
        } else {
            $builder->add('data_name', TextType::class, ['label' => 'Field name','trim' => false,'attr' => ['maxlength' => 50]]);
        }
        $builder->add('input_output', HiddenType::class)->add('revision', HiddenType::class);
        if ($options['direction'] === 'in') {
            $builder->add('type_code', TextType::class, ['label' => 'Special type code','required' => false,'trim' => false,'empty_data' => '','attr' => ['maxlength' => 40]])->add('regexp_match', TextType::class, ['label' => 'Regular expression','required' => false,'trim' => false,'empty_data' => '','attr' => ['maxlength' => 200]])->add('allow_nulls', CheckboxType::class, ['label' => 'Allow empty input','required' => false]);
        } else {
            $builder->add('update_rra', CheckboxType::class, ['label' => 'Update RRA','required' => false]);
        }
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'data_input_field','translation_domain' => 'data_input','placeholders' => null,'direction' => 'in']);
        $resolver->setAllowedTypes('placeholders', ['array','null']);
        $resolver->setAllowedValues('direction', ['in','out']);
    }
}
