/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

document.getElementById('aggregate_template_graph_template_id')?.addEventListener('change', function () {
    const target = new URL(this.dataset.editorUrl, window.location.href);
    target.searchParams.set('source', this.value);
    window.location.assign(target);
});
