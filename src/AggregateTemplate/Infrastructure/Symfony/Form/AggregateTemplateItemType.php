<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AggregateTemplateItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id', HiddenType::class)
            ->add('sequence', HiddenType::class)
            ->add('forceSkip', HiddenType::class)
            ->add('colorTemplate', ChoiceType::class, ['label' => false, 'choices' => $options['color_templates'], 'choice_translation_domain' => false])
            ->add('skip', CheckboxType::class, ['label' => 'Skip', 'required' => false])
            ->add('total', CheckboxType::class, ['label' => 'Total', 'required' => false]);
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();
            $event->getForm()->add('skip', CheckboxType::class, [
                'label' => 'Skip', 'required' => false, 'disabled' => is_array($data) && ($data['forceSkip'] ?? false) === true,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'aggregate_template', 'color_templates' => []]);
        $resolver->setAllowedTypes('color_templates', 'array');
    }
}
