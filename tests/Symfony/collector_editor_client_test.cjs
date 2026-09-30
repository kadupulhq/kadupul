/* SPDX-License-Identifier: GPL-3.0-or-later */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

async function verify(collectorId, sslChecked) {
    const fields = new Map([
        ['collector_edit[dbhost]', '127.0.0.1'], ['collector_edit[dbuser]', 'fixture'],
        ['collector_edit[dbpass]', ''], ['collector_edit[dbdefault]', 'remote'],
        ['collector_edit[dbport]', '1'], ['collector_edit[dbretries]', '0'],
        ['collector_edit[dbssl]', '1'], ['collector_edit[dbsslkey]', ''],
        ['collector_edit[dbsslcert]', ''], ['collector_edit[dbsslca]', ''],
        ['collector_edit[revision]', 'opaque-revision'], ['collector_edit[notes]', 'private notes'],
        ['collector_edit[name]', 'Fixture'], ['collector_edit[_token]', 'edit-token'],
    ]);
    let click;
    let posted;
    const button = {disabled: false, addEventListener: (event, callback) => { click = callback; }};
    const result = {textContent: ''};
    const form = {
        dataset: {collectorId, connectionUrl: '/app.php/collectors/connection-test'},
        querySelector: selector => ({
            '[data-collector-connection-test]': button,
            '[data-collector-connection-result]': result,
            '[name="connection_token"]': {value: 'probe-token'},
            '[name="collector_edit[dbssl]"]': {checked: sslChecked},
        })[selector] || null,
    };
    class FormData extends Map { constructor(source) { super(source ? fields : []); } }
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../public/collector-editor.js'), 'utf8'), {
        document: {querySelector: () => form, getElementById: () => null},
        FormData,
        fetch: async (url, options) => {
            assert.equal(url, form.dataset.connectionUrl);
            assert.equal(options.method, 'POST');
            posted = options.body;
            return {json: async () => ({message: 'Connection Failed'})};
        },
    });
    await click();
    assert.equal(posted.get('connection_token'), 'probe-token');
    assert.equal(posted.get('collector_edit[revision]'), 'opaque-revision');
    assert.equal(posted.get('collector_edit[dbpass]'), '');
    assert.equal(posted.get('collector_edit[dbssl]'), sslChecked ? '1' : '');
    assert.equal(posted.has('collector_id'), collectorId !== '');
    for (const field of ['name', 'notes', '_token']) {
        assert.equal(posted.has(`collector_edit[${field}]`), false);
    }
    assert.equal(button.disabled, false);
    assert.equal(result.textContent, 'Connection Failed');
}

(async () => {
    await verify('2', false);
    await verify('', true);
    console.log('Collector editor client handoff checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
