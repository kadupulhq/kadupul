<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Form;

use Kadupul\Inventory\Domain\DeviceOptionsChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Event\PostSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DeviceOptionsType extends AbstractType
{
    public function __construct(private readonly \Symfony\Contracts\Translation\TranslatorInterface $translator) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (PreSubmitEvent $event): void {
            $data = $event->getData();
            if (!is_array($data)) {
                return;
            }
            // An unchecked value is irrelevant, including a stale choice that
            // would otherwise fail transformation before domain validation.
            foreach (DeviceOptionsChange::DEFAULTS as $field => $default) {
                if (!isset($data['apply_' . $field])) {
                    $data[$field] = $default;
                }
            }
            $event->setData($data);
        });
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (PostSubmitEvent $event): void {
            $form = $event->getForm();
            foreach ($form as $child) {
                if (!$child->isSynchronized()) {
                    return;
                }
            }
            $data = $form->getData();
            if (!is_array($data)) {
                return;
            }
            $changes = [];
            foreach (DeviceOptionsChange::DEFAULTS as $field => $default) {
                if ($data['apply_' . $field] ?? false) {
                    $changes[$field] = $data[$field] ?? null;
                }
            }
            try {
                new DeviceOptionsChange($changes);
            } catch (\InvalidArgumentException $error) {
                $form->addError(new FormError($this->translator->trans($error->getMessage(), [], 'inventory')));
            }
        });
        $labels = ['location' => 'Location', 'device_threads' => 'Device threads', 'snmp_port' => 'SNMP port', 'snmp_timeout' => 'SNMP timeout (ms)', 'max_oids' => 'Maximum OIDs', 'bulk_walk_size' => 'Bulk walk size', 'availability_method' => 'Availability method', 'ping_method' => 'Ping method', 'ping_port' => 'Ping port', 'ping_timeout' => 'Ping timeout (ms)', 'ping_retries' => 'Ping retries'];
        $polling = $builder->create('polling', DevicePollingType::class);
        foreach (DeviceOptionsChange::DEFAULTS as $field => $default) {
            $builder->add('apply_' . $field, CheckboxType::class, ['label' => 'Update %option%', 'label_translation_parameters' => ['%option%' => $this->translator->trans($labels[$field], [], 'inventory')], 'required' => false]);
            if ($field === 'location') {
                $builder->add($field, TextType::class, ['label' => $labels[$field], 'required' => false, 'trim' => false, 'empty_data' => '', 'attr' => ['maxlength' => 40]]);
            } else {
                $builder->add($polling->get($field)->setRequired(false));
            }
        }
    }
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'inventory']);
    }
}
