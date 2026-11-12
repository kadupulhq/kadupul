<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\GraphRuleChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The settings of an automation_graph_rules.php rule. Graph Types are grouped
 * by Data Query, so the form needs no script; the store checks the pair.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class GraphRuleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $queries = [];
        foreach (is_array($options['queries']) ? $options['queries'] : [] as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $queries[$id] = $name;
            }
        }
        $types = [];
        foreach (is_array($options['graph_types']) ? $options['graph_types'] : [] as $id => $type) {
            if (is_int($id) && is_array($type) && is_int($type['query'] ?? null) && is_string($type['name'] ?? null)) {
                $types[$id] = ['query' => $type['query'], 'name' => $type['name']];
            }
        }
        $builder
            ->add('name', TextType::class, ['label' => 'Name', 'help' => 'A useful name for this Rule.', 'required' => false, 'trim' => false, 'empty_data' => '',
                'attr' => ['maxlength' => GraphRuleChange::NAME_LENGTH, 'size' => 80]])
            // Choices are IDs, so records that share a name stay distinct.
            ->add('snmp_query_id', ChoiceType::class, ['label' => 'Data Query', 'help' => 'Choose a Data Query to apply to this rule.',
                'choices' => array_keys($queries), 'choice_label' => static fn(int $id): string => $queries[$id], 'choice_translation_domain' => false])
            ->add('graph_type_id', ChoiceType::class, ['label' => 'Graph Type', 'help' => 'Choose any of the available Graph Types of the selected Data Query to apply to this rule.',
                'choices' => array_keys($types), 'choice_label' => static fn(int $id): string => $types[$id]['name'], 'choice_translation_domain' => false,
                'group_by' => static fn(int $id): string => $queries[$types[$id]['query']] ?? '#' . $types[$id]['query'],
                'placeholder' => 'Choose a Graph Type', 'required' => false])
            ->add('enabled', CheckboxType::class, ['label' => 'Enable Rule', 'help' => 'Check this box to enable this rule.', 'required' => false])
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'graph_rule',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_graph_rule_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'queries' => [],
            'graph_types' => [],
        ]);
        $resolver->setAllowedTypes('queries', 'array');
        $resolver->setAllowedTypes('graph_types', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'graph_rule';
    }
}
