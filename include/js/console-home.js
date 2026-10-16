/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

(() => {
    'use strict';

    const resize = () => {
        const main = document.getElementById('main');
        const content = document.getElementById('navigation_right');
        if (!main || !content) {
            return;
        }
        for (const frame of main.querySelectorAll('iframe.content')) {
            frame.style.boxSizing = 'border-box';
            frame.style.height = `${Math.round(content.getBoundingClientRect().height)}px`;
            frame.style.width = `${Math.round(main.getBoundingClientRect().width)}px`;
            frame.style.marginTop = '-5px';
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', resize, {once: true});
    } else {
        resize();
    }
    window.addEventListener('resize', () => window.requestAnimationFrame(resize));
})();
