<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony;

use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/** Shared navigation and private responses for single-device forms. */
final readonly class DeviceFormPage
{
    public function __construct(private Environment $twig, private TranslatorInterface $translator) {}

    public function parameters(Request $request, int $id): array|Response
    {
        try {
            return ['id' => $id, 'list' => DeviceListParameters::context($request->query->all())];
        } catch (\InvalidArgumentException) {
            return new Response($this->translator->trans('Invalid device list filters.', [], 'inventory'), 400, ['Cache-Control' => 'private, no-store']);
        }
    }

    public function render(string $template, object $device, FormInterface $form, Request $request, array $filters, int|Response $status): Response
    {
        if ($status instanceof Response) {
            return $status;
        }
        return new Response($this->twig->render($template, ['device' => $device, 'form' => $form->createView(), 'saved' => ($request->query->all()['saved'] ?? null) === '1', 'filters' => $filters]), $status, ['Cache-Control' => 'private, no-store']);
    }
}
