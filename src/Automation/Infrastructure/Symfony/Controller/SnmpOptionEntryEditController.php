<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\SnmpOptionStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\SnmpOptionEntry;
use Kadupul\Automation\Domain\SnmpOptionEntryChange;
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionEntryType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SnmpOptionEntryEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const array INTEGERS = ['snmp_port', 'snmp_timeout', 'snmp_retries'];
    private const array SETTINGS = ['snmp_version', 'snmp_port', 'snmp_timeout', 'snmp_retries', 'max_oids', 'bulk_walk_size', 'snmp_auth_protocol', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id'];

    #[Route('/automation/snmp-options/{id}/entries/new', name: 'automation_snmp_entry_create', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function create(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, self::HEADERS);
        }
        return $this->handle($actor->id, $id, null, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/snmp-options/{id}/entries/{entry}/edit', name: 'automation_snmp_entry_edit', requirements: ['id' => '[1-9][0-9]{0,9}', 'entry' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, int $entry, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, self::HEADERS);
        }
        return $this->handle($actor->id, $id, $entry, $request, $store, $forms, $twig, $urls, $translator);
    }

    private function handle(int $actorId, int $setId, ?int $entryId, Request $request, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        try {
            $set = $store->findSet($setId);
            $entry = $set === null || $entryId === null ? null : $store->findEntry($set->id, $entryId);
            $defaults = $entryId === null ? $store->entryDefaults() : [];
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, self::HEADERS);
        }
        if ($set === null) {
            return new Response($translator->trans('SNMP Option Set not found.', [], 'automation'), 404, self::HEADERS);
        }
        if ($entryId !== null && $entry === null) {
            return new Response($translator->trans('SNMP entry not found.', [], 'automation'), 404, self::HEADERS);
        }
        return $this->edit($actorId, $set, $entry, $defaults, $request, $store, $forms, $twig, $urls, $translator);
    }

    /** @param array<string, int|string> $defaults */
    private function edit(int $actorId, SnmpOptionSet $set, ?SnmpOptionEntry $entry, array $defaults, Request $request, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $settings = $entry?->settings() ?? $defaults;
        $initial = $settings + array_fill_keys(array_keys(SnmpOptionEntryChange::CREDENTIALS), '') + ['revision' => $entry->revision ?? ''];
        if ($entry !== null) {
            $initial['keep_credentials'] = true;
        }
        $form = $forms->create(SnmpOptionEntryType::class, $initial, [
            'existing' => $entry !== null,
            'action' => $entry === null
                ? $urls->generate('automation_snmp_entry_create', ['id' => $set->id])
                : $urls->generate('automation_snmp_entry_edit', ['id' => $set->id, 'entry' => $entry->id]),
            'csrf_token_id' => $entry === null ? 'automation_snmp_entry_create' : 'automation_snmp_entry_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            $data = SnmpOptionSetEditController::data($form);
            foreach (self::INTEGERS as $field) {
                if ($form->get($field)->isValid() && !is_int($data[$field] ?? null)) {
                    $form->get($field)->addError(new FormError($translator->trans('Enter a whole number.', [], 'automation')));
                }
            }
            if ($form->isValid()) {
                try {
                    $change = new SnmpOptionEntryChange(
                        array_intersect_key($data, array_flip(self::SETTINGS)),
                        $entry !== null && $data['keep_credentials'] === true,
                        array_intersect_key($data, SnmpOptionEntryChange::CREDENTIALS),
                    );
                    $store->saveEntry($actorId, $set->id, $entry?->id, $change, $entry === null ? null : SnmpOptionSetEditController::field($data, 'revision'));
                    // Legacy returned to the set after saving an entry.
                    return new RedirectResponse($urls->generate('automation_snmp_option_edit', ['id' => $set->id, 'entry' => 'saved']), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, self::HEADERS);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'automation')));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'not found') ? 404 : 422);
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'automation')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/snmp_option_entry_edit.html.twig', [
            'set' => $set,
            'entry' => $entry,
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }
}
