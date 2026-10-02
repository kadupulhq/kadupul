<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaletteColorType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Color Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'disabled' => $options['read_only'], 'attr' => ['maxlength' => 40]])
            ->add('hex', TextType::class, ['label' => 'Hex', 'required' => false, 'trim' => false, 'empty_data' => '', 'disabled' => $options['read_only'], 'attr' => ['maxlength' => 6]])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'palette',
            'read_only' => false,
            'csrf_protection' => true,
            'csrf_token_id' => 'palette_color_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'palette_color';
    }
}
