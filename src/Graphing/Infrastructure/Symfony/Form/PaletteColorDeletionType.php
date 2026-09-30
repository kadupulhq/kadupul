<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaletteColorDeletionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revisions', HiddenType::class)
            ->add('selection', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'palette',
            'csrf_protection' => true,
            'csrf_token_id' => 'palette_color_delete',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'palette_color_delete';
    }
}
