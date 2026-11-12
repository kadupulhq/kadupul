<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Form;

use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The legacy automation_networks.php edit form; the domain change validates what it submits.
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class DiscoveryNetworkType extends AbstractType
{
    private const array WEEKDAYS = [1 => 'Sunday', 2 => 'Monday', 3 => 'Tuesday', 4 => 'Wednesday', 5 => 'Thursday', 6 => 'Friday', 7 => 'Saturday'];
    private const array MONTHS = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $pollers = self::names($options['pollers']);
        $sites = self::names($options['sites']);
        $snmp = self::names($options['snmp']);
        $builder
            ->add('name', TextType::class, self::text('Name', 'Give this Network a meaningful name.', 'name', ['placeholder' => 'New Network Discovery Range']))
            // Choices are IDs, so records that share a name stay distinct.
            ->add('poller_id', ChoiceType::class, self::records('Data Collector', 'Choose the Data Collector/Poller to be used to gather data from this Device.', $pollers))
            ->add('site_id', ChoiceType::class, ['choices' => [0, ...array_keys($sites)], 'choice_label' => static fn(int $id): string => $id === 0 ? 'None' : $sites[$id],
                'choice_translation_domain' => 'discovery_network', 'label' => 'Associated Site', 'help' => 'Choose the Site that you wish to associate discovered Devices with.'])
            ->add('subnet_range', TextareaType::class, ['label' => 'Subnet Range', 'trim' => false, 'empty_data' => '',
                'help' => 'Enter valid Network Ranges separated by commas.  You may use an IP address, a Network range such as 192.168.1.0/24 or 192.168.1.0/255.255.255.0, or using wildcard notation such as 192.168.*.*',
                'attr' => ['rows' => 4, 'cols' => 80, 'maxlength' => DiscoveryNetworkChange::SUBNET_RANGE_LENGTH, 'placeholder' => '192.168.1.0/24']])
            ->add('dns_servers', TextType::class, self::text('Alternate DNS Servers', 'A space delimited list of alternate DNS Servers to use for DNS resolution. If blank, the poller OS will be used to resolve DNS names.', 'dns_servers', ['placeholder' => 'Enter IPs or FQDNs of DNS Servers']))
            ->add('sched_type', ChoiceType::class, self::choices('Schedule Type', 'Define the collection frequency.', [1 => 'Manual', 2 => 'Daily', 3 => 'Weekly', 4 => 'Monthly', 5 => 'Monthly on Day']))
            ->add('threads', ChoiceType::class, self::counted(
                'Discovery Threads',
                'Define the number of threads to use for discovering this Network Range.',
                DiscoveryNetworkChange::CHOICES['threads'],
                static fn(int $threads): array => [$threads === 1 ? '%count% Thread' : '%count% Threads', $threads]
            ))
            ->add('run_limit', ChoiceType::class, self::counted(
                'Run Limit',
                'After the selected Run Limit, the discovery process will be terminated.',
                DiscoveryNetworkChange::CHOICES['run_limit'],
                static fn(int $seconds): array => $seconds < 3600
                    ? [$seconds === 60 ? '%count% Minute' : '%count% Minutes', intdiv($seconds, 60)]
                    : [$seconds === 3600 ? '%count% Hour' : '%count% Hours', intdiv($seconds, 3600)]
            ))
            ->add('enabled', CheckboxType::class, self::flag('Enabled', 'Enable this Network Range.'))
            ->add('enable_netbios', CheckboxType::class, self::flag('Enable NetBIOS', 'Use NetBIOS to attempt to resolve the hostname of up hosts.'))
            ->add('add_to_cacti', CheckboxType::class, self::flag('Automatically Add Devices', 'For any newly discovered Devices that are reachable using SNMP and who match a Device Rule, add them as Devices.'))
            ->add('same_sysname', CheckboxType::class, self::flag('Allow same sysName on different hosts', 'When discovering devices, allow duplicate sysnames to be added on different hosts'))
            ->add('rerun_data_queries', CheckboxType::class, self::flag('Rerun Data Queries', 'If a device previously added is found, rerun its data queries.'))
            ->add('notification_enabled', CheckboxType::class, self::flag('Notification Enabled', 'If checked, when the Automation Network is scanned, a report will be sent to the Notification Email account.'))
            ->add('notification_email', TextType::class, self::text('Notification Email', 'The Email account to be used to send the Notification Email to.', 'notification_email'))
            ->add('notification_fromname', TextType::class, self::text('Notification From Name', 'The Email account name to be used as the senders name for the Notification Email.  If left blank, the default Automation Notification Name is used.', 'notification_fromname'))
            ->add('notification_fromemail', TextType::class, self::text('Notification From Email Address', 'The Email Address to be used as the senders Email for the Notification Email.  If left blank, the default Automation Notification Email Address is used.', 'notification_fromemail'))
            ->add('start_at', TextType::class, self::text('Starting Date/Time', 'What time will this Network discover item start?  Enter it as YYYY-MM-DD HH:MM.', 'start_at'))
            ->add('recur_every', ChoiceType::class, self::choices(
                'Rerun Every',
                'Rerun discovery for this Network Range every X days for a Daily schedule, or every X weeks for a Weekly one.',
                array_combine(DiscoveryNetworkChange::CHOICES['recur_every'], array_map('strval', DiscoveryNetworkChange::CHOICES['recur_every'])),
                false
            ))
            ->add('day_of_week', ChoiceType::class, self::many('Days of Week', 'What Day(s) of the week will this Network Range be discovered.', self::WEEKDAYS))
            ->add('month', ChoiceType::class, self::many('Months of Year', 'What Months(s) of the Year will this Network Range be discovered.', self::MONTHS))
            ->add('day_of_month', ChoiceType::class, self::many(
                'Days of Month',
                'What Day(s) of the Month will this Network Range be discovered.',
                array_combine(range(1, 31), array_map('strval', range(1, 31))) + [32 => 'Last']
            ))
            ->add('monthly_week', ChoiceType::class, self::many('Week(s) of Month', 'What Week(s) of the Month will this Network Range be discovered.', [1 => 'First', 2 => 'Second', 3 => 'Third', 32 => 'Last']))
            ->add('monthly_day', ChoiceType::class, self::many('Day(s) of Week', 'What Day(s) of the week will this Network Range be discovered.', self::WEEKDAYS))
            ->add('snmp_id', ChoiceType::class, self::records('SNMP Options', 'Select the SNMP Options to use for discovery of this Network Range.', $snmp))
            ->add('ping_method', ChoiceType::class, self::choices('Ping Method', 'The type of ping packet to send.', [1 => 'ICMP Ping', 3 => 'TCP Ping', 5 => 'TCP Ping Closed', 2 => 'UDP Ping', 4 => 'SNMP Get']))
            ->add('ping_port', IntegerType::class, self::number('Ping Port', 'TCP or UDP port to attempt connection.'))
            ->add('ping_timeout', IntegerType::class, self::number('Ping Timeout Value', 'The timeout value to use for host ICMP and UDP pinging.  This host SNMP timeout value applies for SNMP pings.'))
            ->add('ping_retries', IntegerType::class, self::number('Ping Retry Count', 'After an initial failure, the number of ping retries to attempt before failing.'))
            ->add('revision', HiddenType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'discovery_network',
            'csrf_protection' => true,
            'csrf_token_id' => 'automation_network_edit',
            'method' => 'POST',
            'allow_extra_fields' => false,
            'pollers' => [],
            'sites' => [],
            'snmp' => [],
        ]);
        foreach (['pollers', 'sites', 'snmp'] as $option) {
            $resolver->setAllowedTypes($option, 'array');
        }
    }

    public function getBlockPrefix(): string
    {
        return 'discovery_network';
    }

    /** @return array<int, string> */
    private static function names(mixed $records): array
    {
        $names = [];
        foreach (is_array($records) ? $records : [] as $id => $name) {
            if (is_int($id) && is_string($name)) {
                $names[$id] = $name;
            }
        }
        return $names;
    }

    /**
     * @param array<string, string> $attr
     * @return array<string, mixed>
     */
    private static function text(string $label, string $help, string $column, array $attr = []): array
    {
        return ['label' => $label, 'help' => $help, 'required' => false, 'trim' => false, 'empty_data' => '',
            'attr' => ['maxlength' => DiscoveryNetworkChange::TEXT[$column]] + $attr];
    }

    /** @return array<string, mixed> */
    private static function flag(string $label, string $help): array
    {
        return ['label' => $label, 'help' => $help, 'required' => false];
    }

    /** @return array<string, mixed> */
    private static function number(string $label, string $help): array
    {
        return ['label' => $label, 'help' => $help, 'required' => false, 'attr' => ['size' => 5]];
    }

    /**
     * @param array<int, string> $records names by ID, shown untranslated
     * @return array<string, mixed>
     */
    private static function records(string $label, string $help, array $records): array
    {
        return ['label' => $label, 'help' => $help, 'choices' => array_keys($records), 'choice_label' => static fn(int $id): string => $records[$id],
            'choice_translation_domain' => false];
    }

    /**
     * @param array<int, string> $labels
     * @return array<string, mixed>
     */
    private static function choices(string $label, string $help, array $labels, bool $translate = true): array
    {
        return ['label' => $label, 'help' => $help, 'choices' => array_keys($labels), 'choice_label' => static fn(int $value): string => $labels[$value],
            'choice_translation_domain' => $translate ? 'discovery_network' : false];
    }

    /**
     * @param list<int> $values
     * @param callable(int): array{string, int} $describe the message and its %count%
     * @return array<string, mixed>
     */
    private static function counted(string $label, string $help, array $values, callable $describe): array
    {
        return ['label' => $label, 'help' => $help, 'choices' => $values, 'choice_label' => static fn(int $value): string => $describe($value)[0],
            'choice_translation_parameters' => static fn(int $value): array => ['%count%' => $describe($value)[1]], 'choice_translation_domain' => 'discovery_network'];
    }

    /**
     * @param array<int, string> $labels
     * @return array<string, mixed>
     */
    private static function many(string $label, string $help, array $labels): array
    {
        return self::choices($label, $help, $labels) + ['multiple' => true, 'expanded' => true, 'required' => false];
    }
}
