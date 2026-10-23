<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\AutomationTemplateStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\AutomationTemplate;
use Kadupul\Automation\Infrastructure\Symfony\Form\AutomationTemplateDeletionType;
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

final class AutomationTemplateDeleteController
{
    #[Route('/automation/templates/delete', name: 'automation_template_delete', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $ids = self::ids($request->query->all()['ids'] ?? null);
            $selected = $store->findMany($ids);
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid automation template selection.', [], 'automation'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load automation templates. Reload before retrying.', [], 'automation'), 502, $headers);
        }
        if (count($selected) !== count($ids)) {
            return new Response($translator->trans('One or more selected automation templates no longer exist.', [], 'automation'), 404, $headers);
        }
        $form = $forms->create(AutomationTemplateDeletionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode(array_map(static fn(AutomationTemplate $template): string => $template->revision, $selected), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ], [
            'action' => $urls->generate('automation_template_delete', ['ids' => $ids]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $submitted = json_decode((string) $data['selection'], false, 8, JSON_THROW_ON_ERROR);
                    $submittedRevisions = json_decode((string) $data['revisions'], true, 8, JSON_THROW_ON_ERROR);
                    if ($submitted !== $ids || !is_array($submittedRevisions)) {
                        throw new \InvalidArgumentException('Invalid automation template selection.');
                    }
                    $expected = [];
                    foreach ($submittedRevisions as $id => $revision) {
                        $expected[(int) $id] = $revision;
                    }
                    $store->delete($actor->id, $ids, $expected);
                    return new RedirectResponse($urls->generate('automation_templates', ['deleted' => 1]), 303, $headers);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException | \JsonException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                    $message = $error instanceof \JsonException ? 'Invalid automation template selection.' : $error->getMessage();
                    $form->addError(new FormError($translator->trans($message, [], 'automation')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Check the automation template list before retrying.', [], 'automation')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/automation_template_delete.html.twig', [
            'templates' => array_values($selected),
            'form' => $form->createView(),
        ]), $status, $headers);
    }

    /** @return list<int> */
    private static function ids(mixed $raw): array
    {
        if (!is_array($raw) || $raw === [] || count($raw) > AutomationTemplateStore::MAX_SELECTION || !array_is_list($raw)) {
            throw new \InvalidArgumentException('Invalid automation template selection.');
        }
        $ids = [];
        foreach ($raw as $id) {
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,7}\z/D', $id) !== 1) {
                throw new \InvalidArgumentException('Invalid automation template selection.');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
