<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Symfony\Controller;

use Kadupul\CollectorAdministration\Application\Command\ExecuteCollectorBulkAction;
use Kadupul\CollectorAdministration\Application\Command\PrepareCollectorBulkAction;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;
use Kadupul\CollectorAdministration\Infrastructure\Symfony\Form\CollectorBulkActionType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CollectorBulkActionController
{
    #[Route('/collectors/actions/{action}', name: 'collector_action', requirements: ['action' => 'delete|disable|enable|full-sync|clear-statistics'], methods: ['GET', 'POST'])]
    public function __invoke(
        string $action,
        Request $request,
        PrepareCollectorBulkAction $prepare,
        ExecuteCollectorBulkAction $execute,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        TranslatorInterface $translator,
        ConsoleAccess $access
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $actor === null ? 401 : 403, $headers);
        }
        try {
            $operation = CollectorBulkAction::from($action);
            if ($request->isMethod('GET')) {
                $query = $request->query->all();
                if (array_diff(array_keys($query), ['ids']) !== [] || !is_array($query['ids'] ?? null)) {
                    throw new \InvalidArgumentException('Invalid data collector selection.');
                }
                $selection = new CollectorSelection(array_values($query['ids']));
                $collectors = $prepare($operation, $selection);
            } else {
                $selection = new CollectorSelection($this->postSelection($request));
                $collectors = $prepare($operation, $selection);
            }
        } catch (CollectorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\OutOfBoundsException) {
            return new Response($translator->trans('One or more selected data collectors no longer exist.', [], 'collectors'), 404, $headers);
        } catch (\InvalidArgumentException|\ValueError|\JsonException) {
            return new Response($translator->trans('Invalid data collector selection.', [], 'collectors'), 400, $headers);
        }

        $form = $forms->create(CollectorBulkActionType::class, [
            'selection' => json_encode($selection->ids, JSON_THROW_ON_ERROR),
        ], [
            'action' => $urls->generate('collector_action', ['action' => $operation->value, 'ids' => $selection->ids]),
        ]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'collectors')));
            }
            if ($form->isValid()) {
                try {
                    $posted = json_decode((string) $form->getData()['selection'], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($posted) || (new CollectorSelection(array_values($posted)))->ids !== $selection->ids) {
                        throw new \InvalidArgumentException();
                    }
                } catch (\JsonException|\InvalidArgumentException) {
                    return new Response($translator->trans('Invalid data collector selection.', [], 'collectors'), 400, $headers);
                }
                try {
                    $result = $execute($operation, $selection);
                    if ($result['failed'] !== []) {
                        return new Response($twig->render('collectors/bulk_action.html.twig', [
                            'action' => $operation,
                            'collectors' => $collectors,
                            'form' => $form->createView(),
                            'result' => $result,
                            'error' => $translator->trans('Some data collector operations failed. Review the results before retrying.', [], 'collectors'),
                        ]), 502, $headers);
                    }
                    return new RedirectResponse($urls->generate('collector_list'), 303, $headers);
                } catch (CollectorAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'collectors'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\Throwable) {
                    return new Response($translator->trans('The data collector operation outcome is unknown. Check collector state before retrying.', [], 'collectors'), 502, $headers);
                }
            }
        }

        return new Response($twig->render('collectors/bulk_action.html.twig', [
            'action' => $operation,
            'collectors' => $collectors,
            'form' => $form->createView(),
            'result' => null,
            'error' => null,
        ]), $request->isMethod('POST') ? 422 : 200, $headers);
    }

    private function postSelection(Request $request): array
    {
        $query = $request->query->all();
        if (array_diff(array_keys($query), ['ids']) !== [] || !is_array($query['ids'] ?? null)) {
            throw new \InvalidArgumentException('Invalid confirmation context.');
        }
        $confirmed = new CollectorSelection(array_values($query['ids']));
        $post = $request->request->all();
        if (array_diff(array_keys($post), ['collector_bulk_action', 'collector_bulk_action_token']) !== []) {
            throw new \InvalidArgumentException('Unexpected form fields.');
        }
        $formData = $post['collector_bulk_action'] ?? null;
        if (!is_array($formData) || array_diff(array_keys($formData), ['selection', '_token']) !== [] || !is_string($formData['selection'] ?? null)) {
            throw new \InvalidArgumentException('Invalid form data.');
        }
        $selection = json_decode($formData['selection'], true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($selection) || (new CollectorSelection(array_values($selection)))->ids !== $confirmed->ids) {
            throw new \InvalidArgumentException('Invalid form data.');
        }
        return array_values($selection);
    }
}
