<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class LinkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $files = ['Web URL Below' => '0'];
        foreach ($options['files'] as $file) {
            $files[$file] = $file;
        }
        $sections = ['External Links' => 'External Links'];
        foreach ($options['sections'] as $section) {
            $sections[$section] = $section;
        } $sections['New Name Below'] = '__NEW__';
        $builder->add('title', TextType::class, ['label' => 'Tab/Menu Name', 'trim' => false, 'attr' => ['maxlength' => 20]])
            ->add('style', ChoiceType::class, ['label' => 'Style', 'choices' => ['Top Tab' => 'TAB', 'Console Menu' => 'CONSOLE', 'Bottom of Console Page' => 'FRONT', 'Top of Console Page' => 'FRONTTOP']])
            ->add('consolesection', ChoiceType::class, ['label' => 'Console Menu Section', 'choices' => $sections])
            ->add('consolenewsection', TextType::class, ['label' => 'New Console Section', 'required' => false, 'empty_data' => '', 'trim' => false])
            ->add('filename', ChoiceType::class, ['label' => 'Content File/URL', 'choices' => $files])
            ->add('fileurl', TextType::class, ['label' => 'Web URL Location', 'required' => false, 'empty_data' => '', 'trim' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Enabled', 'required' => false])
            ->add('refresh', ChoiceType::class, ['label' => 'Automatic Page Refresh', 'choices' => ['Disabled' => 0, 'Every 10 Seconds' => 10, 'Every 15 Seconds' => 15, 'Every 20 Seconds' => 20, 'Every 30 Seconds' => 30, 'Every Minute' => 60, 'Every 5 Minutes' => 300]])
            ->add('revision', HiddenType::class, []);
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['files', 'sections']);
        $resolver->setAllowedTypes('files', 'array');
        $resolver->setAllowedTypes('sections', 'array');
        $resolver->setDefaults(['translation_domain' => 'navigation', 'csrf_protection' => true, 'csrf_token_id' => 'navigation_link', 'method' => 'POST']);
    }
}
