<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Infrastructure\Symfony\Controller;

use Kadupul\Automation\Application\Port\AutomationTemplateAccess;
use Kadupul\Automation\Application\Port\TreeRuleStore;
use Kadupul\Automation\Application\Query\AutomationTemplateAccessDenied;
use Kadupul\Automation\Domain\TreeRule;
use Kadupul\Automation\Domain\TreeRuleChange;
use Kadupul\Automation\Domain\TreeRuleRejected;
use Kadupul\Automation\Infrastructure\Symfony\Form\TreeRuleType;
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

/** The settings of an automation_tree_rules.php rule; its criteria, previews, list and bulk actions remain legacy. */
final class TreeRuleEditController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'tree_rule';

    #[Route('/automation/tree-rules/new', name: 'automation_tree_rule_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, TreeRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
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
            return new Response($translator->trans('Unable to load the tree rule. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        return $this->edit($actor->id, null, ['name' => '', 'tree_id' => array_key_first($choices['trees']), 'tree_item_id' => 0, 'leaf_type' => 2, 'host_grouping_type' => 1, 'enabled' => false], $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/automation/tree-rules/{id}/edit', name: 'automation_tree_rule_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, TreeRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
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
            return new Response($translator->trans('Unable to load the tree rule. Reload before retrying.', [], self::DOMAIN), 502, self::HEADERS);
        }
        if ($rule === null || $choices === null) {
            return new Response($translator->trans('Tree rule not found.', [], self::DOMAIN), 404, self::HEADERS);
        }
        return $this->edit($actor->id, $rule, ['name' => $rule->name, 'tree_id' => $rule->treeId, 'tree_item_id' => $rule->treeItemId, 'leaf_type' => $rule->leafType,
            'host_grouping_type' => $rule->hostGroupingType, 'enabled' => $rule->enabled, 'remove_graph_criteria' => false], $choices, $request, $store, $forms, $twig, $urls, $translator);
    }

    /**
     * @param array<string, bool|int|string|null> $settings
     * @param array{trees: array<int, string>, headers: array<int, array{tree: int, title: string, depth: int}>} $choices
     */
    private function edit(int $actorId, ?TreeRule $rule, array $settings, array $choices, Request $request, TreeRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $form = $forms->create(TreeRuleType::class, $settings + ['revision' => $rule->revision ?? ''], [
            'trees' => $choices['trees'],
            'headers' => $choices['headers'],
            'existing' => $rule !== null,
            'action' => $rule === null ? $urls->generate('automation_tree_rule_create') : $urls->generate('automation_tree_rule_edit', ['id' => $rule->id]),
            'csrf_token_id' => $rule === null ? 'automation_tree_rule_create' : 'automation_tree_rule_edit',
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
                    $change = new TreeRuleChange(
                        is_string($data['name'] ?? null) ? $data['name'] : '',
                        self::int($data, 'tree_id'),
                        self::int($data, 'tree_item_id'),
                        self::int($data, 'leaf_type'),
                        self::int($data, 'host_grouping_type'),
                        ($data['enabled'] ?? false) === true,
                        ($data['remove_graph_criteria'] ?? false) === true,
                    );
                    $saved = $store->save($actorId, $rule?->id, $change, $rule === null ? null : (is_string($data['revision'] ?? null) ? $data['revision'] : ''));
                    // Legacy returned to the rule after saving.
                    return new RedirectResponse($urls->generate('automation_tree_rule_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], self::DOMAIN), $error->unauthenticated ? 401 : 403, self::HEADERS);
                } catch (TreeRuleRejected $error) {
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
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/tree_rule_edit.html.twig', [
            'rule' => $rule,
            'form' => $form->createView(),
            // Read without InputBag::get(), which throws on arrays.
            'saved' => ($request->query->all()['saved'] ?? null) === '1',
        ]), $status, self::HEADERS);
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $field): int
    {
        // An unselected choice is null; the domain refuses it on its field.
        return is_int($data[$field] ?? null) ? $data[$field] : -1;
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
