<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Kadupul\Graphing\Domain\GprintPreset;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class GprintPresetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'GPRINT Preset Name', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => GprintPreset::MAX_LENGTH]])
            ->add('gprint_text', TextType::class, ['label' => 'GPRINT Text', 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => GprintPreset::MAX_LENGTH]])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'gprint',
            'csrf_protection' => true,
            'csrf_token_id' => 'gprint_preset_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'gprint_preset';
    }
}
