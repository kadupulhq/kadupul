<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\GraphRuleStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\GraphRule;
use Kadupul\Automation\Domain\GraphRuleChange;
use Kadupul\Automation\Domain\GraphRuleRejected;
use Kadupul\Automation\Infrastructure\Symfony\Form\GraphRuleType;
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

/** The settings of an automation_graph_rules.php rule; its criteria, previews, list and bulk actions remain legacy. */
final class GraphRuleEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'graph_rule';

    #[Route('/automation/graph-rules/new', name: 'automation_graph_rule_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, GraphRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $choices = $store->choices();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the graph rule. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, ['name' => '', 'snmp_query_id' => array_key_first($choices['queries']), 'graph_type_id' => null, 'enabled' => false], $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/graph-rules/{id}/edit', name: 'automation_graph_rule_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, GraphRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
            $rule = $store->find($id);
            $choices = $rule === null ? null : $store->choices();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the graph rule. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        if ($rule === null || $choices === null) {
            return new Response($translator->trans('Graph rule not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $rule, ['name' => $rule->name, 'snmp_query_id' => $rule->snmpQueryId, 'graph_type_id' => $rule->graphTypeId, 'enabled' => $rule->enabled], $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    /**
     * @param array<string, bool|int|string|null> $settings
     * @param array{queries: array<int, string>, graphTypes: array<int, array{query: int, name: string}>} $choices
     */
    private function edit(int $actorId, ?GraphRule $rule, array $settings, array $choices, Request $request, GraphRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $form = $forms->create(GraphRuleType::class, $settings + ['revision' => $rule->revision ?? ''], [
            'queries' => $choices['queries'],
            'graph_types' => $choices['graphTypes'],
            'action' => $rule === null ? $urls->generate('automation_graph_rule_create') : $urls->generate('automation_graph_rule_edit', ['id' => $rule->id]),
            'csrf_token_id' => $rule === null ? 'automation_graph_rule_create' : 'automation_graph_rule_edit',
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
                    $change = new GraphRuleChange(
                        is_string($data['name'] ?? null) ? $data['name'] : '',
                        is_int($data['snmp_query_id'] ?? null) ? $data['snmp_query_id'] : 0,
                        is_int($data['graph_type_id'] ?? null) ? $data['graph_type_id'] : 0,
                        ($data['enabled'] ?? false) === true,
                    );
                    $saved = $store->save($actorId, $rule?->id, $change, $rule === null ? null : (is_string($data['revision'] ?? null) ? $data['revision'] : ''));
                    // Legacy returned to the rule after saving.
                    return new RedirectResponse($urls->generate('automation_graph_rule_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
                } catch (GraphRuleRejected $error) {
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
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/graph_rule_edit.html.twig', [
            'rule' => $rule,
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
