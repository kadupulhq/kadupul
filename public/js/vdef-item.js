/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

const vdefItemType = document.getElementById('vdef_item_type');
if (vdefItemType) {
    vdefItemType.addEventListener('change', event => {
        const target = new URL(window.location.href);
        target.searchParams.set('type', event.currentTarget.value);
        window.location.assign(target);
    });
}
