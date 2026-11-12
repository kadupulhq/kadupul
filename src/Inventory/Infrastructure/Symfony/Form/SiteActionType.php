<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for confirming a bulk site action.
 */
final class SiteActionType extends AbstractType
{
    /**
     * Adds the hidden selection, and for duplication the name pattern for the copies.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class);
        if ($options['duplicate']) {
            $builder->add('pattern', TextType::class, ['label' => 'Copy name', 'trim' => false, 'help' => 'Use <site> for each original site name.']);
        }
    }

    /**
     * Accepts the "duplicate" flag, and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_site_action', 'method' => 'POST', 'duplicate' => false]);
        $resolver->setAllowedTypes('duplicate', 'bool');
    }
}
