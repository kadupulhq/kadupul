<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** The form that posts a new VDEF item order, or a single move up or down. */
final class VdefReorderType extends AbstractType
{
    /** Adds the hidden item list, revision and optional move fields. */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('items', HiddenType::class)
            ->add('revision', HiddenType::class)
            ->add('moveUp', HiddenType::class, ['required' => false])
            ->add('moveDown', HiddenType::class, ['required' => false]);
    }

    /** Turns on CSRF protection. */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['csrf_protection' => true, 'csrf_token_id' => 'graph_vdef_edit', 'method' => 'POST']);
    }
}
