<?php

declare(strict_types=1);

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

/** The form for creating a CDEF or renaming one. */
final class CdefType extends AbstractType
{
    /** Adds the name and the hidden revision. */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'A useful name for this CDEF.', 'attr' => ['maxlength' => 255, 'size' => 80]])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    /** Turns on CSRF protection and refuses extra fields. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'cdef',
            'csrf_protection' => true,
            'csrf_token_id' => 'graph_cdef_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    /** The form's field name prefix. */
    public function getBlockPrefix(): string
    {
        return 'cdef';
    }
}
