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
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionEntryConfirmType;
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

/** Confirms, then deletes, one entry of an SNMP Option Set. */
final class SnmpOptionEntryDeleteController
{
    #[Route('/automation/snmp-options/{id}/entries/{entry}/delete', name: 'automation_snmp_entry_delete', requirements: ['id' => '[1-9][0-9]{0,9}', 'entry' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, int $entry, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $set = $store->findSet($id);
            $found = $set === null ? null : $store->findEntry($set->id, $entry);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, $headers);
        }
        if ($set === null || $found === null) {
            return SnmpOptionSetEditController::message($set === null ? 'SNMP Option Set not found.' : 'SNMP entry not found.', 404, $translator);
        }
        $form = $forms->create(SnmpOptionEntryConfirmType::class, ['revision' => $found->revision], [
            'action' => $urls->generate('automation_snmp_entry_delete', ['id' => $set->id, 'entry' => $found->id]),
            'csrf_token_id' => 'automation_snmp_entry_delete',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            if ($form->isValid()) {
                try {
                    $store->deleteEntry($actor->id, $set->id, $found->id, SnmpOptionSetEditController::field(SnmpOptionSetEditController::data($form), 'revision'));
                    return new RedirectResponse($urls->generate('automation_snmp_option_edit', ['id' => $set->id, 'entry' => 'deleted']), 303, $headers);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'not found') ? 404 : 422);
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'automation')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Reload the SNMP Option Set before retrying.', [], 'automation')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/snmp_option_entry_delete.html.twig', [
            'set' => $set,
            'entry' => $found,
            'form' => $form->createView(),
        ]), $status, $headers);
    }
}
