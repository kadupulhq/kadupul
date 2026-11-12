<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form for choosing a maintenance action and, for query actions, a data query.
 */
final class DeviceMaintenanceType extends AbstractType
{
    /**
     * Adds the hidden revision, the action choice and the data query choice.
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $queries = [0 => ''] + $options['queries'];
        $builder->add('revision', HiddenType::class)->add('operation', ChoiceType::class, ['label' => 'Maintenance action', 'placeholder' => 'Select a change', 'choices' => [
            'Reindex all queries' => 'reindex', 'Reload data query' => 'reload-query', 'Diagnose data query' => 'query-diagnostics', 'Refresh poller cache' => 'refresh-cache', 'Enable device debug' => 'enable-debug', 'Disable device debug' => 'disable-debug', 'Check connectivity' => 'connectivity',
        ]])->add('query', ChoiceType::class, ['label' => 'Data query', 'choices' => array_map('intval', array_keys($queries)), 'choice_label' => static fn(int $id): string => $queries[$id], 'choice_translation_domain' => false, 'choice_value' => static fn(?int $id): string => $id === null ? '' : (string) $id, 'help' => 'Choose a query only when reloading or diagnosing one query.']);
    }
    /**
     * Requires the "queries" option (names keyed by id), and enables CSRF protection and POST.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('queries');
        $resolver->setAllowedTypes('queries', 'array');
        $resolver->setDefaults(['translation_domain' => 'inventory', 'csrf_protection' => true, 'csrf_token_id' => 'inventory_device_maintenance', 'method' => 'POST']);
    }
}
