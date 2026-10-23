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
use Kadupul\Automation\Infrastructure\Symfony\Form\AutomationTemplateType;
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

final class AutomationTemplateEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];

    #[Route('/automation/templates/new', name: 'automation_template_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $templates = $store->deviceTemplates();
            $availability = $store->defaultAvailability();
        } catch (AutomationTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load automation templates. Reload before retrying.', [], 'automation'), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, $templates, $availability, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/templates/{id}/edit', name: 'automation_template_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, AutomationTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'automation'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $template = $store->find($id);
            $templates = $store->deviceTemplates();
        } catch (AutomationTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load automation templates. Reload before retrying.', [], 'automation'), 502, self::HEADERS);
        }
        if ($template === null) {
            return new Response($translator->trans('Automation template not found.', [], 'automation'), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $template, $templates, $template->availabilityMethod, $request, $store, $forms, $twig, $urls, $translator);
    }

    /** @param array<int, string> $templates */
    private function edit(int $actorId, ?AutomationTemplate $template, array $templates, int $availability, Request $request, AutomationTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        // A template whose device template was deleted opens without a choice, so
        // saving it cannot silently point it at whichever device template is first.
        $hostTemplate = $template !== null && isset($templates[$template->hostTemplate]) ? $template->hostTemplate : null;
        $form = $forms->create(AutomationTemplateType::class, [
            'host_template' => $hostTemplate,
            'availability_method' => $availability,
            'sysDescr' => $template?->sysDescr ?? '',
            'sysName' => $template?->sysName ?? '',
            'sysOid' => $template?->sysOid ?? '',
            'revision' => $template?->revision ?? '',
        ], [
            'device_templates' => $templates,
            'action' => $template === null ? $urls->generate('automation_template_create') : $urls->generate('automation_template_edit', ['id' => $template->id]),
            'csrf_token_id' => $template === null ? 'automation_template_create' : 'automation_template_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'automation')));
            }
            $data = $form->getData();
            if ($form->isValid() && !is_int($data['host_template'] ?? null)) {
                $form->get('host_template')->addError(new FormError($translator->trans('Choose a Device Template that exists.', [], 'automation')));
            }
            if ($form->isValid()) {
                try {
                    $store->save($actorId, $template?->id, $data['host_template'], (int) $data['availability_method'], (string) $data['sysDescr'], (string) $data['sysName'], (string) $data['sysOid'], $template === null ? null : (string) $data['revision']);
                    return new RedirectResponse($urls->generate('automation_templates', ['saved' => 1]), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'automation')));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : 422;
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'automation')));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/automation_template_edit.html.twig', [
            'template' => $template,
            'deviceTemplate' => $template === null ? null : ($templates[$template->hostTemplate] ?? null),
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    private function denied(AutomationTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'automation'), $error->unauthenticated ? 401 : 403, self::HEADERS);
    }
}
