<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Kadupul\CollectorAdministration\Application\Command\SaveCollector;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Application\Query\FindCollector;
use Kadupul\CollectorAdministration\Domain\CollectorDetails;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\Form\CollectorEditType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CollectorEditController
{
    #[Route('/collectors/new', name: 'collector_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, SaveCollector $save, ConsoleAccess $access, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, CsrfTokenManagerInterface $tokens, TranslatorInterface $translator): Response
    {
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            $headers = ['Cache-Control' => 'private, no-store'];
            return new Response($translator->trans('Access denied.', [], 'collectors'), $actor === null ? 401 : 403, $headers);
        }
        return $this->edit(null, null, $request, $save, $forms, $twig, $urls, $tokens, $translator);
    }

    #[Route('/collectors/{id}/edit', name: 'collector_edit', requirements: ['id' => '[1-9][0-9]{0,4}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, FindCollector $find, SaveCollector $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, CsrfTokenManagerInterface $tokens, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $collector = $find($id);
        } catch (CollectorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($collector === null) {
            return new Response($translator->trans('Collector not found.', [], 'collectors'), 404, $headers);
        }
        return $this->edit($id, $collector, $request, $save, $forms, $twig, $urls, $tokens, $translator);
    }

    private function edit(?int $id, ?CollectorDetails $collector, Request $request, SaveCollector $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, CsrfTokenManagerInterface $tokens, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $remote = $id !== 1;
        $settings = $collector?->settings ?? [];
        $data = [
            'name' => $collector?->name ?? 'New Data Collector',
            'hostname' => $collector?->hostname ?? '',
            'timezone' => ($collector?->timezone ?? '') !== '' ? $collector->timezone : date_default_timezone_get(),
            'notes' => $collector?->notes ?? '',
            'processes' => $collector?->processes ?? 1,
            'threads' => $collector?->threads ?? 1,
            'revision' => $collector?->revision ?? '',
        ];
        if ($remote) {
            $data += [
                'sync_interval' => $collector?->syncInterval ?? 3600,
                'dbdefault' => (string) ($settings['dbdefault'] ?? ''),
                'dbhost' => (string) ($settings['dbhost'] ?? ''),
                'dbuser' => (string) ($settings['dbuser'] ?? ''),
                // Never hydrate or render the stored database password.
                'dbpass' => '',
                'dbport' => (int) ($settings['dbport'] ?? 3306),
                'dbretries' => (int) ($settings['dbretries'] ?? 5),
                'dbssl' => ($settings['dbssl'] ?? '') === 'on',
                'dbsslkey' => (string) ($settings['dbsslkey'] ?? ''),
                'dbsslcert' => (string) ($settings['dbsslcert'] ?? ''),
                'dbsslca' => (string) ($settings['dbsslca'] ?? ''),
            ];
        }
        $route = $id === null ? 'collector_create' : 'collector_edit';
        $form = $forms->create(CollectorEditType::class, $data, [
            'action' => $urls->generate($route, $id === null ? [] : ['id' => $id]),
            'remote' => $remote,
            'csrf_token_id' => $id === null ? 'collector_create' : 'collector_edit',
            'attr' => [
                'data-timezone-url' => $urls->generate('collector_timezones'),
                'data-connection-url' => $urls->generate('collector_connection_test'),
                'data-collector-id' => $id ?? '',
            ],
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'collectors')));
            }
            $this->validateFields($form, $remote, $translator);
            if ($form->isValid()) {
                $values = $form->getData();
                if (!$remote) {
                    unset($values['sync_interval']);
                } else {
                    $values['dbport'] = (int) ($values['dbport'] ?? 0);
                    $values['dbretries'] = (int) ($values['dbretries'] ?? 0);
                }
                try {
                    $savedId = $save($id, $values);
                    return new RedirectResponse($urls->generate('collector_edit', ['id' => $savedId, 'saved' => 1]), 303, $headers);
                } catch (CollectorAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'collectors')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload the collector before retrying.', [], 'collectors')));
                }
            }
        }
        return new Response($twig->render('collectors/edit.html.twig', [
            'collector' => $collector, 'form' => $form->createView(), 'saved' => $request->query->get('saved') === '1',
            'remote' => $remote,
            'connectionUrl' => $urls->generate('collector_connection_test'),
            'connectionToken' => $tokens->getToken('collector_connection_test')->getValue(),
        ]), $status, $headers);
    }

    private function validateFields(\Symfony\Component\Form\FormInterface $form, bool $remote, TranslatorInterface $translator): void
    {
        $limits = ['name' => 30, 'hostname' => 100, 'timezone' => 40, 'notes' => 1024];
        if ($remote) {
            $limits += ['dbdefault' => 20, 'dbhost' => 64, 'dbuser' => 20, 'dbpass' => 64, 'dbsslkey' => 255, 'dbsslcert' => 255, 'dbsslca' => 255];
        }
        foreach ($limits as $field => $limit) {
            $value = $form->get($field)->getData();
            if (!is_string($value) || strlen($value) > $limit || preg_match('//u', $value) !== 1 || str_contains($value, "\0")
                || (in_array($field, ['name', 'hostname', 'timezone'], true) && trim($value) === '')) {
                $form->get($field)->addError(new FormError($translator->trans('This value is invalid.', [], 'collectors')));
            }
        }
        foreach (['processes', 'threads'] as $field) {
            $value = $form->get($field)->getData();
            if (!is_int($value) || $value < 0 || $value > 9999) {
                $form->get($field)->addError(new FormError($translator->trans('Enter a whole number from 0 to 9999.', [], 'collectors')));
            }
        }
        if ($remote) {
            if (!in_array($form->get('sync_interval')->getData(), [0, 1800, 3600, 7200, 14400, 28800, 57600, 86400], true)) {
                $form->get('sync_interval')->addError(new FormError($translator->trans('Choose a valid sync interval.', [], 'collectors')));
            }
            foreach (['dbport' => [1, 65535], 'dbretries' => [0, 99999]] as $field => [$minimum, $maximum]) {
                $value = $form->get($field)->getData();
                if (!is_int($value) || $value < $minimum || $value > $maximum) {
                    $form->get($field)->addError(new FormError($translator->trans('Enter a valid number.', [], 'collectors')));
                }
            }
        }
    }
}
