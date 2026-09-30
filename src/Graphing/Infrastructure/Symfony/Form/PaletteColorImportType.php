<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class PaletteColorImportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('file', FileType::class, ['label' => 'CSV file', 'required' => false])
            ->add('allow_update', CheckboxType::class, ['label' => 'Update existing colors', 'required' => false])
            ->add('revision', HiddenType::class);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'palette', 'csrf_protection' => true, 'csrf_token_id' => 'palette_color_import', 'method' => 'POST', 'allow_extra_fields' => false]);
    }
    public function getBlockPrefix(): string
    {
        return 'palette_color_import';
    }
}
