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
use Kadupul\Automation\Domain\SnmpOptionSet;
use Kadupul\Automation\Infrastructure\Symfony\Form\SnmpOptionSetActionType;
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

/** Confirms, then applies, deleting or duplicating the SNMP Option Sets selected on the list. */
final readonly class SnmpOptionSetActionController
{
    private const string INVALID = 'Invalid SNMP Option Set selection.';

    #[Route('/automation/snmp-options/actions/{operation}', name: 'automation_snmp_option_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, SnmpOptionStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load SNMP Option Sets. Reload before retrying.', [], 'automation'), 502, $headers);
        }
        try {
            $ids = self::ids($request->query->all()['ids'] ?? null);
        } catch (\InvalidArgumentException $error) {
            // An empty or oversized selection is told apart from a malformed one, all before any read.
            return SnmpOptionSetEditController::message($error->getMessage(), 400, $translator);
        }
        try {
            $selected = $store->findSets($ids);
        } catch (\Throwable) {
            return SnmpOptionSetEditController::message('Unable to load SNMP Option Sets. Reload before retrying.', 502, $translator);
        }
        if (count($selected) !== count($ids)) {
            return SnmpOptionSetEditController::message('One or more selected SNMP Option Sets no longer exist.', 404, $translator);
        }
        $duplicate = $operation === 'duplicate';
        $form = $forms->create(SnmpOptionSetActionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode(array_map(static fn(SnmpOptionSet $set): string => $set->revision, $selected), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ] + ($duplicate ? ['name_format' => SnmpOptionSet::NAME_FORMAT] : []), [
            'duplicate' => $duplicate,
            'action' => $urls->generate('automation_snmp_option_action', ['operation' => $operation, 'ids' => $ids]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            if ($form->isValid()) {
                try {
                    $data = SnmpOptionSetEditController::data($form);
                    $revisions = self::revisions($ids, SnmpOptionSetEditController::field($data, 'selection'), SnmpOptionSetEditController::field($data, 'revisions'));
                    if ($duplicate) {
                        $store->duplicateSets($actor->id, $ids, $revisions, SnmpOptionSetEditController::field($data, 'name_format'));
                    } else {
                        $store->deleteSets($actor->id, $ids, $revisions);
                    }
                    return new RedirectResponse($urls->generate('admin_snmp_options'), 303, $headers);
                } catch (AutomationTemplateAccessDenied $error) {
                    return SnmpOptionSetEditController::denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'no longer exist') ? 404 : 422);
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'automation')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans($duplicate
                        ? 'Duplicate outcome is uncertain. Check the SNMP Option Set list before retrying.'
                        : 'Delete outcome is uncertain. Check the SNMP Option Set list before retrying.', [], 'automation')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/snmp_option_action.html.twig', [
            'operation' => $operation,
            'sets' => array_values($selected),
            'form' => $form->createView(),
        ]), $status, $headers);
    }

    /**
     * The list submits ids[] in the query string; anything but a short list of decimal IDs is refused.
     *
     * @return list<int> ascending and distinct
     */
    private static function ids(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            throw new \InvalidArgumentException('Select at least one SNMP Option Set.');
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        if (count($raw) > SnmpOptionStore::MAX_SELECTION) {
            throw new \InvalidArgumentException('Select at most 100 SNMP Option Sets at a time.');
        }
        $ids = [];
        foreach ($raw as $id) {
            // automation_snmp.id is an unsigned 32-bit column.
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,9}\z/D', $id) !== 1 || (int) $id > 4294967295) {
                throw new \InvalidArgumentException(self::INVALID);
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * The posted selection must be the one in the URL, with one revision per selected set.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private static function revisions(array $ids, string $selection, string $revisions): array
    {
        try {
            $submitted = json_decode($selection, false, 2, JSON_THROW_ON_ERROR);
            $decoded = json_decode($revisions, true, 2, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        if ($submitted !== $ids || !is_array($decoded) || array_keys($decoded) !== $ids) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        $expected = [];
        foreach ($decoded as $id => $revision) {
            if (!is_string($revision)) {
                throw new \InvalidArgumentException(self::INVALID);
            }
            $expected[(int) $id] = $revision;
        }
        return $expected;
    }
}
