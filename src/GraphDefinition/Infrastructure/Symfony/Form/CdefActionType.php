<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class CdefActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revisions', HiddenType::class)
            ->add('selection', HiddenType::class)
            ->add('title_format', $options['operation'] === 'delete' ? HiddenType::class : TextType::class, ['label' => 'Title format', 'required' => false, 'trim' => false, 'attr' => ['maxlength' => 255]]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'graph_definition', 'csrf_protection' => true, 'csrf_token_id' => 'graph_cdef_action', 'method' => 'POST', 'operation' => 'duplicate']);
        $resolver->setAllowedValues('operation', ['delete', 'duplicate']);
    }
}
