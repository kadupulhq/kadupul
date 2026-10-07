// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Loaded into every page as an init script, so it runs whatever the page's
// CSP says. It measures what the browser painted: colours come from computed
// style and backgrounds from the elements stacked under the measured point,
// which also catches menus and dialogs that sit over content they are not
// nested in.
(function () {
	'use strict';

	const WHITE = { r: 255, g: 255, b: 255, a: 1 };

	function parseColor(value) {
		const m = /rgba?\(([^)]+)\)/.exec(value || '');
		if (!m) {
			return { r: 0, g: 0, b: 0, a: 0 };
		}
		const parts = m[1].split(/[ ,/]+/).filter(Boolean).map(parseFloat);
		return { r: parts[0], g: parts[1], b: parts[2], a: parts.length > 3 ? parts[3] : 1 };
	}

	function over(top, bottom) {
		const a = top.a + bottom.a * (1 - top.a);
		if (a === 0) {
			return { r: 0, g: 0, b: 0, a: 0 };
		}
		const mix = (t, b) => (t * top.a + b * bottom.a * (1 - top.a)) / a;
		return { r: mix(top.r, bottom.r), g: mix(top.g, bottom.g), b: mix(top.b, bottom.b), a };
	}

	function luminance({ r, g, b }) {
		const channel = (v) => {
			const c = v / 255;
			return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
		};
		return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
	}

	function ratio(a, b) {
		const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
		return (hi + 0.05) / (lo + 0.05);
	}

	function hex(c) {
		const h = (v) => Math.round(v).toString(16).padStart(2, '0');
		return '#' + h(c.r) + h(c.g) + h(c.b) + (c.a < 1 ? h(c.a * 255) : '');
	}

	// Cumulative opacity is used only for visibility. Paint opacity is applied
	// once to each completed subtree below, rather than to its individual layers.
	// Cleared on every pointer move and focus change by the callers below,
	// since hover and focus can change any ancestor's opacity.
	let opacities = new WeakMap();

	function cumulativeOpacity(el) {
		if (!el || el.nodeType !== 1) {
			return 1;
		}
		if (!opacities.has(el)) {
			opacities.set(el, parseFloat(getComputedStyle(el).opacity) * cumulativeOpacity(el.parentElement));
		}
		return opacities.get(el);
	}

	function isVisible(el) {
		if (!el.checkVisibility || !el.checkVisibility({ opacityProperty: true, visibilityProperty: true, contentVisibilityAuto: true })) {
			return false;
		}
		return cumulativeOpacity(el) > 0.05;
	}

	// Splits a CSS value on commas that are not inside parentheses.
	function splitTop(value) {
		const parts = [];
		let depth = 0;
		let start = 0;
		for (let i = 0; i < value.length; i++) {
			const ch = value[i];
			if (ch === '(') {
				depth++;
			} else if (ch === ')') {
				depth--;
			} else if (ch === ',' && depth === 0) {
				parts.push(value.slice(start, i).trim());
				start = i + 1;
			}
		}
		parts.push(value.slice(start).trim());
		return parts;
	}

	function length(token, extent) {
		if (/%$/.test(token)) {
			return parseFloat(token) / 100 * extent;
		}
		return parseFloat(token);
	}

	function position(tokens, box) {
		const words = { left: 0, center: 50, right: 100, top: 0, bottom: 100 };
		let x = box.width / 2;
		let y = box.height / 2;
		const list = tokens.filter(Boolean);
		if (list.length === 1 && (list[0] === 'top' || list[0] === 'bottom')) {
			y = words[list[0]] / 100 * box.height;
		} else if (list.length) {
			const [a, b] = list;
			const flip = (a === 'top' || a === 'bottom') && b !== undefined && (b === 'left' || b === 'right' || b === 'center');
			const [px, py] = flip ? [b, a] : [a, b];
			x = px in words ? words[px] / 100 * box.width : length(px, box.width);
			if (py !== undefined) {
				y = py in words ? words[py] / 100 * box.height : length(py, box.height);
			}
		}
		return { x, y };
	}

	// The colour one gradient paints at (x, y), or null when the syntax is
	// beyond this parser, in which case the caller falls back to every stop.
	function gradientAt(image, box, x, y) {
		const m = /^(-webkit-)?(repeating-)?(linear|radial)-gradient\((.*)\)$/.exec(image);
		if (!m || m[2]) {
			return null;
		}
		const legacy = Boolean(m[1]);
		const args = splitTop(m[4]);
		const config = [];
		const stops = [];
		for (const arg of args) {
			const c = /^(rgba?\([^)]+\))\s*(.*)$/.exec(arg);
			if (c) {
				const at = c[2].split(/\s+/).filter(Boolean);
				stops.push({ color: parseColor(c[1]), at: at.length ? at[0] : null });
			} else if (!stops.length) {
				config.push(arg);
			} else {
				return null;
			}
		}
		if (stops.length < 2) {
			return null;
		}
		const px = x - box.left;
		const py = y - box.top;
		let t;
		let extent;
		if (m[3] === 'linear') {
			let angle = 180;
			const dir = (config[0] || '').trim();
			if (dir) {
				const deg = /^(-?[\d.]+)(deg|turn|rad)$/.exec(dir);
				if (deg) {
					const unit = { deg: 1, turn: 360, rad: 180 / Math.PI }[deg[2]];
					angle = parseFloat(deg[1]) * unit;
					if (legacy) {
						angle = 90 - angle;
					}
				} else {
					const words = dir.replace(/^to\s+/, '').split(/\s+/);
					const toward = legacy
						? words.map((w) => ({ top: 'bottom', bottom: 'top', left: 'right', right: 'left' })[w])
						: words;
					const vx = toward.includes('right') ? 1 : (toward.includes('left') ? -1 : 0);
					const vy = toward.includes('bottom') ? 1 : (toward.includes('top') ? -1 : 0);
					if (!vx && !vy) {
						return null;
					}
					// Corner directions aim at the corner of this box.
					angle = Math.atan2(vx * (vy ? box.height : 1), -vy * (vx ? box.width : 1)) * 180 / Math.PI;
				}
			}
			const rad = angle * Math.PI / 180;
			const sin = Math.sin(rad);
			const cos = Math.cos(rad);
			extent = Math.abs(box.width * sin) + Math.abs(box.height * cos);
			t = ((px - box.width / 2) * sin - (py - box.height / 2) * cos) / extent + 0.5;
		} else {
			const words = config.join(' ').split(/\s+/).filter(Boolean);
			let centre = { x: box.width / 2, y: box.height / 2 };
			let shape = 'ellipse';
			let size = 'farthest-corner';
			const atIndex = words.indexOf('at');
			let rest = words;
			if (legacy && config.length) {
				// -webkit-radial-gradient(<position>, <shape> <size>, ...)
				const first = config[0].split(/\s+/);
				if (!/^(circle|ellipse|cover|contain|closest|farthest)/.test(first[0])) {
					centre = position(first, box);
					rest = config.slice(1).join(' ').split(/\s+/).filter(Boolean);
				}
			} else if (atIndex >= 0) {
				centre = position(words.slice(atIndex + 1), box);
				rest = words.slice(0, atIndex);
			}
			for (const w of rest) {
				if (w === 'circle' || w === 'ellipse') {
					shape = w;
				} else if (w === 'cover') {
					size = 'farthest-corner';
				} else if (w === 'contain') {
					size = 'closest-side';
				} else if (/^(closest|farthest)-(side|corner)$/.test(w)) {
					size = w;
				} else if (w) {
					return null;
				}
			}
			const dx = [centre.x, box.width - centre.x].map(Math.abs);
			const dy = [centre.y, box.height - centre.y].map(Math.abs);
			const side = size.startsWith('closest') ? Math.min : Math.max;
			let rx = side(...dx);
			let ry = side(...dy);
			if (shape === 'circle') {
				const corners = [0, 1].flatMap((i) => [0, 1].map((j) => Math.hypot(dx[i], dy[j])));
				const r = size.endsWith('corner') ? side(...corners) : side(rx, ry);
				rx = r;
				ry = r;
			} else if (size.endsWith('corner')) {
				rx *= Math.SQRT2;
				ry *= Math.SQRT2;
			}
			if (!rx || !ry) {
				return null;
			}
			extent = rx;
			t = Math.hypot((px - centre.x) / rx, (py - centre.y) / ry);
		}
		// Unpositioned stops share the space between their neighbours.
		const pos = stops.map((s) => (s.at === null ? null : length(s.at, extent) / extent));
		if (pos[0] === null) {
			pos[0] = 0;
		}
		if (pos[pos.length - 1] === null) {
			pos[pos.length - 1] = Math.max(1, ...pos.filter((p) => p !== null));
		}
		for (let i = 1; i < pos.length; i++) {
			if (pos[i] === null) {
				let j = i;
				while (pos[j] === null) {
					j++;
				}
				for (let k = i; k < j; k++) {
					pos[k] = pos[i - 1] + (pos[j] - pos[i - 1]) * (k - i + 1) / (j - i + 1);
				}
			}
			pos[i] = Math.max(pos[i], pos[i - 1]);
		}
		if (t <= pos[0]) {
			return stops[0].color;
		}
		for (let i = 1; i < stops.length; i++) {
			if (t <= pos[i]) {
				const f = pos[i] === pos[i - 1] ? 1 : (t - pos[i - 1]) / (pos[i] - pos[i - 1]);
				const a = stops[i - 1].color;
				const b = stops[i].color;
				return { r: a.r + (b.r - a.r) * f, g: a.g + (b.g - a.g) * f, b: a.b + (b.b - a.b) * f, a: a.a + (b.a - a.a) * f };
			}
		}
		return stops[stops.length - 1].color;
	}

	// The box a background image is sized against: the padding box, the
	// viewport for fixed backgrounds, and the root box for a body background
	// that the canvas has taken over.
	function paintBox(el, style) {
		if (style.backgroundAttachment === 'fixed') {
			return new DOMRect(0, 0, innerWidth, innerHeight);
		}
		const target = el === document.body && parseColor(getComputedStyle(document.documentElement).backgroundColor).a === 0
			&& getComputedStyle(document.documentElement).backgroundImage === 'none' ? document.documentElement : el;
		const r = target.getBoundingClientRect();
		const s = target === el ? style : getComputedStyle(target);
		const bl = parseFloat(s.borderLeftWidth);
		const bt = parseFloat(s.borderTopWidth);
		return new DOMRect(r.left + bl, r.top + bt, r.width - bl - parseFloat(s.borderRightWidth), r.height - bt - parseFloat(s.borderBottomWidth));
	}

	// Every paint layer of one element at (x, y), top first, each a list of
	// the colours it may put there. A gradient is evaluated at the point; one
	// this parser cannot follow contributes every stop, so the worst decides.
	function layersOf(el, x, y) {
		const style = getComputedStyle(el);
		const layers = [];
		let image = false;
		let gradient = false;
		const bgImage = style.backgroundImage;
		if (bgImage && bgImage !== 'none') {
			const sized = style.backgroundSize === 'auto' || style.backgroundSize === 'auto auto' || style.backgroundSize === '100% 100%';
			for (const img of splitTop(bgImage)) {
				if (/gradient/.test(img)) {
					gradient = true;
					const at = sized && x !== undefined ? gradientAt(img, paintBox(el, style), x, y) : null;
					const stops = at ? [at] : (img.match(/rgba?\([^)]+\)/g) || []).map(parseColor);
					if (stops.length) {
						layers.push(stops);
					}
				} else if (/url\(/.test(img)) {
					image = true;
					layers.push([{ r: 0, g: 0, b: 0, a: 0, image: true }]);
				}
			}
		}
		const fill = parseColor(style.backgroundColor);
		if (fill.a > 0) {
			layers.push([fill]);
		}
		return { layers, image, gradient };
	}

	// What the canvas shows at (x, y): the root background, or the body's
	// when the root has none.
	function canvasColors(x, y) {
		for (const el of [document.documentElement, document.body]) {
			if (!el) {
				continue;
			}
			const found = layersOf(el, x, y);
			if (found.layers.length) {
				return found.layers.reverse().reduce(
					(below, layer) => layer.flatMap((top) => below.map((bottom) => over(top, bottom))),
					[WHITE],
				);
			}
		}
		return [WHITE];
	}

	// Candidate backgrounds at (x, y) under `self`. Elements above `self` in
	// the stack, other than its own descendants, are ignored: the caller has
	// already decided that `self` is what shows at this point.
	function backgroundsAt(x, y, self, skipSelf) {
		// Hit testing skips table rows and row groups, whose backgrounds still
		// paint behind their cells, so put the skipped ancestors back. An
		// ancestor whose box does not cover the point paints nothing there;
		// content that overflows it shows whatever lies further down.
		const hits = document.elementsFromPoint(x, y);
		const stack = [];
		hits.forEach((el, i) => {
			stack.push(el);
			const next = hits[i + 1];
			if (next && next.contains(el)) {
				for (let n = el.parentElement; n && n !== next; n = n.parentElement) {
					const box = n.getBoundingClientRect();
					if (x >= box.left && x < box.right && y >= box.top && y < box.bottom) {
						stack.push(n);
					}
				}
			}
		});
		let start = 0;
		if (self) {
			const idx = stack.indexOf(self);
			if (idx >= 0) {
				start = skipSelf ? idx + 1 : idx;
			}
		}
        // Rebuild the paint ancestry, including non-hit ancestors whose opacity
        // still groups their overflowing descendants. Hit order gives sibling
        // paint order; each node flattens its own layers and children first.
        const nodes = new Map();
        function nodeFor(el) {
            if (!nodes.has(el)) {
                const node = { el, children: [], paint: null, opacity: parseFloat(getComputedStyle(el).opacity) };
                nodes.set(el, node);
                if (el.parentElement) nodeFor(el.parentElement).children.push(node);
            }
            return nodes.get(el);
        }
        for (const el of stack.slice(start).reverse()) {
            if (self && skipSelf && self.contains(el)) continue;
            nodeFor(el).paint = layersOf(el, x, y);
        }
        if (self) nodeFor(self);
        const roots = [...nodes.values()].filter((node) => !node.el.parentElement);
        const transparent = { r: 0, g: 0, b: 0, a: 0 };
        const flatten = (node, foreground) => {
            let candidates = [{ color: transparent, image: false, gradient: false }];
            for (const layer of (node.paint?.layers || []).slice().reverse()) {
                candidates = layer.flatMap((top) => candidates.map((below) => ({
                    color: over(top, below.color),
                    image: !!top.image || (top.a < 0.999 && below.image),
                    gradient: !!node.paint.gradient || below.gradient,
                })));
            }
            for (const child of node.children) {
                const painted = flatten(child, foreground);
                candidates = painted.flatMap((top) => candidates.map((below) => ({
                    color: over(top.color, below.color),
                    image: top.image || (top.color.a < 0.999 && below.image),
                    gradient: top.gradient || below.gradient,
                })));
            }
            if (foreground && node.el === self) {
                candidates = candidates.map((below) => ({ ...below, color: over(foreground, below.color) }));
            }
            return candidates.map((candidate) => ({ ...candidate, color: { ...candidate.color, a: candidate.color.a * node.opacity } }));
        };
        function paint(foreground) {
            let candidates = [{ color: WHITE, image: false, gradient: false }];
            for (const root of roots) {
                candidates = flatten(root, foreground).flatMap((top) => candidates.map((below) => ({
                    color: over(top.color, below.color),
                    image: top.image || (top.color.a < 0.999 && below.image),
                    gradient: top.gradient || below.gradient,
                })));
            }
            return candidates;
        }
        const backgrounds = paint(null);
        return {
            list: backgrounds.map((candidate, index) => ({ ...candidate.color, paint: (foreground) => paint(foreground)[index].color })),
            image: backgrounds.some((candidate) => candidate.image),
            gradient: backgrounds.some((candidate) => candidate.gradient),
        };
    }

	function worst(fg, backgrounds) {
		let best = null;
		for (const bg of backgrounds) {
			const painted = bg.paint ? bg.paint(fg) : over(fg, bg);
			const r = ratio(painted, bg);
			if (!best || r < best.ratio) {
				best = { ratio: r, fg: painted, bg };
			}
		}
		return best;
	}

	function describe(el) {
		const parts = [];
		for (let n = el; n && n.nodeType === 1 && n !== document.body; n = n.parentElement) {
			let part = n.tagName.toLowerCase();
			if (n.id && !/^\d|_\d+$|\d{3,}/.test(n.id)) {
				part += '#' + n.id;
			} else {
				const classes = [...n.classList].filter((c) => !/^(ui-state-hover|ui-state-focus|hover|odd|even|selected|ui-state-active|jstree-hovered)$/.test(c)).sort();
				if (classes.length) {
					part += '.' + classes.join('.');
				}
			}
			parts.unshift(part);
			if (n.id && !/^\d|_\d+$|\d{3,}/.test(n.id)) {
				break;
			}
		}
		return parts.slice(-6).join(' > ');
	}

	function textRect(el) {
		const rects = [];
		for (const node of el.childNodes) {
			if (node.nodeType === 3 && node.textContent.trim()) {
				const range = document.createRange();
				range.selectNodeContents(node);
				for (const r of range.getClientRects()) {
					if (r.width > 0.5 && r.height > 0.5) {
						rects.push(r);
					}
				}
			}
		}
		return rects;
	}

	function isLarge(style) {
		const size = parseFloat(style.fontSize);
		const weight = parseInt(style.fontWeight, 10) || 400;
		return size >= 24 || (size >= 18.66 && weight >= 700);
	}

	function isDisabled(el) {
		return Boolean(el.closest(':disabled, .ui-state-disabled, [aria-disabled="true"], .disabled, .ui-button-disabled, .ui-selectmenu-disabled'));
	}

	function isIcon(el, style) {
		const before = getComputedStyle(el, '::before');
		const content = before.content;
		if (!content || content === 'none' || content === 'normal' || content === '""') {
			return false;
		}
		return /Font Awesome|FontAwesome|fontello|Glyph/i.test(before.fontFamily + ' ' + style.fontFamily);
	}

	// A point on the text that the element itself paints. Text covered by
	// another box, clipped away or scrolled out of the viewport is skipped:
	// there the hit lands on some other box, or on an ancestor whose overflow
	// cut the text off. Text that ignores the pointer is hit through to its
	// ancestors, so it is accepted that way.
	function probePoint(el, rects) {
		const passive = getComputedStyle(el).pointerEvents === 'none';
		for (const r of rects) {
			const x = r.left + Math.min(r.width / 2, 4);
			const y = r.top + r.height / 2;
			if (x < 0 || y < 0 || x >= innerWidth || y >= innerHeight) {
				continue;
			}
			const top = document.elementFromPoint(x, y);
			if (top && (top === el || el.contains(top) || (passive && top.contains(el)))) {
				// The glyphs span the line box, so a gradient behind them is
				// sampled at its top and bottom as well as the middle.
				const inset = Math.min(2, r.height / 4);
				return { x, y, also: [[x, r.top + inset], [x, r.bottom - inset], [Math.min(r.right - 2, innerWidth - 1), y]] };
			}
		}
		return null;
	}

	function measureText(scope) {
		const root = scope || document.body;
		const out = [];
		const els = [root, ...root.querySelectorAll('*')];
		for (const el of els) {
			if (/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|OPTION|svg)$/i.test(el.tagName)) {
				continue;
			}
			const style = getComputedStyle(el);
			const icon = isIcon(el, style);
			let rects = textRect(el);
			let text = rects.length ? [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join('').trim().replace(/\s+/g, ' ').slice(0, 40) : '';
			const valued = /^(INPUT|TEXTAREA)$/.test(el.tagName) && !/^(hidden|checkbox|radio|image|range|color|file)$/i.test(el.type || '') && el.value;
			if (!rects.length && valued) {
				const r = el.getBoundingClientRect();
				rects = [new DOMRect(r.left + parseFloat(style.paddingLeft) + parseFloat(style.borderLeftWidth) + 2, r.top + r.height / 2 - 2, 4, 4)];
				text = String(el.value).slice(0, 40);
			}
			if (!rects.length && icon) {
				const r = el.getBoundingClientRect();
				if (r.width > 0 && r.height > 0) {
					rects = [r];
					text = '[icon]';
				}
			}
			if (!rects.length || !isVisible(el)) {
				continue;
			}
			const point = probePoint(el, rects);
			if (!point) {
				continue;
			}
			const fg = parseColor(style.webkitTextFillColor && style.webkitTextFillColor !== style.color ? style.webkitTextFillColor : style.color);
			const bgs = backgroundsAt(point.x, point.y, el, false);
			const shows = (x, y) => {
				const hit = document.elementFromPoint(x, y);
				return hit && (hit === el || el.contains(hit));
			};
			for (const [ax, ay] of (bgs.gradient ? point.also.filter(([x, y]) => shows(x, y)) : [])) {
				const more = backgroundsAt(ax, ay, el, false);
				if (more.list.length) {
					bgs.list.push(...more.list);
				}
			}
			const w = worst(fg, bgs.list);
			const kind = rects.length && text !== '[icon]' ? 'text' : 'icon';
			const required = kind === 'icon' ? 3 : (isLarge(style) ? 3 : 4.5);
			out.push({
				kind,
				key: describe(el),
				text,
				fg: hex(w.fg),
				bg: hex(w.bg),
				ratio: Math.round(w.ratio * 100) / 100,
				required,
				disabled: isDisabled(el),
				image: bgs.image,
			});
		}
		return out;
	}

	// The element that draws the ring for a focused control. Switch inputs are
	// invisible and ring the slider next to them; midwinter and sunrise hide
	// plain checkboxes and ring the label's drawn box.
	function ringOwners(el) {
		const owners = [{ el, pseudo: null }];
		const next = el.nextElementSibling;
		if (next) {
			owners.push({ el: next, pseudo: null }, { el: next, pseudo: '::before' });
		}
		if (el.id) {
			for (const label of document.querySelectorAll(`label[for="${CSS.escape(el.id)}"]`)) {
				owners.push({ el: label, pseudo: '::before' }, { el: label, pseudo: null });
			}
		}
		return owners;
	}

	// Colours just outside `target`. With `container`, only points that still
	// fall inside that element count, so a control touching the next row is
	// judged against the row it sits in.
	function outerBackground(target, rect, distance, container) {
		const points = [
			[rect.left - distance, rect.top + rect.height / 2],
			[rect.right + distance, rect.top + rect.height / 2],
			[rect.left + rect.width / 2, rect.top - distance],
			[rect.left + rect.width / 2, rect.bottom + distance],
		].filter(([x, y]) => x >= 0 && y >= 0 && x < innerWidth && y < innerHeight);
		// The first painted box under the point decides whose colour shows.
		const painter = (x, y) => document.elementsFromPoint(x, y).find((el) => !target.contains(el)
			&& (parseColor(getComputedStyle(el).backgroundColor).a > 0 || getComputedStyle(el).backgroundImage !== 'none'));
		const inside = container
			? points.filter(([x, y]) => {
				const el = painter(x, y);
				return el && (el === container || container.contains(el));
			})
			: points;
		const list = [];
		for (const [x, y] of (inside.length ? inside : points)) {
			list.push(...backgroundsAt(x, y, target, true).list);
		}
		return list.length ? list : canvasColors(0, 0);
	}

	function snapshotStyle(el, pseudo) {
		const s = getComputedStyle(el, pseudo);
		return {
			outlineStyle: s.outlineStyle,
			outlineWidth: parseFloat(s.outlineWidth),
			outlineColor: s.outlineColor,
			outlineOffset: parseFloat(s.outlineOffset) || 0,
			boxShadow: s.boxShadow,
			borderColor: s.borderTopColor,
			borderWidth: parseFloat(s.borderTopWidth),
			background: s.backgroundColor,
		};
	}

	function focusIndicator(el, before) {
		const result = { kind: 'focus', key: describe(el), text: (el.innerText || el.value || el.getAttribute('aria-label') || el.title || '').trim().replace(/\s+/g, ' ').slice(0, 40), disabled: isDisabled(el), required: 3 };
		// Focus scrolls the control itself into view. One parked outside the
		// viewport leaves its ring wherever the page was, so Tab gives no
		// visible cue however well the ring would contrast.
		const own = el.getBoundingClientRect();
		if (own.bottom < 0 || own.right < 0 || own.top > innerHeight || own.left > innerWidth) {
			return { ...result, via: 'off-screen', fg: 'off-screen', bg: 'viewport', ratio: 0 };
		}
		for (const { el: owner, pseudo } of ringOwners(el)) {
			if (!isVisible(owner) && pseudo === null) {
				continue;
			}
			const now = snapshotStyle(owner, pseudo);
			const rect = owner.getBoundingClientRect();
			// Chrome draws outline-style auto as a dark ring inside a white one,
			// so one of the two clears 3:1 on any background.
			if (now.outlineStyle === 'auto') {
				const bgs = outerBackground(owner, rect, 2);
				let low = Infinity;
				let at = bgs[0];
				for (const bg of bgs) {
					const r = Math.max(ratio({ r: 16, g: 16, b: 16 }, bg), ratio(WHITE, bg));
					if (r < low) {
						low = r;
						at = bg;
					}
				}
				return { ...result, via: 'outline auto' + (pseudo || ''), fg: '#101010/#ffffff', bg: hex(at), ratio: Math.round(low * 100) / 100 };
			}
			if (now.outlineStyle !== 'none' && now.outlineWidth > 0 && parseColor(now.outlineColor).a > 0) {
				const color = parseColor(now.outlineColor);
				// A hard shadow reaching past the ring is a halo: the ring has to
				// stand out from the halo, and one of the two from the page.
				let halo = null;
				for (const shadow of splitTop(now.boxShadow === 'none' ? '' : now.boxShadow)) {
					const m = /^(rgba?\([^)]+\))\s+0px\s+0px\s+0px\s+([\d.]+)px$/.exec(shadow);
					if (m && parseFloat(m[2]) > now.outlineOffset + now.outlineWidth && (!halo || parseFloat(m[2]) > halo.spread)) {
						halo = { color: over(parseColor(m[1]), WHITE), spread: parseFloat(m[2]) };
					}
				}
				if (halo && now.outlineOffset >= 0) {
					const page = outerBackground(owner, rect, halo.spread + 1);
					const ring = ratio(over(color, halo.color), halo.color);
					let low = { ratio: Infinity, bg: page[0] };
					for (const bg of page) {
						const r = Math.max(ratio(over(color, bg), bg), ratio(halo.color, bg));
						if (r < low.ratio) {
							low = { ratio: r, bg };
						}
					}
					const r = Math.min(ring, low.ratio);
					return { ...result, via: 'outline and halo' + (pseudo || ''), fg: hex(color) + '/' + hex(halo.color), bg: hex(low.bg), ratio: Math.round(r * 100) / 100, width: now.outlineWidth };
				}
				const inside = now.outlineOffset + now.outlineWidth <= 0;
				const bgs = inside
					? backgroundsAt(rect.left + rect.width / 2, rect.top + 1 - now.outlineOffset, owner, false).list
					: outerBackground(owner, rect, Math.max(now.outlineOffset + now.outlineWidth / 2, 0.5) + 0.5);
				const w = worst(color, bgs);
				return { ...result, via: 'outline' + (pseudo || ''), fg: hex(w.fg), bg: hex(w.bg), ratio: Math.round(w.ratio * 100) / 100, width: now.outlineWidth };
			}
			const shadow = /rgba?\([^)]+\)[^,]*?(-?[\d.]+px\s+){2,3}/.exec(now.boxShadow);
			const prev = before && before.get(owner.tagName + (pseudo || '') + describe(owner));
			if (now.boxShadow !== 'none' && shadow && (!prev || prev.boxShadow !== now.boxShadow)) {
				const color = parseColor(now.boxShadow);
				const w = worst(color, outerBackground(owner, rect, 1.5));
				return { ...result, via: 'box-shadow' + (pseudo || ''), fg: hex(w.fg), bg: hex(w.bg), ratio: Math.round(w.ratio * 100) / 100 };
			}
			if (prev && now.borderWidth > 0 && prev.borderColor !== now.borderColor) {
				const r = ratio(over(parseColor(now.borderColor), WHITE), over(parseColor(prev.borderColor), WHITE));
				return { ...result, via: 'border change', fg: hex(parseColor(now.borderColor)), bg: hex(parseColor(prev.borderColor)), ratio: Math.round(r * 100) / 100 };
			}
			if (prev && prev.background !== now.background) {
				const b = backgroundsAt(rect.left + 1, rect.top + 1, owner, false).list[0];
				const r = ratio(b, over(parseColor(prev.background), canvasColors(rect.left, rect.top)[0]));
				return { ...result, via: 'background change', fg: hex(b), bg: hex(parseColor(prev.background)), ratio: Math.round(r * 100) / 100 };
			}
		}
		// A focused jQuery UI menu marks its active item instead of drawing a
		// ring, so that item's fill against the menu is the indicator.
		const active = el.querySelector(':scope > .ui-menu-item > .ui-state-active, :scope > .ui-menu-item.ui-state-active');
		if (active) {
			const ar = active.getBoundingClientRect();
			const fill = backgroundsAt(ar.left + 2, ar.top + ar.height / 2, active, false).list;
			const around = backgroundsAt(ar.left + 2, ar.top + ar.height / 2, active, true).list;
			let low = null;
			for (const f of fill) {
				for (const o of around) {
					const r = ratio(f, o);
					if (!low || r < low.ratio) {
						low = { ratio: r, fg: f, bg: o };
					}
				}
			}
			const border = parseColor(getComputedStyle(active).borderTopColor);
			const edge = parseFloat(getComputedStyle(active).borderTopWidth) > 0 ? worst(border, around) : null;
			const best = edge && edge.ratio > low.ratio ? { ...edge, via: 'active item border' } : { ...low, via: 'active item fill' };
			return { ...result, via: best.via, fg: hex(best.fg), bg: hex(best.bg), ratio: Math.round(best.ratio * 100) / 100 };
		}
		return { ...result, via: 'none', fg: '', bg: '', ratio: 0 };
	}

	// Styles of every ring owner before focus, keyed the way focusIndicator
	// looks them up, so a border or background change can be recognised.
	function preFocus(el) {
		const map = new Map();
		for (const { el: owner, pseudo } of ringOwners(el)) {
			map.set(owner.tagName + (pseudo || '') + describe(owner), snapshotStyle(owner, pseudo));
		}
		return map;
	}

	// WCAG 1.4.11 asks that a control whose shape is its only cue be told apart
	// from what surrounds it. Buttons and links carry text and are left out.
	function boundaryTargets() {
		return [...document.querySelectorAll('input[type=text], input[type=password], input[type=email], input[type=number], input[type=search], input[type=url], input:not([type]), textarea, select, .ui-selectmenu-button, .ui-multiselect, .checkboxSlider, .radioSlider, input[type=checkbox], input[type=radio]')]
			.filter((el) => isVisible(el) && el.getBoundingClientRect().width > 2);
	}

	function measureBoundary(el) {
		let style = getComputedStyle(el);
		// An input with no border or fill of its own is part of a composite
		// control, such as the location autocomplete, whose wrapper draws the
		// edge.
		const bare = (s) => parseFloat(s.borderTopWidth) === 0 && parseColor(s.backgroundColor).a === 0 && s.backgroundImage === 'none';
		const borderless = parseFloat(style.borderTopWidth) === 0;
		if (/^(INPUT|TEXTAREA)$/.test(el.tagName) && !/^(checkbox|radio)$/.test(el.type) && borderless) {
			for (let n = el.parentElement, i = 0; n && i < 3; n = n.parentElement, i++) {
				const ns = getComputedStyle(n);
				if (bare(style) ? !bare(ns) : parseFloat(ns.borderTopWidth) > 0) {
					el = n;
					style = getComputedStyle(n);
					break;
				}
			}
		}
		const rect = el.getBoundingClientRect();
		if (rect.right < 0 || rect.bottom < 0 || rect.left >= innerWidth || rect.top >= innerHeight) {
			return null;
		}
		const result = { kind: 'boundary', key: describe(el), text: el.name || el.id || '', disabled: isDisabled(el), required: 3 };
		// Native checkboxes and radios are drawn by the browser, not the theme.
		if (/^(checkbox|radio)$/.test(el.type) && style.appearance !== 'none') {
			return null;
		}
		let surface = el.parentElement;
		while (surface && surface !== document.body) {
			const ss = getComputedStyle(surface);
			if (parseColor(ss.backgroundColor).a > 0 || ss.backgroundImage !== 'none') {
				break;
			}
			surface = surface.parentElement;
		}
		const outside = outerBackground(el, rect, 1, surface);
		const fill = backgroundsAt(rect.left + rect.width / 2, rect.top + rect.height / 2, el, false).list;
		const candidates = [];
		const bw = parseFloat(style.borderTopWidth) + parseFloat(style.borderBottomWidth);
		if (bw > 0 && style.borderTopStyle !== 'none') {
			const border = parseColor(style.borderTopColor);
			const w = worst(border, outside);
			candidates.push({ via: 'border', ...w });
		}
		// A hard-edged box shadow draws the same edge a border would.
		for (const shadow of splitTop(style.boxShadow === 'none' ? '' : style.boxShadow)) {
			const m = /^(rgba?\([^)]+\))\s+(-?[\d.]+)px\s+(-?[\d.]+)px\s+([\d.]+)px\s+(-?[\d.]+)px/.exec(shadow);
			if (m && parseFloat(m[4]) === 0 && parseFloat(m[5]) >= 1) {
				candidates.push({ via: 'box-shadow', ...worst(parseColor(m[1]), outside) });
			}
		}
		let fillWorst = null;
		for (const f of fill) {
			for (const o of outside) {
				const r = ratio(f, o);
				if (!fillWorst || r < fillWorst.ratio) {
					fillWorst = { via: 'fill', ratio: r, fg: f, bg: o };
				}
			}
		}
		if (fillWorst) {
			candidates.push(fillWorst);
		}
		const best = candidates.sort((a, b) => b.ratio - a.ratio)[0];
		return { ...result, via: best.via, fg: hex(best.fg), bg: hex(best.bg), ratio: Math.round(best.ratio * 100) / 100 };
	}

	function focusables() {
		const sel = 'a[href], button, input:not([type=hidden]), select, textarea, [tabindex]:not([tabindex="-1"]), .ui-selectmenu-button';
		return [...document.querySelectorAll(sel)].filter((el) => {
			if (el.tabIndex < 0 || el.closest('[inert]')) {
				return false;
			}
			if (/^(checkbox|radio)$/.test(el.type)) {
				return true;
			}
			return isVisible(el) && el.getBoundingClientRect().width > 0;
		});
	}

	function hoverables() {
		return [...document.querySelectorAll('a[href], button, .ui-button, tr.selectable, .ui-menu-item, .ui-menu-item-wrapper, li.menuitem, .jstree-anchor, .tab, .ui-tabs-anchor, .ui-selectmenu-button, input[type=button], input[type=submit]')]
			.filter((el) => isVisible(el) && el.getBoundingClientRect().width > 0);
	}

	// Groups elements that share a rendering, so the slow hover and focus
	// passes visit a few of each kind rather than every row of a long table.
	function signature(el) {
		const own = el.tagName + '.' + [...el.classList].filter((c) => !/^(odd|even|hover|selected|ui-state-\w+|jstree-\w+ed)$/.test(c)).sort().join('.');
		const ctx = [];
		for (let n = el.parentElement; n && n !== document.body && ctx.length < 4; n = n.parentElement) {
			const cls = [...n.classList].filter((c) => !/^(odd|even|hover|ui-state-hover|ui-state-focus)$/.test(c)).sort().join('.');
			if (cls || n.id) {
				ctx.push(n.tagName + (n.id && !/\d{2,}/.test(n.id) ? '#' + n.id : '') + (cls ? '.' + cls : ''));
			}
		}
		return own + ' < ' + ctx.join(' < ') + (el.type ? '[' + el.type + ']' : '');
	}

	function pick(list, perSignature) {
		const seen = new Map();
		const chosen = [];
		list.forEach((el, index) => {
			const sig = signature(el);
			const n = seen.get(sig) || 0;
			if (n < perSignature) {
				seen.set(sig, n + 1);
				chosen.push(index);
			}
		});
		return chosen;
	}

	const fresh = (fn) => (...args) => {
		opacities = new WeakMap();
		return fn(...args);
	};

	window.__contrast = {
		measureText: fresh(measureText),
		focusIndicator: fresh(focusIndicator),
		preFocus: fresh(preFocus),
		boundaryTargets: fresh(boundaryTargets),
		measureBoundary: fresh(measureBoundary),
		focusables: fresh(focusables),
		hoverables: fresh(hoverables),
		pick,
		describe,
		backgroundsAt: fresh(backgroundsAt),
	};
}());
