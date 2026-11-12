<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\DiscoveryNetworkStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\DiscoveryNetwork;
use Kadupul\Automation\Domain\DiscoveryNetworkChange;
use Kadupul\Automation\Domain\DiscoveryNetworkRejected;
use Kadupul\Automation\Infrastructure\Symfony\Form\DiscoveryNetworkType;
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

/** The automation_networks.php edit form; the list, its bulk actions and discovery runs remain legacy. */
final class DiscoveryNetworkEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'discovery_network';

    #[Route('/automation/networks/new', name: 'automation_network_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, DiscoveryNetworkStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $defaults = $store->defaults();
            $choices = $store->choices();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the discovery network. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, $defaults, $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/networks/{id}/edit', name: 'automation_network_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, DiscoveryNetworkStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $network = $store->find($id);
            $choices = $network === null ? null : $store->choices();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the discovery network. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        if ($network === null || $choices === null) {
            return new Response($translator->trans('Discovery network not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $network, $network->settings, $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    /**
     * @param array<string, bool|int|string|list<int>> $settings
     * @param array{pollers: array<int, string>, sites: array<int, string>, snmp: array<int, string>} $choices
     */
    private function edit(int $actorId, ?DiscoveryNetwork $network, array $settings, array $choices, Request $request, DiscoveryNetworkStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $form = $forms->create(DiscoveryNetworkType::class, $settings + ['revision' => $network->revision ?? ''], $choices + [
            'action' => $network === null ? $urls->generate('automation_network_create') : $urls->generate('automation_network_edit', ['id' => $network->id]),
            'csrf_token_id' => $network === null ? 'automation_network_create' : 'automation_network_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], self::DOMAIN)));
            }
            if ($form->isValid()) {
                $data = self::data($form);
                try {
                    $change = new DiscoveryNetworkChange(array_diff_key($data, ['revision' => true]));
                    $saved = $store->save($actorId, $network?->id, $change, $network === null ? null : (is_string($data['revision'] ?? null) ? $data['revision'] : ''));
                    // Legacy returned to the network after saving.
                    return new RedirectResponse($urls->generate('automation_network_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
                } catch (DiscoveryNetworkRejected $error) {
                    $target = $error->field !== null && $form->has($error->field) ? $form->get($error->field) : $form;
                    $target->addError(new FormError($translator->trans($error->getMessage(), $error->parameters, self::DOMAIN)));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], self::DOMAIN)));
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'not found') ? 404 : 422);
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], self::DOMAIN)));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/discovery_network_edit.html.twig', [
            'network' => $network,
            'form' => $form->createView(),
            // Read without InputBag::get(), which throws on arrays.
            'saved' => ($request->query->all()['saved'] ?? null) === '1',
        ]), $status, self::HEADERS);
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     * @return array<string, mixed>
     */
    private static function data(FormInterface $form): array
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
}
