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
use Kadupul\Automation\Domain\SnmpOptionEntries;
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionEntryConfirmType;
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionSetType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Creates and renames SNMP Option Sets; an existing set's page also lists and orders its entries. */
final class SnmpOptionSetEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/automation/snmp-options/new', name: 'automation_snmp_option_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, null, null, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/snmp-options/{id}/edit', name: 'automation_snmp_option_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $set = $store->findSet($id);
            $entries = $set === null ? null : $store->entries($set->id);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, ['Cache-Control' => 'private, no-store']);
        }
        if ($set === null) {
            return new Response($translator->trans('SNMP Option Set not found.', [], 'automation'), 404, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, $set, $entries, $request, $store, $forms, $twig, $urls, $translator);
    }

    private function edit(int $actorId, ?SnmpOptionSet $set, ?SnmpOptionEntries $entries, Request $request, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $form = $forms->create(SnmpOptionSetType::class, ['name' => $set->name ?? '', 'revision' => $set->revision ?? ''], [
            'action' => $set === null ? $urls->generate('automation_snmp_option_create') : $urls->generate('automation_snmp_option_edit', ['id' => $set->id]),
            'csrf_token_id' => $set === null ? 'automation_snmp_option_create' : 'automation_snmp_option_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            if ($form->isValid()) {
                $data = self::data($form);
                try {
                    $saved = $store->saveSet($actorId, $set?->id, self::field($data, 'name'), $set === null ? null : self::field($data, 'revision'));
                    // Legacy returned to the set after saving, so entries can be added to a new one.
                    return new RedirectResponse($urls->generate('automation_snmp_option_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'automation')));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'not found') ? 404 : 422);
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'automation')));
                }
            }
        }
        $moves = [];
        foreach ($entries->entries ?? [] as $entry) {
            $moves[$entry->id] = $forms->createNamed('automation_snmp_entry_order_' . $entry->id, SnmpOptionEntryConfirmType::class, ['revision' => $entries->order ?? ''])->createView();
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/snmp_option_edit.html.twig', [
            'set' => $set,
            'entries' => $entries,
            'moves' => $moves,
            'form' => $form->createView(),
            'notice' => self::notice($request->query->all()),
        ]), $status, self::HEADERS);
    }

    /** @param array<string, mixed> $query read without InputBag::get(), which throws on arrays */
    private static function notice(array $query): ?string
    {
        if (($query['saved'] ?? null) === '1') {
            return 'saved';
        }
        return match ($query['entry'] ?? null) {
            'saved' => 'entry_saved',
            'deleted' => 'entry_deleted',
            'moved' => 'entry_moved',
            default => null,
        };
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @return array<string, mixed>
     */
    public static function data(FormInterface $form): array
    {
        $data = $form->getData();
        if (!is_array($data)) {
            throw new \UnexpectedValueException('The submitted form has no data.');
        }
        $fields = [];
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $fields[$key] = $value;
            }
        }
        return $fields;
    }

    /** @param array<string, mixed> $data */
    public static function field(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    public static function message(string $message, int $status, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans($message, [], 'automation'), $status, self::HEADERS);
    }

    public static function denied(AutomationTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return self::message('Access denied.', $error->unauthenticated ? 401 : 403, $translator);
    }
}
