<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class SiteEditType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, ['help' => 'Enter a name of 1–100 Unicode characters.'])
            ->add('notes', TextareaType::class, ['required' => false, 'trim' => false, 'empty_data' => '', 'help' => 'Up to 1,024 Unicode characters.', 'attr' => ['rows' => 8]])
            ->add('revision', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'inventory_site_edit', 'method' => 'POST']);
    }
}
