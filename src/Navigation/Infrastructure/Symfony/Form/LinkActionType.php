<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LinkActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revision', HiddenType::class, []);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'navigation', 'csrf_protection' => true, 'csrf_token_id' => 'navigation_link_action', 'method' => 'POST']);
    }
}
