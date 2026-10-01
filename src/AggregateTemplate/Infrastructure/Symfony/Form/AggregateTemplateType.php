<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\AggregateTemplate\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class AggregateTemplateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('id', HiddenType::class)
            ->add('revision', HiddenType::class)
            ->add('name', TextType::class, ['label' => 'Aggregate template name', 'trim' => false, 'attr' => ['maxlength' => 64]])
            ->add('graph_template_id', ChoiceType::class, [
                'label' => 'Source graph template', 'choices' => $options['graph_templates'],
                'choice_translation_domain' => false, 'disabled' => $options['source_locked'],
            ])
            ->add('gprint_prefix', TextType::class, ['label' => 'Prefix', 'required' => false, 'trim' => false, 'attr' => ['maxlength' => 64]])
            ->add('gprint_format', CheckboxType::class, ['label' => 'Include prefix text', 'required' => false])
            ->add('graph_type', ChoiceType::class, ['label' => 'Graph type', 'choices' => $options['graph_types'], 'choice_translation_domain' => false])
            ->add('total', ChoiceType::class, ['label' => 'Totaling', 'choices' => $options['totals'], 'choice_translation_domain' => false])
            ->add('total_type', ChoiceType::class, ['label' => 'Total type', 'choices' => $options['total_types'], 'choice_translation_domain' => false])
            ->add('total_prefix', TextType::class, ['label' => 'Total prefix', 'required' => false, 'trim' => false, 'attr' => ['maxlength' => 64]])
            ->add('order_type', ChoiceType::class, ['label' => 'Reorder type', 'choices' => $options['order_types'], 'choice_translation_domain' => false])
            ->add('items', CollectionType::class, [
                'entry_type' => AggregateTemplateItemType::class,
                'entry_options' => ['color_templates' => $options['color_templates']],
                'allow_add' => false, 'allow_delete' => false, 'by_reference' => false,
            ]);

        foreach ($options['graph_field_names'] as $name) {
            $metadata = $options['graph_field_metadata'][$name] ?? ['kind' => 'text', 'choices' => [], 'maxLength' => 255];
            $builder->add('graphSettings_' . $name, AggregateGraphSettingType::class, [
                'property_path' => '[graphSettings][' . $name . ']',
                'label' => $name,
                'value_kind' => $metadata['kind'],
                'choices' => $metadata['choices'],
                'max_length' => $metadata['maxLength'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'aggregate_template',
            'csrf_protection' => true,
            'csrf_token_id' => 'aggregate_template_edit',
            'method' => 'POST',
            'graph_templates' => [], 'graph_types' => [], 'totals' => [], 'total_types' => [], 'order_types' => [],
            'graph_field_names' => [], 'graph_field_metadata' => [], 'color_templates' => [], 'source_locked' => false,
        ]);
        foreach (['graph_templates', 'graph_types', 'totals', 'total_types', 'order_types', 'graph_field_names', 'graph_field_metadata', 'color_templates'] as $option) {
            $resolver->setAllowedTypes($option, 'array');
        }
        $resolver->setAllowedTypes('source_locked', 'bool');
    }
}
