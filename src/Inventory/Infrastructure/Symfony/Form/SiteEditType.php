<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for editing a site; the site fields are inherited from SiteCreateType.
 */
final class SiteEditType extends AbstractType
{
    /**
     * Adds the hidden revision.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('revision', HiddenType::class);
    }

    /**
     * Returns the form type whose fields this form inherits.
     */
    public function getParent(): string
    {
        return SiteCreateType::class;
    }

    /**
     * Enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_site_edit', 'method' => 'POST']);
    }
}
