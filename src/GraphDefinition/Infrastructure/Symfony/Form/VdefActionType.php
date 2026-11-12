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

/** The confirmation form for deleting or duplicating VDEFs. */
final class VdefActionType extends AbstractType
{
    /** Adds the hidden selection and revisions and the title format. */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)
            ->add('revisions', HiddenType::class)
            ->add('title_format', TextType::class, ['label' => 'Title format', 'required' => false, 'trim' => false, 'attr' => ['maxlength' => 255]]);
    }

    /** Turns on CSRF protection. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'graph_definition', 'csrf_protection' => true, 'csrf_token_id' => 'graph_vdef_action', 'method' => 'POST']);
    }
}
