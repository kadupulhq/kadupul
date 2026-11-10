<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Symfony\Controller;

use Kadupul\Alerting\Application\Port\RuleAccess;
use Kadupul\Alerting\Application\Port\RuleCatalog;
use Kadupul\Alerting\Application\Port\RuleStore;
use Kadupul\Alerting\Application\Query\AlertingAccessDenied;
use Kadupul\Alerting\Application\Query\ViewAlertingStatus;
use Kadupul\Alerting\Application\ReadModel\RuleView;
use Kadupul\Alerting\Application\Rule\RuleSubmission;
use Kadupul\Alerting\Application\Rule\RuleSubmissionRefused;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;
use Kadupul\Alerting\Infrastructure\Symfony\Form\RuleDeletionType;
use Kadupul\Alerting\Infrastructure\Symfony\Form\RuleType;
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
 * Alert rules (ADR 0011 decision 12). Every route takes the console actor,
 * then RuleAccess::authorize(), before it reads the request or any rule.
 * Rules on data sources the actor cannot view are not found.
 */
final class RuleController
{
    private const array HEADERS = ['Cache-Control' => 'private, no-store'];
    private const string PAGE = '/\A[1-9][0-9]{0,4}\z/D';
    /** Rule and incident ids are int(10) unsigned. */
    public const int MAX_ID = 4294967295;

