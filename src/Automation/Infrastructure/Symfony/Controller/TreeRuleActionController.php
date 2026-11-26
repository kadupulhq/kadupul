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
use Kadupul\Automation\Infrastructure\Symfony\Form\TreeRuleActionType;
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

/** Confirms, then applies, deleting, duplicating, enabling or disabling the Tree Rules selected on the list. */
final readonly class TreeRuleActionController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string DOMAIN = 'tree_rule';
    private const string INVALID = 'Invalid Tree Rule selection.';
    private const string UNAVAILABLE = 'Unable to load Tree Rules. Reload before retrying.';

    #[Route('/automation/tree-rules/actions/{operation}', name: 'automation_tree_rule_action', requirements: ['operation' => 'delete|duplicate|enable|disable'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, ConsoleAccess $console, AutomationTemplateAccess $access, TreeRuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'tree_rule'), 401, self::HEADERS);
        }
        try {
            $actor = $access->authorize();
        } catch (AutomationTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'tree_rule'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Tree Rules. Reload before retrying.', [], 'tree_rule'), 502, self::HEADERS);
        }
        try {
            $ids = self::ids($request->query->all()['ids'] ?? null);
        } catch (\InvalidArgumentException $error) {
            // An empty or oversized selection is told apart from a malformed one, all before any read.
            return self::message($error->getMessage(), 400, $translator);
        }
        try {
            $selected = $store->findRules($ids);
        } catch (\Throwable) {
            return self::message(self::UNAVAILABLE, 502, $translator);
        }
        if (count($selected) !== count($ids)) {
            return self::message('One or more selected Tree Rules no longer exist.', 404, $translator);
        }
        $duplicate = $operation === 'duplicate';
        $form = $forms->create(TreeRuleActionType::class, [
            'selection' => json_encode($ids, JSON_THROW_ON_ERROR),
            'revisions' => json_encode(array_map(static fn(TreeRule $rule): string => $rule->revision, $selected), JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT),
        ] + ($duplicate ? ['name_format' => TreeRule::NAME_FORMAT] : []), [
            'duplicate' => $duplicate,
            'action' => $urls->generate('automation_tree_rule_action', ['operation' => $operation, 'ids' => $ids]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'tree_rule')));
            }
            if ($form->isValid()) {
                try {
                    $data = TreeRuleEditController::data($form);
                    $revisions = self::revisions($ids, self::field($data, 'selection'), self::field($data, 'revisions'));
                    match ($operation) {
                        'duplicate' => $store->duplicateRules($actor->id, $ids, $revisions, self::field($data, 'name_format')),
                        'enable', 'disable' => $store->setEnabled($actor->id, $ids, $revisions, $operation === 'enable'),
                        default => $store->deleteRules($actor->id, $ids, $revisions),
                    };
                    return new RedirectResponse($urls->generate('admin_tree_rules'), 303, self::HEADERS);
                } catch (AutomationTemplateAccessDenied $error) {
                    return self::message('Access denied.', $error->unauthenticated ? 401 : 403, $translator);
                } catch (\InvalidArgumentException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') ? 409 : (str_contains($error->getMessage(), 'no longer exist') ? 404 : 422);
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], self::DOMAIN)));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError(match ($operation) {
                        'duplicate' => $translator->trans('Duplicate outcome is uncertain. Check the Tree Rule list before retrying.', [], 'tree_rule'),
                        'enable' => $translator->trans('Enable outcome is uncertain. Check the Tree Rule list before retrying.', [], 'tree_rule'),
                        'disable' => $translator->trans('Disable outcome is uncertain. Check the Tree Rule list before retrying.', [], 'tree_rule'),
                        default => $translator->trans('Delete outcome is uncertain. Check the Tree Rule list before retrying.', [], 'tree_rule'),
                    }));
                }
            }
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('automation/tree_rule_action.html.twig', [
            'operation' => $operation,
            'rules' => array_values($selected),
            'form' => $form->createView(),
        ]), $status, self::HEADERS);
    }

    private static function message(string $message, int $status, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans($message, [], self::DOMAIN), $status, self::HEADERS);
    }

    /** @param array<string, mixed> $data */
    private static function field(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : '';
    }

    /**
     * The list submits ids[] in the query string; anything but a short list of decimal IDs is refused.
     *
     * @return list<int> ascending and distinct
     */
    private static function ids(mixed $raw): array
    {
        if ($raw === null || $raw === []) {
            throw new \InvalidArgumentException('Select at least one Tree Rule.');
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            throw new \InvalidArgumentException(self::INVALID);
        }
        if (count($raw) > TreeRuleStore::MAX_SELECTION) {
            throw new \InvalidArgumentException('Select at most 100 Tree Rules at a time.');
        }
        $ids = [];
        foreach ($raw as $id) {
            // automation_tree_rules.id is an unsigned mediumint column.
            if (!is_string($id) || preg_match('/\A[1-9][0-9]{0,7}\z/D', $id) !== 1 || (int) $id > 16777215) {
                throw new \InvalidArgumentException(self::INVALID);
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * The posted selection must be the one in the URL, with one revision per selected rule.
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
