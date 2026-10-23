/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Without a Referer the Return link steps back in history, as the native page did.
for (const link of document.querySelectorAll('a.returnTo')) {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        window.history.back();
    });
}