    #[Route('/alerting/rules', name: 'alerting_rule_list', methods: ['GET', 'HEAD'])]
    public function list(Request $request, ConsoleAccess $console, RuleAccess $access, RuleCatalog $rules, ViewAlertingStatus $status, Environment $twig, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $page = self::page($query, ['saved', 'deleted']);
            $found = $rules->page($actor->id, $page);
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid alert rule list request.', [], 'alerting'), 400, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load alert rules. Reload before retrying.', [], 'alerting'), 502, self::HEADERS);
        }

        return new Response($request->isMethod('HEAD') ? '' : $twig->render('alerting/rules.html.twig', [
            'page' => $found,
            'status' => $status(),
            'deleted' => ($query['deleted'] ?? null) === '1',
        ]), 200, self::HEADERS);
    }

    #[Route('/alerting/rules/new', name: 'alerting_rule_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, RuleAccess $access, RuleStore $store, ViewAlertingStatus $status, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to authorize alert rule access.', [], 'alerting'), 502, self::HEADERS);
        }

        return $this->edit($actor->id, null, $request, $store, $status, $forms, $twig, $urls, $translator);
    }

    #[Route('/alerting/rules/{id}/edit', name: 'alerting_rule_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, RuleAccess $access, RuleCatalog $rules, RuleStore $store, ViewAlertingStatus $status, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $found = $this->visibleRule($id, $console, $access, $rules, $translator);
        if ($found instanceof Response) {
            return $found;
        }
        [$actorId, $rule] = $found;

        return $this->edit($actorId, $rule, $request, $store, $status, $forms, $twig, $urls, $translator);
    }

    #[Route('/alerting/rules/{id}/delete', name: 'alerting_rule_delete', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $id, Request $request, ConsoleAccess $console, RuleAccess $access, RuleCatalog $rules, RuleStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $found = $this->visibleRule($id, $console, $access, $rules, $translator);
        if ($found instanceof Response) {
            return $found;
        }
        [$actorId, $rule] = $found;
        $form = $forms->create(RuleDeletionType::class, ['revision' => $rule->revision], ['action' => $urls->generate('alerting_rule_delete', ['id' => $id])]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->addError(new FormError($translator->trans('The form was not submitted. Reload before retrying.', [], 'alerting')));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $store->delete($actorId, $id, self::revision($form));

                return new RedirectResponse($urls->generate('alerting_rule_list', ['deleted' => 1]), 303, self::HEADERS);
            } catch (AlertingAccessDenied $error) {
                return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
            } catch (RuleSubmissionRefused $error) {
                $status = match ($error->getMessage()) {
                    RuleStore::STALE => 409,
                    RuleStore::NOT_FOUND => 404,
                    default => 422,
                };
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'alerting')));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Check the alert rule list before retrying.', [], 'alerting')));
            }
        }

        return new Response($request->isMethod('HEAD') ? '' : $twig->render('alerting/rule_delete.html.twig', ['rule' => $rule, 'form' => $form->createView()]), $status, self::HEADERS);
    }

    /**
     * The edit and delete pages share their preamble: a console actor, the
     * Alert Rules realm, then a rule that actor may see. A Response is the
     * refusal to send as it is.
     *
     * @return array{int, RuleView}|Response
     */
    private function visibleRule(int $id, ConsoleAccess $console, RuleAccess $access, RuleCatalog $rules, TranslatorInterface $translator): array|Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $rule = $id <= self::MAX_ID ? $rules->find($actor->id, $id) : null;
        } catch (AlertingAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load the alert rule. Reload before retrying.', [], 'alerting'), 502, self::HEADERS);
        }
        if ($rule === null) {
            return new Response($translator->trans('Alert rule not found.', [], 'alerting'), 404, self::HEADERS);
        }

        return [$actor->id, $rule];
    }

    private function edit(int $actorId, ?RuleView $rule, Request $request, RuleStore $store, ViewAlertingStatus $status, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $form = $forms->create(RuleType::class, self::formData($rule), [
            'action' => $rule === null ? $urls->generate('alerting_rule_create') : $urls->generate('alerting_rule_edit', ['id' => $rule->id]),
            'csrf_token_id' => $rule === null ? 'alerting_rule_create' : 'alerting_rule_edit',
        ]);
        $form->handleRequest($request);
        $code = $request->isMethod('POST') ? 422 : 200;
        if ($request->isMethod('POST') && !$form->isSubmitted()) {
            $form->addError(new FormError($translator->trans('The form was not submitted. Reload before retrying.', [], 'alerting')));
        }
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $definition = RuleSubmission::definition($form->getData());
                if ($rule === null) {
                    $saved = $store->create($actorId, $definition);
                } else {
                    $store->update($actorId, $rule->id, self::revision($form), $definition);
                    $saved = $rule->id;
                }

                return new RedirectResponse($urls->generate('alerting_rule_edit', ['id' => $saved, 'saved' => 1]), 303, self::HEADERS);
            } catch (AlertingAccessDenied $error) {
                return new Response($translator->trans('Access denied.', [], 'alerting'), $error->unauthenticated ? 401 : 403, self::HEADERS);
            } catch (RuleSubmissionRefused | InvalidRule $error) {
                $code = match ($error->getMessage()) {
                    RuleStore::STALE => 409,
                    RuleStore::NOT_FOUND => 404,
                    default => 422,
                };
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'alerting')));
            } catch (\Throwable) {
                $code = 502;
                $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'alerting')));
            }
        }

        return new Response($request->isMethod('HEAD') ? '' : $twig->render('alerting/rule_edit.html.twig', [
            'rule' => $rule,
            'form' => $form->createView(),
            'status' => $status(),
            'saved' => ($request->query->all()['saved'] ?? null) === '1',
        ]), $code, self::HEADERS);
    }

    /**
     * The stored values, or for a new rule the domain's defaults: a baseline
     * rule's minimum sample count and direction have no column default.
     *
     * @return array<string, string>
     */
    private static function formData(?RuleView $rule): array
    {
        $number = static fn(float $value): string => sprintf('%.15G', $value);
        $data = [
            'kind' => 'threshold', 'local_data_id' => '', 'rrd_name' => '', 'for_cycles' => '1',
            'comparator' => 'gt', 'trigger_threshold' => '', 'clear_threshold' => '',
            'baseline_direction' => BaselineRule::DEFAULT_DIRECTION->value, 'baseline_deviations' => '', 'baseline_clear_deviations' => '',
            'baseline_floor' => '', 'baseline_min_samples' => (string) BaselineRule::DEFAULT_MIN_SAMPLES, 'revision' => '',
        ];
        if ($rule === null) {
            return $data;
        }
        $data = ['local_data_id' => (string) $rule->localDataId, 'rrd_name' => $rule->rrdName, 'revision' => $rule->revision] + $data;
        $condition = $rule->condition;
        if ($condition instanceof ThresholdRule) {
            return ['kind' => 'threshold', 'for_cycles' => (string) $condition->forCycles, 'comparator' => $condition->comparator->value,
                'trigger_threshold' => $number($condition->trigger), 'clear_threshold' => $number($condition->clear)] + $data;
        }
        if ($condition instanceof BaselineRule) {
            return ['kind' => 'baseline', 'for_cycles' => (string) $condition->forCycles, 'baseline_direction' => $condition->direction->value,
                'baseline_deviations' => $number($condition->deviations), 'baseline_clear_deviations' => $number($condition->clearDeviations),
                'baseline_floor' => $number($condition->deviationFloor), 'baseline_min_samples' => (string) $condition->minSamples] + $data;
        }

        return $data;
    }

    /**
     * The page number, after refusing any parameter but page and the given
     * flags, arrays, and flags other than 1.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $flags
     */
    public static function page(array $query, array $flags): int
    {
        $page = $query['page'] ?? '1';
        foreach ($query as $key => $value) {
            if (!is_string($value) || ($key !== 'page' && (!in_array($key, $flags, true) || $value !== '1'))) {
                throw new \InvalidArgumentException('Invalid list request.');
            }
        }
        if (!is_string($page) || preg_match(self::PAGE, $page) !== 1) {
            throw new \InvalidArgumentException('Invalid list request.');
        }

        return (int) $page;
    }

    /**
     * @template T
     * @param FormInterface<T> $form
     */
    private static function revision(FormInterface $form): string
    {
        $revision = $form->get('revision')->getData();

        return is_string($revision) ? $revision : '';
    }
}
