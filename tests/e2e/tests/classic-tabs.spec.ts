/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

import { test, expect, type Page } from '@playwright/test';

/*
 * The classic theme's top tabs used to be GIF and GD-drawn images of their
 * labels. They are now text links styled by include/themes/classic/main.css.
 * The admin's theme is switched through the profile page's own AJAX call and
 * put back afterwards so the other specs keep their theme.
 */

async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto('/');
    await page.locator('input[name="login_username"]').fill('admin');
    await page.locator('input[name="login_password"]').fill('admin');
    await page.locator('form#login input[type="submit"]').click();
    await expect(page.locator('#tabs')).toBeVisible();
}

async function setTheme(page: Page, theme: string): Promise<void> {
    await page.goto('/auth_profile.php?action=edit');
    await page.evaluate(async (value) => {
        const w = window as unknown as {
            jQuery: { post: (url: string, data: object) => Promise<unknown> };
            csrfMagicToken: string;
        };
        await w.jQuery.post('auth_profile.php?tab=general&action=update_data', {
            __csrf_magic: w.csrfMagicToken,
            name: 'selected_theme',
            value,
        });
    }, theme);
}

async function focusFirstTab(page: Page): Promise<string | null> {
    await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
    for (let i = 0; i < 40; i++) {
        await page.keyboard.press('Tab');
        const id = await page.evaluate(() => {
            const active = document.activeElement;
            return active && active.closest('#tabs') ? active.id : null;
        });
        if (id) {
            return id;
        }
    }
    return null;
}

test.describe('classic theme top tabs', () => {
    let originalTheme = 'modern';

    test.beforeEach(async ({ page }) => {
        await loginAsAdmin(page);
        originalTheme = await page.evaluate(() => (window as unknown as { theme: string }).theme);
        await setTheme(page, 'classic');
    });

    test.afterEach(async ({ page }) => {
        await setTheme(page, originalTheme);
    });

    test('render as focusable text links on the console and graph pages', async ({ page }) => {
        for (const [url, selectedId] of [['/index.php', 'tab-console'], ['/graph_view.php', 'tab-graphs']]) {
            await page.goto(url);
            expect(await page.evaluate(() => (window as unknown as { theme: string }).theme)).toBe('classic');

            await expect(page.locator('#tabs img')).toHaveCount(0);
            await expect(page.locator('#tab-console')).toHaveText('Console');
            await expect(page.locator('#tab-graphs')).toHaveText('Graphs');
            await expect(page.locator(`#${selectedId}`)).toHaveClass(/\bselected\b/);

            const tab = await page.locator('#tab-console').evaluate((a) => {
                const box = a.getBoundingClientRect();
                const style = getComputedStyle(a);
                return { width: box.width, height: box.height, color: style.color, transform: style.textTransform };
            });
            expect(tab.width).toBeGreaterThanOrEqual(86);
            expect(tab.height).toBe(30);
            expect(tab.color).toBe('rgb(255, 255, 255)');
            expect(tab.transform).toBe('lowercase');

            expect(await focusFirstTab(page)).toBe('tab-console');
            const outline = await page.locator('#tab-console').evaluate((a) => {
                const style = getComputedStyle(a);
                return `${style.outlineStyle} ${style.outlineWidth}`;
            });
            expect(outline).toBe('solid 2px');
        }
    });
});
