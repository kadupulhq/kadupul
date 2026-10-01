<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataInputMethodType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $types = ['Script/Command' => 1,'Script Server' => 5];
        $labels = [2 => 'SNMP Get',3 => 'SNMP Query',4 => 'Script Query',6 => 'Script Server Query'];
        if (isset($labels[$options['existing_type']])) {
            $types[$labels[$options['existing_type']]] = $options['existing_type'];
        }
        $builder->add('name', TextType::class, ['label' => 'Name','trim' => false,'attr' => ['maxlength' => 200]])->add('input_string', TextareaType::class, ['label' => 'Input string','required' => false,'trim' => false,'empty_data' => '','attr' => ['maxlength' => 512]])->add('type_id', ChoiceType::class, ['label' => 'Input type','choices' => $types])->add('revision', HiddenType::class, ['required' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'data_input_method','translation_domain' => 'data_input','existing_type' => 1]);
        $resolver->setAllowedTypes('existing_type', 'int');
    }
}
