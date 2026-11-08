<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Twig\Environment;
use Twig\Markup;
use Twig\TemplateWrapper;

/** Render in native hook order; the caller retains bootstrap, CSRF and writes. */
final readonly class LoginPageRenderer
{
    private TemplateWrapper $template;

    public function __construct(Environment $twig)
    {
        $this->template = $twig->load('identity_access/login.html.twig');
    }

    public function begin(Markup $locale): string
    {
        return $this->template->renderBlock('begin', ['locale' => $locale]);
    }

    public function formStart(string $heading, string $target): string
    {
        return $this->template->renderBlock('form_start', ['heading' => $heading, 'target' => $target]);
    }

    public function credentials(string $instructions, string $usernameLabel, string $passwordLabel, Markup $username): string
    {
        return $this->template->renderBlock('credentials', compact('instructions', 'usernameLabel', 'passwordLabel', 'username'));
    }

    /** @param list<array{value: string, name: Markup, selected: bool}> $choices */
    public function realms(string $label, array $choices): string
    {
        return $this->template->renderBlock('realms', ['label' => $label, 'choices' => $choices]);
    }

    public function remember(string $label, bool $checked): string
    {
        return $this->template->renderBlock('remember', ['label' => $label, 'checked' => $checked]);
    }

    public function submit(string $label): string
    {
        return $this->template->renderBlock('submit', ['label' => $label]);
    }

    public function result(Markup $error, string $version, string $nonce, bool $focusUsername): string
    {
        return $this->template->renderBlock('result', compact('error', 'version', 'nonce', 'focusUsername'));
    }

    public function end(): string
    {
        return $this->template->renderBlock('end');
    }
}
