<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Symfony\Controller;

use Kadupul\Alerting\Application\Port\Clock;
use Kadupul\Alerting\Application\Port\IncidentAccess;
use Kadupul\Alerting\Application\Port\IncidentCatalog;
use Kadupul\Alerting\Application\Port\IncidentStore;
use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\Query\ViewAlertingStatus;
use Kadupul\Alerting\Application\ReadModel\IncidentDetail;
use Kadupul\Alerting\Infrastructure\Symfony\Form\IncidentAcknowledgementType;
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

/**
 * Alert incidents (ADR 0011 decision 12). Every route takes the console
 * actor, then IncidentAccess::authorize(), before it reads the request or
 * any incident. An incident whose recorded series the actor cannot view
 * is not found, by id as in the list. The detail path is the one the
 * notification mail names (NotificationMail::INCIDENT_PATH).
 */
final class IncidentController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/alerting/incidents', name: 'alerting_incident_list', methods: ['GET', 'HEAD'])]
    public function list(Request $request, ConsoleAccess $console, IncidentAccess $access, IncidentCatalog $incidents, ViewAlertingStatus $status, Environment $twig, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $filter = $query['status'] ?? 'active';
            unset($query['status']);
            if (!in_array($filter, ['active', 'all'], true)) {
                throw new \InvalidArgumentException('Invalid incident filter.');
            }
            $found = $incidents->page($actor->id, $filter === 'active', RuleController::page($query, []));
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid alert incident list request.', [], 'alerting'), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load alert incidents. Reload before retrying.', [], 'alerting'), 502, self::HEADERS);
        }

        return new Response($request->isMethod('HEAD') ? '' : $twig->render('alerting/incidents.html.twig', [
            'page' => $found,
            'status' => $status(),
        ]), 200, self::HEADERS);
    }

    #[Route('/alerting/incidents/{id}', name: 'alerting_incident_show', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    public function show(int $id, Request $request, ConsoleAccess $console, IncidentAccess $access, IncidentCatalog $incidents, ViewAlertingStatus $status, Clock $clock, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            if (array_diff_key($query, ['acknowledged' => true]) !== [] || !in_array($query['acknowledged'] ?? '1', ['1'], true)) {
                throw new \InvalidArgumentException('Invalid incident request.');
            }
            $detail = $id <= RuleController::MAX_ID ? $incidents->find($actor->id, $id, $clock->now()->getTimestamp()) : null;
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid alert incident request.', [], 'alerting'), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the alert incident. Reload before retrying.', [], 'alerting'), 502, self::HEADERS);
        }
        if ($detail === null) {
            return new Response($translator->trans('Alert incident not found.', [], 'alerting'), 404, self::HEADERS);
        }
        $form = $forms->create(IncidentAcknowledgementType::class, null, ['action' => $urls->generate('alerting_incident_acknowledge', ['id' => $id])]);

        return $this->render($detail, $form, $status, $twig, $request->isMethod('HEAD'), ($query['acknowledged'] ?? null) === '1', 200);
    }

    #[Route('/alerting/incidents/{id}/acknowledge', name: 'alerting_incident_acknowledge', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['POST'])]
    public function acknowledge(int $id, Request $request, ConsoleAccess $console, IncidentAccess $access, IncidentCatalog $incidents, IncidentStore $store, ViewAlertingStatus $status, Clock $clock, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $detail = $id <= RuleController::MAX_ID ? $incidents->find($actor->id, $id, $clock->now()->getTimestamp()) : null;
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the alert incident. Reload before retrying.', [], 'alerting'), 502, self::HEADERS);
        }
        if ($detail === null) {
            return new Response($translator->trans('Alert incident not found.', [], 'alerting'), 404, self::HEADERS);
        }
        $form = $forms->create(IncidentAcknowledgementType::class, null, ['action' => $urls->generate('alerting_incident_acknowledge', ['id' => $id])]);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            $form->addError(new FormError($translator->trans('The form was not submitted. Reload before retrying.', [], 'alerting')));
        }
        $code = 422;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $outcome = $store->acknowledge($actor->id, $id, $clock->now()->getTimestamp());
                if ($outcome === AcknowledgeOutcome::Acknowledged || $outcome === AcknowledgeOutcome::AlreadyAcknowledged) {
                    return new RedirectResponse($urls->generate('alerting_incident_show', ['id' => $id, 'acknowledged' => 1]), 303, self::HEADERS);
                }
                if ($outcome === AcknowledgeOutcome::NotFound) {
                    return new Response($translator->trans('Alert incident not found.', [], 'alerting'), 404, self::HEADERS);
                }
                $code = 409;
                $form->addError(new FormError($translator->trans('This incident has ended and cannot be acknowledged.', [], 'alerting')));
            } catch (AlertingAccessDenied $error) {
                return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
            } catch (\Throwable) {
                $code = 502;
                $form->addError(new FormError($translator->trans('Acknowledgement outcome is uncertain. Reload the incident before retrying.', [], 'alerting')));
            }
        }

        return $this->render($detail, $form, $status, $twig, false, false, $code);
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     */
    private function render(IncidentDetail $detail, FormInterface $form, ViewAlertingStatus $status, Environment $twig, bool $head, bool $acknowledged, int $code): Response
    {
        return new Response($head ? '' : $twig->render('alerting/incident.html.twig', [
            'detail' => $detail,
            'incident' => $detail->view->incident,
            'rule' => $detail->view->rule,
            'form' => $form->createView(),
            'status' => $status(),
            'acknowledged' => $acknowledged,
        ]), $code, self::HEADERS);
    }
}
