<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\GraphRule;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Confirms a bulk action on selected Graph Rules; the "duplicate" option adds the name format.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class GraphRuleActionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('selection', HiddenType::class)->add('revisions', HiddenType::class);
        // Only duplication names anything; the other forms must not render a stray input.
        if ($options['duplicate'] === true) {
            $builder->add('name_format', TextType::class, [
                'label' => 'Title Format',
                'help' => 'Every <rule_name> is replaced by the name of the Graph Rule being copied.',
                'required' => false,
                'trim' => false,
                'empty_data' => '',
                'attr' => ['maxlength' => GraphRule::NAME_FORMAT_LENGTH, 'size' => 30],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'graph_rule',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_graph_rule_action',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'duplicate' => false,
        ]);
        $resolver->setAllowedTypes('duplicate', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'automation_graph_rule_action';
    }
}
