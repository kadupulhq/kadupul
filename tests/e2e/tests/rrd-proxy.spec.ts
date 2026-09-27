import { test, expect, type Page } from '@playwright/test';
import { readFile } from 'node:fs/promises';

const templateName = 'RRDtool Proxy E2E Device Template';

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/');
    await page.locator('input[name="login_username"]').fill('admin');
    await page.locator('input[name="login_password"]').fill('admin');
    await Promise.all([
        page.waitForLoadState('networkidle'),
        page.locator('form#login input[type="submit"]').click(),
    ]);
}

test('settings, RRD traffic, and template import/export work through the real proxy stack', async ({ page }) => {
    const fingerprint = process.env.RRD_PROXY_E2E_FINGERPRINT;
    expect(fingerprint, 'run tests/e2e/rrd-proxy/run.sh to seed the proxy key').toBeTruthy();

    await loginAsAdmin(page);

    // Change the actual Kadupul storage settings page, as an administrator would.
    await page.goto('/settings.php?tab=data');
    await page.locator('#storage_location-button').click();
    await page.getByRole('option', { name: 'RRDtool Proxy Server' }).click();
    await page.locator('#rrdp_server').fill('rrdproxy');
    await page.locator('#rrdp_port').fill('40301');
    await page.locator('#rrdp_fingerprint').fill(fingerprint!);
    await page.locator('#submit').click();
    await expect(page.locator('#storage_location')).toHaveValue('1');

    // Exercise create/update/info/last through Kadupul's configured transport.
    const probe = await page.evaluate(async () => {
        const response = await fetch('/rrd-proxy-e2e-probe.php');
        return { status: response.status, contentType: response.headers.get('content-type'), body: await response.text() };
    });
    const probeBody = probe.body;
    expect(probe.status, probeBody).toBe(200);
    expect(probeBody, 'probe returned PHP output instead of JSON').not.toContain('<br');
    expect(probe.contentType, probeBody).toContain('application/json');
    const result = JSON.parse(probeBody);
    expect(result).toMatchObject({
        ok: true,
        transport: 'rrdproxy',
        commands: ['create', 'update', 'info', 'last'],
    });

    // Download a real template export, then upload that XML through Kadupul.
    await page.goto('/templates_export.php?export_type=host_template');
    await page.locator('#export_item_id-button').click();
    await page.getByRole('option', { name: templateName }).click();
    await page.getByText('Save File Locally', { exact: true }).click();
    const [download] = await Promise.all([
        page.waitForEvent('download'),
        page.locator('#submit').click(),
    ]);
    const exportedXml = await readFile((await download.path())!);
    expect(exportedXml.toString()).toContain(templateName);

    await page.goto('/templates_import.php');
    await page.locator('#import_file').setInputFiles({
        name: 'proxy-e2e-template.xml',
        mimeType: 'application/xml',
        buffer: exportedXml,
    });
    await expect(page.locator('#contents')).toContainText(templateName);
    await page.locator('#import').evaluate((form) => {
        let input = form.querySelector<HTMLInputElement>('input[name="preview_only"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'preview_only';
            form.append(input);
        }
        input.value = 'false';
    });
    await page.locator('#submit').click();
    await expect(page.getByText('The Template Import Succeeded.')).toBeVisible();
});
