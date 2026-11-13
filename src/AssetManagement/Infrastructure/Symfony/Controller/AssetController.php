<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony\Controller;

use Kadupul\AssetManagement\Application\Port\AssetWriter;
use Kadupul\AssetManagement\Application\ViewAssets;
use Kadupul\AssetManagement\Domain\AssetError;
use Kadupul\AssetManagement\Domain\AssetFields;
use Kadupul\AssetManagement\Infrastructure\Symfony\AssetFormInput;
use Kadupul\AssetManagement\Infrastructure\Symfony\WebAssetAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Serves the asset register page and its form submissions.
 * Responses are not cacheable.
 */
final readonly class AssetController
{
    /**
     * Binds the controller to access checks, the view, the command writer, CSRF, templating, URLs and translation.
     */
    public function __construct(private WebAssetAccess $access, private ViewAssets $view, private AssetWriter $writer, private CsrfTokenManagerInterface $csrf, private Environment $twig, private UrlGeneratorInterface $urls, private TranslatorInterface $translator) {}

    /**
     * Renders the register, or applies a posted command and redirects to the resulting page.
     */
    #[Route('/assets', name: 'asset_register', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $error = null;
        $status = 200;
        $input = [];
        try {
            $this->access->requireActor();
            if ($request->isMethod('POST')) {
                if (strlen($request->getContent()) > 524288) {
                    throw new AssetError('invalid_command');
                }
                $input = $request->request->all();
                $token = $input['_token'] ?? null;
                if (!is_string($token) || !$this->csrf->isTokenValid(new CsrfToken('asset_register', $token))) {
                    throw new AssetError('csrf');
                }
                $command = AssetFormInput::command($input);
                $result = $this->writer->apply($command);
                return new RedirectResponse($this->urls->generate('asset_register', ['asset' => $result['asset_id'] ?? $command['asset_id'] ?? '', 'receipt' => $result['request_id']]), 303, $headers);
            }
        } catch (AssetError $failure) {
            $error = $failure->reason;
            $status = match ($error) {
                'disabled' => 404, 'unauthenticated' => 401, 'denied', 'csrf' => 403,
                'stale_revision', 'request_reused' => 409, 'uncertain', 'unavailable' => 503, default => 422,
            };
            if (in_array($status, [401, 403, 404], true)) {
                return new Response($this->translator->trans($error, [], 'assets'), $status, $headers);
            }
            if ($error === 'uncertain') {
                // A worker may have committed before its acknowledgement was lost.
                // Do not render a replacement command with a new idempotency key.
                $receipt = isset($command) && is_string($command['request_id'] ?? null) ? $command['request_id'] : '';
                return new Response($this->translator->trans('uncertain', [], 'assets') . "\n" . $receipt, 503, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
        } catch (\Throwable) {
            return new Response($this->translator->trans('unavailable', [], 'assets'), 503, $headers);
        }
        try {
            $query = $request->query->all();
            $page = $query['page'] ?? '1';
            if (!is_string($page) || preg_match('/\A[1-9][0-9]?\z/D', $page) !== 1) {
                throw new AssetError('invalid_fields');
            }
            $assetId = $query['asset'] ?? '';
            if ($assetId !== '') {
                $assetId = AssetFields::id($assetId);
            }
            $before = $query['before'] ?? (string) PHP_INT_MAX;
            if (!is_string($before) || preg_match('/\A[1-9][0-9]{0,18}\z/D', $before) !== 1 || strlen($before) === 19 && strcmp($before, (string) PHP_INT_MAX) > 0) {
                throw new AssetError('invalid_fields');
            }
            $data = ($this->view)((int) $before);
            $selected = $assetId === '' ? null : ($data['assets'][$assetId] ?? throw new AssetError('not_found'));
            $fields = $selected['fields'] ?? AssetFields::DEFAULTS;
            if ($error !== null && isset($input['fields']) && is_array($input['fields']) && in_array($input['action'] ?? '', ['edit', 'create'], true)) {
                foreach (array_keys($fields) as $key) {
                    if (is_string($input['fields'][$key] ?? null)) {
                        $fields[$key] = $input['fields'][$key];
                    }
                }
            }
            $receipt = $query['receipt'] ?? null;
            $saved = is_string($receipt) && array_any($data['history'], static fn(array $event): bool => $event['request_id'] === $receipt);
            return new Response($this->twig->render('assets/register.html.twig', $data + [
                'selected' => $selected, 'fields' => $fields, 'error' => $error, 'saved' => $saved,
                'request_id' => bin2hex(random_bytes(16)), 'states' => AssetFields::STATES,
                'asset_page' => array_slice($data['assets'], ((int) $page - 1) * 50, 50, true),
                'identity_page' => array_slice($data['identities'], ((int) $page - 1) * 50, 50, true), 'page' => (int) $page,
            ]), $status, $headers);
        } catch (AssetError $failure) {
            return new Response($this->translator->trans($failure->reason, [], 'assets'), $failure->reason === 'not_found' ? 404 : 400, $headers);
        } catch (\Throwable) {
            return new Response($this->translator->trans('unavailable', [], 'assets'), 503, $headers);
        }
    }
}
