<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */

namespace Kadupul\DataInput\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DataInputActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revision', HiddenType::class);
        if ($options['duplicate']) {
            $builder->add('title', TextType::class, ['label' => 'Name format', 'trim' => false, 'attr' => ['maxlength' => 200]]);
        }
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_token_id' => 'data_input_action', 'translation_domain' => 'data_input', 'duplicate' => false]);
    }
}
