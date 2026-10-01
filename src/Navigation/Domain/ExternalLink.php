<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Navigation\Domain;

final readonly class ExternalLink
{
    // Longer than both the 50-character legacy and 20-character new-name limits.
    public const string NEW_SECTION_SELECTION = '__CREATE_NEW_CONSOLE_SECTION_NOT_A_PERSISTED_SECTION_NAME__';
    public const array STYLES = ['TAB', 'CONSOLE', 'FRONT', 'FRONTTOP'];
    public const array REFRESHES = [0, 10, 15, 20, 30, 60, 300];
    public function __construct(
        public int $id,
        public int $sortorder,
        public string $title,
        public string $contentfile,
        public string $style,
        public string $extendedstyle,
        public bool $enabled,
        public int $refresh
    ) {}
    public static function validate(array $fields, array $files): array
    {
        foreach (['title', 'style', 'filename', 'fileurl', 'consolesection', 'consolenewsection'] as $key) {
            if (!isset($fields[$key]) || !is_string($fields[$key]) || preg_match('//u', $fields[$key]) !== 1 || str_contains($fields[$key], "\0")) {
                throw new \InvalidArgumentException('Invalid link fields.');
            }
        }
        $title = $fields['title'];
        if (trim($title) === '' || mb_strlen($title, 'UTF-8') > 20 || !in_array($fields['style'], self::STYLES, true)
            || !is_int($fields['refresh'] ?? null) || !in_array($fields['refresh'], self::REFRESHES, true) || !is_bool($fields['enabled'] ?? null)) {
            throw new \InvalidArgumentException('Invalid link fields.');
        }
        $content = $fields['filename'];
        if ($content === '0') {
            $content = $fields['fileurl'];
            if (strlen($content) > 255 || filter_var($content, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) parse_url($content, PHP_URL_SCHEME)), ['http', 'https', 'ftp', 'ftps'], true)
                || preg_match('/[\x00-\x20\x7f]/', $content)) {
                throw new \InvalidArgumentException('Invalid content URL.');
            }
        } elseif (!in_array($content, $files, true) || $content !== basename($content) || preg_match('/^[A-Za-z0-9_.-]+$/D', $content) !== 1) {
            throw new \InvalidArgumentException('Select an installed content file.');
        }
        $section = '';
        if ($fields['style'] === 'CONSOLE') {
            $section = $fields['consolesection'] === self::NEW_SECTION_SELECTION ? $fields['consolenewsection'] : $fields['consolesection'];
            $section = $section === '' ? 'External Links' : $section;
            if (mb_strlen($section, 'UTF-8') > ($fields['consolesection'] === self::NEW_SECTION_SELECTION ? 20 : 50)) {
                throw new \InvalidArgumentException('Invalid console section.');
            }
        }
        return ['title' => $title, 'contentfile' => $content, 'style' => $fields['style'], 'extendedstyle' => $section,
            'enabled' => $fields['enabled'] ? 'on' : '', 'refresh' => $fields['refresh']];
    }
    public function fields(array $files): array
    {
        return ['title' => $this->title, 'style' => $this->style, 'filename' => in_array($this->contentfile, $files, true) ? $this->contentfile : '0',
            'fileurl' => $this->contentfile, 'consolesection' => $this->extendedstyle ?: 'External Links', 'consolenewsection' => '',
            'enabled' => $this->enabled, 'refresh' => $this->refresh];
    }
}
