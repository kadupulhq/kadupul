// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

import { test, expect, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/*
 * Measures WCAG contrast on real Kadupul pages in every shipped theme: text
 * (4.5:1, 3:1 when large), icon glyphs (3:1), keyboard focus indicators (3:1
 * against the colour around them) and the edges of form controls (3:1). Each
 * page is measured at rest, with rows selected, and while each kind of link,
 * row, button and control is hovered and keyboard-focused.
 *
 * Needs the compose stack with the seed from theme-contrast/seed.sh. Each
 * theme's report lands in theme-contrast-results/; compare two runs with
 * theme-contrast/compare.js.
 *
 *   THEME_CONTRAST_THEMES   comma list, default every shipped theme
 *   THEME_CONTRAST_ROOT     serve include/themes from this checkout instead,
 *                           to measure another revision's CSS on this stack
 *   THEME_CONTRAST_LABEL    report name, default "current"
 */

const repoRoot = path.resolve(__dirname, '../../..');
const themeRoot = path.join(repoRoot, 'include/themes');
const shipped = fs.readdirSync(themeRoot, { withFileTypes: true })
	.filter((entry) => entry.isDirectory())
	.map((entry) => entry.name)
	.sort();
const wanted = process.env.THEME_CONTRAST_THEMES ? process.env.THEME_CONTRAST_THEMES.split(',') : shipped;
const altRoot = process.env.THEME_CONTRAST_ROOT ? path.resolve(process.env.THEME_CONTRAST_ROOT) : null;
const label = process.env.THEME_CONTRAST_LABEL || 'current';
const measureScript = path.join(__dirname, '../theme-contrast/measure.js');
// Outside test-results, which Playwright empties at the start of every run.
const reportDir = path.join(__dirname, '../theme-contrast-results');

type Finding = {
	theme: string;
	page: string;
	state: string;
	kind: string;
	key: string;
	text: string;
	fg: string;
	bg: string;
	ratio: number;
	required: number;
	disabled?: boolean;
	image?: boolean;
	via?: string;
};

type Scenario = {
	name: string;
	url: string;
	// Returns false when the theme has no such control, or a scope that
	// replaces the listed one.
	setup?: (page: Page) => Promise<string | false | void>;
	// Limits every pass to this part of the page, for overlays.
	scope?: string;
	passes?: Array<'default' | 'hover' | 'focus' | 'selected'>;
	loggedOut?: boolean;
};

const all: Scenario['passes'] = ['default', 'hover', 'focus', 'selected'];

async function openDialog(page: Page): Promise<void> {
	await page.evaluate(() => {
		const $ = (window as any).jQuery;
		$('<div id="contrastDialog"><p>Delete the selected devices? <a href="#">Details</a></p>'
			+ '<input type="text" value="Device 1"> <input type="checkbox" id="contrastDialogBox"><label for="contrastDialogBox">Keep graphs</label></div>')
			.dialog({ title: 'Confirm', modal: true, width: 500, buttons: { Continue: () => {}, Cancel: () => {} } });
	});
}

// include/layout.js MESSAGE_LEVEL_INFO and MESSAGE_LEVEL_ERROR.
async function raise(page: Page, level: number): Promise<void> {
	await page.evaluate((lvl) => {
		(window as any).raiseMessage('Operation Result', 'Save Successful', 'The device <a href="#">Device 1</a> was saved.', lvl);
	}, level);
	await page.waitForSelector('#messageContainer', { state: 'visible' });
	// The info box counts down and closes itself.
	await page.evaluate(() => { const w = window as any; if (w.sessionMessageTimer) clearInterval(w.sessionMessageTimer); });
}

const scenarios: Scenario[] = [
	{ name: 'login', url: '/index.php', loggedOut: true, passes: ['default', 'hover', 'focus'] },
	{ name: 'logout', url: '/logout.php?action=timeout', loggedOut: true, passes: ['default', 'hover', 'focus'] },
	{ name: 'console', url: '/index.php' },
	{ name: 'devices', url: '/host.php' },
	{ name: 'device edit', url: '/host.php?action=edit&id=1' },
	{ name: 'graphs list', url: '/graphs.php' },
	{ name: 'data sources', url: '/data_sources.php' },
	{ name: 'users', url: '/user_admin.php' },
	{ name: 'settings general', url: '/settings.php?tab=general' },
	{ name: 'settings visual', url: '/settings.php?tab=visual' },
	{ name: 'profile', url: '/auth_profile.php?action=edit' },
	{
		name: 'graph tree',
		url: '/graph_view.php?action=tree',
		setup: async (page) => {
			// Open the tree and pick a device, so the page shows a selected
			// node and that device's graphs.
			await page.evaluate(() => (window as any).jQuery('.jstree').jstree('open_all'));
			const node = page.locator('.jstree-anchor', { hasText: 'Device 1' }).first();
			await node.click();
			await page.waitForLoadState('networkidle');
		},
	},
	{ name: 'graph list view', url: '/graph_view.php?action=list' },
	{ name: 'graph preview', url: '/graph_view.php?action=preview' },
	{
		name: 'spike kill menu',
		url: '/graph_view.php?action=preview',
		scope: '.spikekillMenu',
		passes: ['default', 'hover', 'focus'],
		setup: async (page) => {
			// dark and midwinter show the icon column only while the graph is
			// hovered.
			const cell = page.locator('td.graphDrillDown').first();
			await cell.scrollIntoViewIfNeeded();
			await cell.hover();
			await page.locator('span.spikekill').first().click();
			await page.waitForSelector('.spikekillMenu', { state: 'visible' });
		},
	},
	{
		name: 'user menu',
		url: '/host.php',
		scope: '.menuoptions',
		passes: ['default', 'hover', 'focus'],
		setup: async (page) => {
			// midwinter moves the user menu into its navigation bar.
			const compact = page.locator('.compact_nav_icon[data-helper="user"]:visible');
			if (await compact.count()) {
				await compact.first().click();
				return '.cactiConsoleNavigationUserBox[data-helper="user"]';
			}
			// Some themes hide the user name and open the menu from their own
			// header icon, which ends in the same include/layout.js call.
			if (await page.locator('span#user:visible').count()) {
				await page.locator('span#user').click();
			} else {
				await page.evaluate(() => (window as any).openUserMenu());
			}
			await page.waitForSelector('.menuoptions', { state: 'visible' });
		},
	},
	{
		name: 'selectmenu open',
		url: '/host.php',
		scope: '.ui-selectmenu-menu.ui-selectmenu-open',
		passes: ['default', 'hover'],
		setup: async (page) => {
			// classic keeps native selects.
			if (await page.locator('#host_status-button').count() === 0) {
				return false;
			}
			await page.locator('#host_status-button').click();
			await page.waitForSelector('.ui-selectmenu-open', { state: 'visible' });
		},
	},
	{
		name: 'dialog',
		url: '/host.php',
		scope: '.ui-dialog',
		passes: ['default', 'hover', 'focus'],
		setup: openDialog,
	},
	{
		name: 'message info',
		url: '/host.php',
		scope: '.ui-dialog',
		passes: ['default', 'hover', 'focus'],
		setup: (page) => raise(page, 1),
	},
	{
		name: 'message error',
		url: '/host.php',
		scope: '.ui-dialog',
		passes: ['default', 'hover', 'focus'],
		setup: (page) => raise(page, 3),
	},
];

// midwinter keeps its colour mode in local storage, so it is measured once
// per mode.
const variants = wanted.flatMap((theme) => (theme === 'midwinter'
	? [{ theme, color: 'dark' }, { theme, color: 'light' }]
	: [{ theme, color: '' }]));

async function prepare(page: Page, color: string): Promise<void> {
	await page.addInitScript({ path: measureScript });
	await page.addInitScript((mode) => {
		if (mode) {
			localStorage.setItem('midWinter_Color_Mode', JSON.stringify(mode));
			localStorage.setItem('midWinter_Color_Mode_Auto', JSON.stringify('off'));
			document.cookie = `CactiColorMode=${mode}; path=/`;
		}
		// Measure the settled colour, not a frame of a fade.
		document.addEventListener('DOMContentLoaded', () => {
			const jq = (window as any).jQuery;
			if (jq) {
				jq.fx.off = true;
			}
			const style = document.createElement('style');
			// Zero durations rather than none: midwinter reveals menus with
			// animations whose end state has to apply.
			style.textContent = '*, *::before, *::after { transition-duration: 0s !important; transition-delay: 0s !important;'
				+ ' animation-duration: 0s !important; animation-delay: 0s !important; caret-color: transparent !important; }';
			document.head.appendChild(style);
		});
	}, color);
	if (altRoot) {
		await page.route('**/include/themes/**', async (route) => {
			const url = new URL(route.request().url());
			const rel = decodeURIComponent(url.pathname.replace(/^.*?\/include\/themes\//, ''));
			const file = path.join(altRoot, 'include/themes', rel);
			if (fs.existsSync(file) && fs.statSync(file).isFile()) {
				await route.fulfill({ path: file });
			} else {
				await route.continue();
			}
		});
	}
}

async function login(page: Page): Promise<void> {
	await page.goto('/index.php');
	if (await page.locator('input[name="login_username"]').count() === 0) {
		return;
	}
	await page.fill('input[name="login_username"]', 'admin');
	await page.fill('input[name="login_password"]', 'admin');
	await Promise.all([
		page.waitForURL((url) => !/login/.test(url.search)),
		page.click('form#login input[type="submit"]'),
	]);
	await page.waitForLoadState('networkidle');
}

// Dropping the session cookie is a logout as far as the next page knows, and
// unlike loading logout.php it cannot race a reload the last page started.
async function logout(page: Page): Promise<void> {
	await page.goto('about:blank');
	await page.context().clearCookies();
}

// The login page has no user yet, so the system-wide theme is what decides
// it. Setting that one covers both.
async function selectTheme(page: Page, theme: string): Promise<void> {
	await login(page);
	await page.goto('/settings.php?tab=visual');
	await page.waitForLoadState('networkidle');
	await page.evaluate((name) => {
		const $ = (window as any).jQuery;
		$('#selected_theme').val(name).trigger('change');
	}, theme);
	await Promise.all([
		page.waitForResponse((response) => response.request().method() === 'POST' && /settings\.php/.test(response.url())),
		page.locator('input[type=submit]').first().click(),
	]);
	// The session keeps the theme it started with.
	await logout(page);
	await login(page);
	const active = await page.evaluate(() => (window as any).theme);
	expect(active, 'theme switch').toBe(theme);
}

async function settle(page: Page): Promise<void> {
	await page.waitForLoadState('networkidle');
	await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

// Measures text across the whole page by scrolling a viewport at a time,
// since only painted points can be sampled.
async function measureTextPass(page: Page, scope: string | null): Promise<Finding[]> {
	const found: Finding[] = [];
	const height = await page.evaluate(() => Math.min(document.documentElement.scrollHeight, 6000));
	const step = page.viewportSize()!.height - 100;
	for (let y = 0; y < height; y += step) {
		if (!scope) {
			await page.evaluate((top) => window.scrollTo(0, top), y);
		}
		found.push(...await page.evaluate((sel) => {
			const c = (window as any).__contrast;
			const roots = sel ? [...document.querySelectorAll(sel)] : [document.body];
			return roots.flatMap((root) => c.measureText(root));
		}, scope));
		if (scope) {
			break;
		}
	}
	await page.evaluate(() => window.scrollTo(0, 0));
	return found as Finding[];
}

async function hoverPass(page: Page, scope: string | null, within: string | null): Promise<Finding[]> {
	const found: Finding[] = [];
	const count = await page.evaluate(([sel, inside]) => {
		const c = (window as any).__contrast;
		const list = c.hoverables().filter((el: Element) => (!sel || el.closest(sel)) && (!inside || el.closest(inside)));
		(window as any).__hover = list.filter((_: Element, i: number) => c.pick(list, 2).includes(i));
		return (window as any).__hover.length;
	}, [scope, within]);
	for (let i = 0; i < Math.min(count, 120); i++) {
		const box = await page.evaluate((index) => {
			const el = (window as any).__hover[index] as HTMLElement;
			if (!el.isConnected) {
				return null;
			}
			el.scrollIntoView({ block: 'center', inline: 'nearest' });
			const r = el.getBoundingClientRect();
			return r.width > 0 ? { x: r.left + Math.min(r.width / 2, 20), y: r.top + r.height / 2 } : null;
		}, i);
		if (!box) {
			continue;
		}
		await page.mouse.move(box.x, box.y);
		await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
		found.push(...await page.evaluate((index) => {
			const el = (window as any).__hover[index] as HTMLElement;
			const root = el.closest('tr, li, .ui-menu-item') || el;
			return (window as any).__contrast.measureText(root);
		}, i));
	}
	await page.mouse.move(0, 0);
	return found as Finding[];
}

async function focusPass(page: Page, scope: string | null, within: string | null): Promise<Finding[]> {
	const found: Finding[] = [];
	const count = await page.evaluate(([sel, inside]) => {
		const c = (window as any).__contrast;
		const list = c.focusables().filter((el: Element) => (!sel || el.closest(sel)) && (!inside || el.closest(inside)));
		(window as any).__focus = list.filter((_: Element, i: number) => c.pick(list, 2).includes(i));
		return (window as any).__focus.length;
	}, [scope, within]);
	// A key press puts Chrome in keyboard modality, so script focus then
	// matches :focus-visible the way Tab does.
	await page.keyboard.press('Shift');
	for (let i = 0; i < Math.min(count, 150); i++) {
		const result = await page.evaluate((index) => {
			const c = (window as any).__contrast;
			const el = (window as any).__focus[index] as HTMLElement;
			if (!el.isConnected) {
				return null;
			}
			el.scrollIntoView({ block: 'center', inline: 'nearest' });
			const before = c.preFocus(el);
			el.focus({ focusVisible: true } as FocusOptions);
			if (document.activeElement !== el) {
				return null;
			}
			return { visible: el.matches(':focus-visible'), before: [...before.entries()] };
		}, i);
		if (!result) {
			continue;
		}
		await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
		found.push(...await page.evaluate(([index, before]) => {
			const c = (window as any).__contrast;
			const el = (window as any).__focus[index] as HTMLElement;
			const ring = c.focusIndicator(el, new Map(before as any));
			const text = /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName) ? [] : c.measureText(el);
			return [ring, ...text];
		}, [i, result.before] as const));
	}
	await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
	return found as Finding[];
}

async function boundaryPass(page: Page, scope: string | null, within: string | null): Promise<Finding[]> {
	return await page.evaluate(([sel, inside]) => {
		const c = (window as any).__contrast;
		const out = [];
		for (const el of c.boundaryTargets()) {
			if ((sel && !el.closest(sel)) || (inside && !el.closest(inside))) {
				continue;
			}
			el.scrollIntoView({ block: 'center', inline: 'nearest' });
			const m = c.measureBoundary(el);
			if (m) {
				out.push(m);
			}
		}
		return out;
	}, [scope, within]) as Finding[];
}

async function selectRows(page: Page): Promise<boolean> {
	return await page.evaluate(() => {
		const rows = [...document.querySelectorAll('tr.selectable')].slice(0, 3);
		for (const row of rows) {
			row.classList.add('selected');
			const box = row.querySelector('input[type=checkbox]') as HTMLInputElement | null;
			if (box) {
				box.checked = true;
			}
		}
		return rows.length > 0;
	});
}

function worstByKey(findings: Finding[]): Finding[] {
	const map = new Map<string, Finding>();
	for (const f of findings) {
		const id = [f.page, f.state, f.kind, f.key, f.text].join('|');
		const prev = map.get(id);
		if (!prev || f.ratio < prev.ratio) {
			map.set(id, f);
		}
	}
	return [...map.values()];
}

function isFailure(f: Finding): boolean {
	return !f.disabled && !f.image && f.ratio + 0.005 < f.required;
}

for (const { theme, color } of variants) {
	const name = color ? `${theme}-${color}` : theme;

	test(`${name} contrast on real pages`, async ({ browser }) => {
		test.setTimeout(20 * 60_000);
		const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8080' });
		const page = await context.newPage();
		page.setDefaultTimeout(30_000);
		await prepare(page, color);
		await selectTheme(page, theme);

		const findings: Finding[] = [];
		for (const scenario of scenarios) {
			if (scenario.loggedOut) {
				await logout(page);
			} else {
				await login(page);
			}
			await page.goto(scenario.url);
			await settle(page);
			let scope = scenario.scope || null;
			if (scenario.setup) {
				const outcome = await scenario.setup(page);
				if (outcome === false) {
					continue;
				}
				if (outcome) {
					scope = outcome;
				}
				await settle(page);
			}
			const passes = scenario.passes || all;
			const started = Date.now();
			const tag = (state: string, list: Finding[]) => {
				if (process.env.THEME_CONTRAST_DEBUG) {
					console.log(`${name} ${scenario.name} ${state}: ${list.length} in ${Date.now() - started} ms`);
				}
				return list.map((f) => ({ ...f, theme: name, page: scenario.name, state }));
			};

			if (passes.includes('default')) {
				findings.push(...tag('default', await measureTextPass(page, scope)));
				findings.push(...tag('default', await boundaryPass(page, scope, null)));
			}
			if (passes.includes('hover')) {
				findings.push(...tag('hover', await hoverPass(page, scope, null)));
			}
			if (passes.includes('focus')) {
				findings.push(...tag('focus', await focusPass(page, scope, null)));
			}
			if (passes.includes('selected') && await selectRows(page)) {
				await settle(page);
				findings.push(...tag('selected', await measureTextPass(page, 'tr.selectable.selected')));
				findings.push(...tag('selected hover', await hoverPass(page, null, 'tr.selectable.selected')));
				findings.push(...tag('selected focus', await focusPass(page, null, 'tr.selectable.selected')));
			}
		}
		await context.close();

		const measured = worstByKey(findings);
		fs.mkdirSync(reportDir, { recursive: true });
		fs.writeFileSync(path.join(reportDir, `theme-contrast-${label}-${name}.json`), JSON.stringify(measured, null, 1));

		const failures = measured.filter(isFailure)
			.map((f) => `${f.page} [${f.state}] ${f.kind} ${f.key} "${f.text}" ${f.fg} on ${f.bg} = ${f.ratio} < ${f.required}`);
		expect(failures, `${name} contrast failures`).toEqual([]);
	});
}
