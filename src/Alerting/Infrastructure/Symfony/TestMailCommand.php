<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Symfony;

use Kadupul\Alerting\Application\Command\SendTestMail;
use Kadupul\Alerting\Application\MailDeliveryFailed;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'kadupul:mail:test', description: 'Send one test email using the installation SMTP settings and test recipient.')]
final class TestMailCommand extends Command
{
    public function __construct(private readonly SendTestMail $sendTestMail)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            ($this->sendTestMail)();
        } catch (MailDeliveryFailed $error) {
            $output->writeln('<error>' . $error->getMessage() . '</error>');

            return Command::FAILURE;
        }
        $output->writeln('SMTP server accepted the test email. Inbox delivery is not guaranteed.');

        return Command::SUCCESS;
    }
}
