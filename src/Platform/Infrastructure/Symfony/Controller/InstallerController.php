<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\Platform\Application\Installer\InstallerWorkflow;
use Kadupul\Platform\Infrastructure\Symfony\InstallerWebContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class InstallerController
{
    public function __construct(
        private readonly InstallerWorkflow $workflow,
        private readonly InstallerWebContext $context,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
    ) {}

    #[Route('/installer', name: 'platform_installer', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'"];
        // The native entry authorizes before Symfony boots. Direct app.php
        // requests have no native capability and cannot reach protected reads.
        $view = $this->context->view();
        $request->setLocale($view['locale']);
        if ($request->query->all() === ['view' => 'diagnostics'] && !$request->isMethod('POST')) {
            try {
                return new JsonResponse($this->workflow->diagnostics(), 200, $headers + [
                    'Content-Disposition' => 'attachment; filename="installer-diagnostics.json"',
                ]);
            } catch (HttpExceptionInterface $exception) {
                throw $exception;
            } catch (\RuntimeException|\JsonException) {
                return new JsonResponse(['error' => 'diagnostics_unavailable'], 503, $headers);
            }
        }
        if ($request->query->all() === ['view' => 'status'] && !$request->isMethod('POST')) {
            try {
                $state = $this->workflow->status();
            } catch (HttpExceptionInterface $exception) {
                throw $exception;
            } catch (\RuntimeException|\JsonException) {
                return new JsonResponse(['error' => $this->translator->trans('Status is unavailable. Saved work is retained. Refresh to reconnect; do not submit another installation.', [], 'installer')], 503, $headers);
            }
            foreach (['phase', 'error'] as $field) {
                if (is_string($state[$field] ?? null)) {
                    $state[$field] = $this->translator->trans($state[$field], [], 'installer');
                }
            }
            return new JsonResponse($state, 200, $headers);
        }
        $error = null;
        $status = 200;
        if ($request->isMethod('POST')) {
            if (!$this->context->validPost()) {
                return new JsonResponse(['error' => 'csrf_expired', 'token' => $view['csrf']], 403, $headers);
            }
            try {
                $post = $request->request->all();
                $action = $post['action'] ?? null;
                if ($action === 'recover' && is_string($post['attempt'] ?? null) && preg_match('/\\A[0-9]{1,15}\\z/D', $post['attempt']) === 1) {
                    $this->workflow->recover((int) $post['attempt'], ($post['recovery_confirmed'] ?? null) === '1');
                } elseif ($action === 'configure' && ($post['form_complete'] ?? null) === '1' && is_array($post['options'] ?? null)) {
                    $this->workflow->configure($post['options'], true);
                } elseif (($action === 'start' || $action === 'retry') && is_string($post['revision'] ?? null)) {
                    if ($action === 'retry' && ($post['retry_confirmed'] ?? null) !== '1') {
                        throw new \InvalidArgumentException('Confirm the recovery warning before retrying.');
                    }
                    $this->workflow->start(
                        $post['revision'],
                        ($post['confirmed'] ?? null) === '1',
                        ($post['discovery_confirmed'] ?? null) === '1',
                        $action === 'retry'
                    );
                } else {
                    throw new \InvalidArgumentException('Invalid installation form.');
                }
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(['status' => 'saved'], 200, $headers);
                }
                return new RedirectResponse($view['action'], 303, $headers);
            } catch (HttpExceptionInterface $exception) {
                return new JsonResponse(['error' => $this->translator->trans('Access denied.', [], 'installer')], $exception->getStatusCode(), $headers);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
                $status = 422;
            } catch (\RuntimeException) {
                $error = 'The installation could not proceed. Reload its status and review the blocking checks before retrying.';
                $status = 409;
            }
            if ($request->isXmlHttpRequest()) {
                return new JsonResponse(['error' => $this->translator->trans($error, [], 'installer')], $status, $headers);
            }
        }
        try {
            $plan = $this->workflow->inspect();
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\RuntimeException|\JsonException) {
            return new Response(
                $this->translator->trans('Status is unavailable. Saved work is retained. Refresh to reconnect; do not submit another installation.', [], 'installer'),
                503,
                $headers + ['Content-Type' => 'text/plain; charset=UTF-8']
            );
        }
        return new Response($request->isMethod('HEAD') ? '' : $this->twig->render(
            'platform/installer.html.twig',
            ['plan' => $plan, 'view' => $view, 'error' => $error]
        ), $status, $headers);
    }
}
