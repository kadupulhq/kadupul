// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const path = require("node:path");
const fs = require("node:fs");
const { test, expect } = require("@playwright/test");

test.beforeEach(async ({ page }) => {
  // A real origin is required by the pager's localStorage/cookie persistence.
  await page.goto("/tests/e2e/theme-smoke.html");
});

async function load(page, ...files) {
  for (const file of files) {
    await page.addScriptTag({
      path: path.resolve(__dirname, "../../include/js", file),
    });
  }
}

test("graph input delete handler sends the CSRF token by POST", async ({ page }) => {
  const controller = fs.readFileSync(path.resolve(__dirname, "../../graph_templates.php"), "utf8");
  const handler = controller.match(/\$\('\.inputDeleteMarker'\)\.on\('click', function\(event\) \{[\s\S]*?\n\t\t\}\);/);
  expect(handler).not.toBeNull();
  await page.setContent('<a class="inputDeleteMarker" href="graph_templates_inputs.php?action=input_remove&id=7&graph_template_id=2">Delete input</a>');
  await load(page, "jquery.js");
  await page.evaluate(() => {
    window.csrfMagicToken = "test-body-token";
    window.loadPageUsingPost = (url, data) => { window.deleteRequest = { url, data }; };
  });
  await page.addScriptTag({ content: handler[0] });
  await page.getByText("Delete input", { exact: true }).click();
  expect(await page.evaluate(() => window.deleteRequest)).toEqual({
    url: "graph_templates_inputs.php?action=input_remove&id=7&graph_template_id=2",
    data: { __csrf_magic: "test-body-token" },
  });
});

test("spike removal and dry-run requests carry POST tokens", async ({ page }) => {
  const source = fs.readFileSync(path.resolve(__dirname, "../../include/layout.js"), "utf8");
  const start = source.indexOf("function removeSpikes(");
  const end = source.indexOf("/** buildGraphImage", start);
  expect(start).toBeGreaterThan(-1);
  expect(end).toBeGreaterThan(start);
  await page.setContent('<img id="graph_2" graph_start="100" graph_end="200">');
  await load(page, "jquery.js");
  await page.evaluate(() => {
    window.urlPath = "/";
    window.csrfMagicToken = "body-token";
    window.closeDateFilters = () => {};
    window.spikeRequests = [];
    $.ajax = options => {
      window.spikeRequests.push(options);
      return { done() { return this; }, fail() { return this; } };
    };
  });
  await page.addScriptTag({ content: source.slice(start, end) });
  await page.evaluate(() => {
    removeSpikes("stddev", false, 2);
    removeSpikes("fill", true, 2);
  });
  const requests = await page.evaluate(() => window.spikeRequests);
  expect(requests).toHaveLength(2);
  for (const request of requests) {
    expect(request.type).toBe("POST");
    expect(request.dataType).toBe("json");
    expect(request.data).toEqual({ __csrf_magic: "body-token" });
    expect(request.url).toContain("local_graph_id=2&outlier-start=100&outlier-end=200");
  }
  expect(requests[0].url).not.toContain("dryrun");
  expect(requests[1].url).toContain("dryrun=true");
});

test("DOMPurify abort clears shadow and template trees and unsafe URI attributes", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    const shadow = root.attachShadow({ mode: "open" });
    shadow.innerHTML = '<a href="javascript:alert(1)">link</a><template><iframe srcdoc="unsafe"></iframe></template><script>unsafe</script>';
    const link = shadow.firstChild;
    const content = shadow.querySelector("template").content;
    const frame = content.firstChild;
    DOMPurify.addHook("beforeSanitizeElements", () => { throw new Error("abort"); });
    let message;
    try { DOMPurify.sanitize(root, { IN_PLACE: true }); } catch (error) { message = error.message; }
    DOMPurify.removeAllHooks();
    return { message, shadow: shadow.childNodes.length, template: content.childNodes.length, href: link.hasAttribute("href"), srcdoc: frame.hasAttribute("srcdoc") };
  });
  expect(result).toEqual({ message: "abort", shadow: 0, template: 0, href: false, srcdoc: false });
});

test("DOMPurify severs non-light patch linkage before the first sanitization hook", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    const shadow = root.attachShadow({ mode: "open" });
    shadow.innerHTML = '<span for="target" patchsrc="/patch"></span><template><span for="target" patchsrc="/patch"></span></template>';
    const nodes = [shadow.firstChild, shadow.lastChild.content.firstChild];
    let observed;
    DOMPurify.addHook("beforeSanitizeElements", () => {
      if (!observed) observed = nodes.map(node => [node.hasAttribute("for"), node.hasAttribute("patchsrc")]);
    });
    DOMPurify.sanitize(root, { IN_PLACE: true });
    DOMPurify.removeAllHooks();
    return observed;
  });
  expect(result).toEqual([[false, false], [false, false]]);
});

for (const tree of ["shadow", "template"]) {
  for (const method of ["hasAttribute", "removeAttribute"]) {
    test(`DOMPurify linkage prepass tolerates ${method} clobbering in ${tree}`, async ({ page }) => {
      await load(page, "purify.js");
      const result = await page.evaluate(({ tree, method }) => {
        const root = document.createElement("div");
        let container;
        if (tree === "shadow") {
          container = root.attachShadow({ mode: "open" });
        } else {
          const template = document.createElement("template");
          root.append(template);
          container = template.content;
        }
        const form = document.createElement("form");
        form.setAttribute("patchsrc", "/patch");
        form.setAttribute("for", "target");
        form.innerHTML = `<input name="${method}">`;
        container.append(form);
        const clobbered = typeof form[method] !== "function";
        let observed;
        DOMPurify.addHook("beforeSanitizeElements", () => {
          if (!observed) observed = ["patchsrc", "for"].map(name => Element.prototype.hasAttribute.call(form, name));
        });
        DOMPurify.sanitize(root, { IN_PLACE: true });
        DOMPurify.removeAllHooks();
        return { clobbered, observed };
      }, { tree, method });
      expect(result).toEqual({ clobbered: true, observed: [false, false] });
    });
  }
}

test("DOMPurify sanitizes a shadow root inside a light-DOM template once", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    const template = document.createElement("template");
    root.append(template);
    const host = document.createElement("div");
    template.content.append(host);
    const shadow = host.attachShadow({ mode: "open" });
    shadow.innerHTML = '<img onerror="unsafe()">';
    let calls = 0;
    DOMPurify.addHook("beforeSanitizeShadowDOM", node => { if (node === shadow) calls++; });
    DOMPurify.sanitize(root, { IN_PLACE: true });
    DOMPurify.removeAllHooks();
    return { calls, handler: shadow.firstChild.hasAttribute("onerror") };
  });
  expect(result).toEqual({ calls: 1, handler: false });
});

test("D3 quantileIndex ignores invalid members while retaining original indexes", async ({ page }) => {
  await load(page, "d3.js");
  const result = await page.evaluate(() => {
    const values = [null, 1, NaN, 3, undefined];
    function* iterable() { yield* values; }
    return {
      array: d3.quantileIndex(values, 0.5),
      set: d3.quantileIndex(new Set(values), 0.5),
      generator: d3.quantileIndex(iterable(), 0.5),
      accessor: d3.quantileIndex(values.map(value => ({ value })), 0.5, row => row.value),
      invalid: d3.quantileIndex([null, NaN, undefined], 0.5),
      bounds: [d3.quantileIndex(values, 0), d3.quantileIndex(values, 1)],
    };
  });
  expect(result).toEqual({ array: 1, set: 1, generator: 1, accessor: 1, invalid: -1, bounds: [1, 3] });
});

test("DOMPurify final template scrub includes a template root", async ({ page }) => {
  const source = fs.readFileSync(path.resolve(__dirname, "../../include/js/purify.js"), "utf8");
  const anchor = "    DOMPurify.setConfig = function () {";
  expect(source.split(anchor)).toHaveLength(2);
  await page.addScriptTag({ content: source.replace(anchor, "    DOMPurify.testTemplateScrub = _scrubTemplateExpressions2;\n" + anchor) });
  const result = await page.evaluate(() => {
    const root = document.createElement("template");
    root.content.append(document.createTextNode("{{"), document.createTextNode("unsafe}}"));
    DOMPurify.setConfig({ SAFE_FOR_TEMPLATES: true });
    DOMPurify.testTemplateScrub(root);
    return root.content.textContent;
  });
  expect(result).toBe(" ");
});

test("DOMPurify discarded template cleanup tolerates a missing constructor", async ({ page }) => {
  await page.evaluate(() => { window.HTMLTemplateElement = undefined; });
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    root.innerHTML = '<section><template><img onerror="window.__discardedTemplate = true"></template></section>';
    const section = root.firstChild;
    const img = section.firstChild.content.firstChild;
    DOMPurify.sanitize(root, { IN_PLACE: true, KEEP_CONTENT: false, FORBID_TAGS: ["section"] });
    return { detached: section.parentNode === null, handler: img.hasAttribute("onerror") };
  });
  expect(result).toEqual({ detached: true, handler: false });
});

for (const phase of ["afterSanitizeElements", "customAfterElements", "beforeSanitizeAttributes", "uponSanitizeAttribute", "afterSanitizeAttributes"]) {
  for (const tree of ["light", "template", "shadow"]) {
    test(`DOMPurify defuses ${phase} detached subtrees in ${tree} trees`, async ({ page }) => {
      await load(page, "purify.js");
      const result = await page.evaluate(({ phase, tree }) => {
        const root = document.createElement("div");
        let container = root;
        if (tree === "template") {
          const template = document.createElement("template");
          root.append(template);
          container = template.content;
        } else if (tree === "shadow") {
          container = root.attachShadow({ mode: "open" });
        }
        const target = document.createElement(phase === "customAfterElements" ? "test-card" : "section");
        target.setAttribute("title", "safe");
        target.innerHTML = '<img onerror="window.__detachedHookEvent = true">';
        const img = target.firstChild;
        container.append(target);
        let calls = 0;
        const hook = phase === "customAfterElements" ? "afterSanitizeElements" : phase;
        DOMPurify.addHook(hook, (node) => {
          if (node === target) { calls++; node.remove(); }
        });
        DOMPurify.sanitize(root, { IN_PLACE: true, CUSTOM_ELEMENT_HANDLING: { tagNameCheck: /^test-card$/ } });
        DOMPurify.removeAllHooks();
        return { calls, detached: target.parentNode === null, handler: img.hasAttribute("onerror") };
      }, { phase, tree });
      expect(result).toEqual({ calls: 1, detached: true, handler: false });
    });
  }
}

test("DOMPurify template scrubbing tolerates a missing template constructor", async ({ page }) => {
  await page.evaluate(() => { window.HTMLTemplateElement = undefined; });
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    root.innerHTML = '<template><span>{{unsafe}}</span></template><b>safe</b>';
    DOMPurify.sanitize(root, { IN_PLACE: true, SAFE_FOR_TEMPLATES: true });
    return { templateText: root.querySelector("template").content.textContent, text: root.querySelector("b").textContent };
  });
  expect(result).toEqual({ templateText: " ", text: "safe" });
});

test("DOMPurify final template scrub handles deeply nested fragments without recursion", async ({ page }) => {
  // Expose the production closure only in this isolated test, exercising the final
  // scrub independently of the separate element-sanitization traversal.
  const source = fs.readFileSync(path.resolve(__dirname, "../../include/js/purify.js"), "utf8");
  const anchor = "    DOMPurify.setConfig = function () {";
  expect(source.split(anchor)).toHaveLength(2);
  await page.addScriptTag({ content: source.replace(anchor, "    DOMPurify.testTemplateScrub = _scrubTemplateExpressions2;\n" + anchor) });
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    let container = root;
    for (let depth = 0; depth < 12000; depth++) {
      const template = document.createElement("template");
      container.append(template);
      container = template.content;
    }
    container.append(document.createTextNode("{{"), document.createTextNode("unsafe}}"));
    DOMPurify.setConfig({ SAFE_FOR_TEMPLATES: true });
    DOMPurify.testTemplateScrub(root);
    return container.textContent;
  });
  expect(result).toBe(" ");
});

test("DOMPurify iterative shadow traversal runs nested hooks once in depth-first order", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    const outer = root.attachShadow({ mode: "open" });
    const template = document.createElement("template");
    outer.append(template);
    const host = document.createElement("div");
    template.content.append(host);
    const inner = host.attachShadow({ mode: "open" });
    inner.innerHTML = '<img onerror="unsafe()">';
    const names = new Map([[outer, "outer"], [template.content, "template"], [inner, "inner"]]);
    const events = [];
    DOMPurify.addHook("beforeSanitizeShadowDOM", node => events.push("before:" + names.get(node)));
    DOMPurify.addHook("afterSanitizeShadowDOM", node => events.push("after:" + names.get(node)));
    DOMPurify.sanitize(root, { IN_PLACE: true });
    DOMPurify.removeAllHooks();
    return events;
  });
  expect(result).toEqual(["before:outer", "before:template", "before:inner", "after:inner", "after:template", "after:outer"]);
});

for (const mode of ["in-place", "string", "shadow-template"]) {
  test(`DOMPurify public sanitizer handles deep ${mode} input without recursion`, async ({ page }) => {
    await load(page, "purify.js");
    const result = await page.evaluate((mode) => {
      const depth = 12000;
      if (mode === "string") {
        const clean = DOMPurify.sanitize('<div>' + '<template>'.repeat(depth) + '<img onerror="unsafe()">' + '</template>'.repeat(depth) + '</div>');
        return { unsafe: clean.includes("onerror"), retained: clean.includes("<img") };
      }
      const root = document.createElement("div");
      let container = root;
      for (let i = 0; i < depth; i++) {
        const template = document.createElement("template");
        container.append(template);
        container = template.content;
        if (mode === "shadow-template" && i % 100 === 0) {
          const host = document.createElement("div");
          container.append(host);
          container = host.attachShadow({ mode: "open" });
        }
      }
      const img = document.createElement("img");
      img.setAttribute("onerror", "unsafe()");
      container.append(img);
      DOMPurify.sanitize(root, { IN_PLACE: true });
      return { unsafe: img.hasAttribute("onerror"), retained: img.parentNode === container };
    }, mode);
    expect(result).toEqual({ unsafe: false, retained: true });
  });
}

for (const detached of [false, true]) {
  test(`DOMPurify failed shadow prepass neutralizes ${detached ? "removed" : "attached"} trees`, async ({ page }) => {
    await load(page, "purify.js");
    const result = await page.evaluate((detached) => {
      const root = document.createElement("div");
      const shadow = root.attachShadow({ mode: "open" });
      const section = document.createElement("section");
      section.innerHTML = '<img onerror="window.__shadowEvent = true"><template><img onerror="window.__templateEvent = true"></template>';
      const img = section.firstChild;
      const templateImg = section.lastChild.content.firstChild;
      const nestedHost = document.createElement("div");
      const nestedShadow = nestedHost.attachShadow({ mode: "open" });
      nestedShadow.innerHTML = '<img onerror="window.__nestedEvent = true">';
      const nestedImg = nestedShadow.firstChild;
      section.append(nestedHost);
      const stop = document.createElement("span");
      shadow.append(section, stop);
      DOMPurify.addHook("beforeSanitizeElements", (node) => {
        if (node === (detached ? stop : section)) throw new Error("test shadow abort");
      });
      let message = "";
      try {
        DOMPurify.sanitize(root, { IN_PLACE: true, KEEP_CONTENT: false, FORBID_TAGS: detached ? ["section"] : [] });
      } catch (error) {
        message = error.message;
      } finally {
        DOMPurify.removeAllHooks();
      }
      return { message, detached: section.parentNode === null, handlers: [img, templateImg, nestedImg].map((node) => node.hasAttribute("onerror")) };
    }, detached);
    expect(result.message).toBe("test shadow abort");
    if (detached) expect(result.detached).toBe(true);
    expect(result.handlers).toEqual([false, false, false]);
  });
}

test("DOMPurify rejects nested policy sanitization before configuration mutation", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    let message = "";
    const policy = trustedTypes.createPolicy("nested-policy-regression", {
      createHTML(value) {
        return DOMPurify.sanitize(value, { TRUSTED_TYPES_POLICY: null });
      },
      createScriptURL(value) { return value; },
    });
    try {
      DOMPurify.sanitize("<b>outer</b>", { TRUSTED_TYPES_POLICY: policy, RETURN_TRUSTED_TYPE: true });
    } catch (error) {
      message = error.message;
    }
    const recovered = DOMPurify.sanitize("<b>recovered</b>", { RETURN_TRUSTED_TYPE: true });
    return { message, recovered: String(recovered), trusted: trustedTypes.isHTML(recovered) };
  });
  expect(result.message).toContain("must not call DOMPurify.sanitize");
  expect(result.recovered).toBe("<b>recovered</b>");
  expect(result.trusted).toBe(true);
});

test("D3 quantileIndex preserves empty-input and invalid-input behavior", async ({ page }) => {
  await load(page, "d3.js");
  const result = await page.evaluate(() => {
    const empty = [0, 0.5, 1].map((p) => d3.quantileIndex([], p));
    let nullRejected = false;
    try { d3.quantileIndex(null, 0.5); } catch (error) { nullRejected = error instanceof TypeError; }
    return { empty, nullRejected, nan: d3.quantileIndex(null, NaN) === undefined };
  });
  expect(result).toEqual({ empty: [-1, -1, -1], nullRejected: true, nan: true });
});

test("DOMPurify scopes caller Trusted Types policy to its configuration", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    let calls = 0;
    const policy = trustedTypes.createPolicy("kadupul-regression", {
      createHTML(value) { calls++; return value; },
      createScriptURL(value) { return value; },
    });
    DOMPurify.sanitize("<b>first</b>", { TRUSTED_TYPES_POLICY: policy });
    const beforeDefault = calls;
    const normal = DOMPurify.sanitize("<b>second</b>", { RETURN_TRUSTED_TYPE: true });
    const defaultCalls = calls - beforeDefault;
    DOMPurify.setConfig({ TRUSTED_TYPES_POLICY: policy, RETURN_TRUSTED_TYPE: true });
    const beforePersistent = calls;
    DOMPurify.sanitize("<b>persistent</b>");
    const persistentCalls = calls - beforePersistent;
    DOMPurify.clearConfig();
    const beforeClear = calls;
    DOMPurify.sanitize("<b>cleared</b>", { RETURN_TRUSTED_TYPE: true });
    const clearedCalls = calls - beforeClear;
    const optedOut = DOMPurify.sanitize("<b>plain</b>", { TRUSTED_TYPES_POLICY: null, RETURN_TRUSTED_TYPE: true });
    return { defaultCalls, persistentCalls, clearedCalls, trusted: trustedTypes.isHTML(normal), optedOut: typeof optedOut };
  });
  expect(result.defaultCalls).toBe(0);
  expect(result.persistentCalls).toBeGreaterThan(0);
  expect(result.clearedCalls).toBe(0);
  expect(result.trusted).toBe(true);
  expect(result.optedOut).toBe("string");
});

for (const abort of [false, true]) {
  test(`DOMPurify retains prior outer removals across a nested call (abort=${abort})`, async ({ page }) => {
    await load(page, "purify.js");
    const result = await page.evaluate((abort) => {
      const root = document.createElement("div");
      root.innerHTML = '<section><img onerror="window.__removedEvent=true"></section><b>trigger</b>';
      const removed = root.firstChild;
      const img = removed.firstChild;
      let nested = false;
      DOMPurify.addHook("beforeSanitizeElements", (node) => {
        if (node.nodeName === "B" && !nested) {
          nested = true;
          try {
            DOMPurify.sanitize("<i>nested</i>");
          } catch (error) {
            if (!abort || error.message !== "fixture abort") throw error;
          }
        } else if (node.nodeName === "I" && abort) {
          throw new Error("fixture abort");
        }
      });
      DOMPurify.sanitize(root, { IN_PLACE: true, FORBID_TAGS: ["section"], KEEP_CONTENT: false });
      DOMPurify.removeAllHooks();
      return { nested, detached: removed.parentNode === null, handler: img.hasAttribute("onerror"), tracked: DOMPurify.removed.some((entry) => entry.element === removed) };
    }, abort);
    expect(result).toEqual({ nested: true, detached: true, handler: false, tracked: true });
  });
}

test("D3 quantileIndex handles arrays, sets and single-use iterators", async ({ page }) => {
  await load(page, "d3.js");
  const result = await page.evaluate(() => {
    const values = [30, 10, 20];
    const collect = (factory) => [0, 0.5, 1].map((p) => d3.quantileIndex(factory(), p));
    const array = collect(() => values);
    const set = collect(() => new Set(values));
    const generator = collect(() => (function* () { yield* values; })());
    const objects = (function* () { yield { n: 30 }; yield { n: 10 }; yield { n: 20 }; })();
    const accessor = d3.quantileIndex(objects, 0.5, (value, index, materialized) => {
      if (materialized[index] !== value) throw new Error("Invalid accessor collection");
      return value.n;
    });
    return { array, set, generator, accessor };
  });
  expect(result).toEqual({ array: [1, 2, 0], set: [1, 2, 0], generator: [1, 2, 0], accessor: 2 });
});

test("DOMPurify discarded subtrees respect explicit forbidden attributes", async ({ page }) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    root.innerHTML = '<section><img onerror="window.__discardedEvent = true" title="safe"></section>';
    const removed = root.firstChild;
    const img = removed.firstChild;
    DOMPurify.sanitize(root, {
      IN_PLACE: true,
      KEEP_CONTENT: false,
      FORBID_TAGS: ["section"],
      ADD_ATTR: ["onerror"],
      FORBID_ATTR: ["onerror"],
    });
    return { detached: removed.parentNode === null, handler: img.hasAttribute("onerror"), title: img.getAttribute("title") };
  });
  expect(result).toEqual({ detached: true, handler: false, title: "safe" });
});

for (const hook of ["beforeSanitizeElements", "uponSanitizeElement"]) {
  for (const tree of ["light", "template", "shadow"]) {
    test(`DOMPurify reentrant ${hook} preserves in-place cleanup in ${tree} trees`, async ({ page }) => {
      await load(page, "purify.js");
      const result = await page.evaluate(({ hook, tree }) => {
        const root = document.createElement("div");
        let container = root;
        if (tree === "template") {
          const template = document.createElement("template");
          root.append(template);
          container = template.content;
        } else if (tree === "shadow") {
          container = root.attachShadow({ mode: "open" });
        }
        const removed = document.createElement("section");
        removed.innerHTML = '<img onerror="window.__detachedEvent = true">';
        container.append(removed);
        const img = removed.firstChild;
        let calls = 0;
        DOMPurify.addHook(hook, (node) => {
          if (node === removed) {
            calls++;
            DOMPurify.sanitize("<b>nested safe input</b>");
            node.remove();
          }
        });
        DOMPurify.sanitize(root, { IN_PLACE: true });
        DOMPurify.removeAllHooks();
        return { calls, detached: removed.parentNode === null, handler: img.hasAttribute("onerror") };
      }, { hook, tree });
      expect(result).toEqual({ calls: 1, detached: true, handler: false });
    });
  }
}

test("jQuery UI legacy escapeSelector fallback does not recurse", async ({
  page,
}) => {
  await load(page, "jquery.js");
  await page.evaluate(() => {
    delete $.escapeSelector;
  });
  await load(page, "jquery-ui.js");
  expect(await page.evaluate(() => $.escapeSelector("panel two"))).toBe(
    "panel\\ two",
  );
});

for (const method of ["querySelectorAll", "normalize"]) {
  test(`DOMPurify template scrubbing tolerates a form-associated ${method} clobber`, async ({
    page,
  }) => {
    await page.setContent(
      `<form id="clobber-target"><template><span>{{</span><span>unsafe}}</span></template><img onerror="window.__unsafe = true"></form><input form="clobber-target" name="${method}">`,
    );
    await load(page, "purify.js");
    const result = await page.evaluate((method) => {
      const form = document.getElementById("clobber-target");
      const clobbered = typeof form[method] !== "function";
      DOMPurify.sanitize(form, { IN_PLACE: true, SAFE_FOR_TEMPLATES: true });
      const template = Element.prototype.querySelector.call(form, "template");
      return {
        clobbered,
        handlers: Element.prototype.querySelectorAll.call(form, "[onerror]")
          .length,
        text: template.content.textContent,
      };
    }, method);
    expect(result.clobbered).toBe(true);
    expect(result.handlers).toBe(0);
    expect(result.text).not.toContain("{{");
  });
}

for (const fragment of ["%", "%E0%A4%A", "panel%20two"]) {
  test(`tabs tolerate encoded or malformed fragments: ${fragment}`, async ({
    page,
  }) => {
    await page.setContent(
      '<div id="tabs"><ul><li><a href="#first">First</a></li><li><a href="#panel%20two">Second</a></li><li><a href="#%">Missing malformed panel</a></li></ul><div id="first">One</div><div id="panel two">Two</div></div>',
    );
    await page.evaluate((hash) => {
      location.hash = hash;
    }, fragment);
    await load(page, "jquery.js", "jquery-ui.js");
    const active = await page.evaluate(() => {
      $("#tabs").tabs();
      return $("#tabs").tabs("option", "active");
    });
    expect(active).toBe({ "%": 2, "%E0%A4%A": 0, "panel%20two": 1 }[fragment]);
  });
}

test("tabs retain locality checks without URL or CSS.escape constructors", async ({
  page,
}) => {
  await page.setContent(
    '<div id="tabs"><ul><li><a href="#first">First</a></li></ul><div id="first">One</div></div>',
  );
  await load(page, "jquery.js", "jquery-ui.js");
  const result = await page.evaluate(() => {
    window.URL = undefined;
    window.CSS.escape = undefined;
    $("#tabs").tabs();
    const tabs = $("#tabs").tabs("instance");
    const anchor = document.createElement("a");
    anchor.href = location.href.split("#")[0] + "#first";
    const local = tabs._isLocal(anchor);
    anchor.hostname = "example.invalid";
    return { local, remote: tabs._isLocal(anchor) };
  });
  expect(result).toEqual({ local: true, remote: false });
});

test("DOMPurify removes executable content in nested attached shadow trees", async ({
  page,
}) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const root = document.createElement("div");
    const host = document.createElement("div");
    root.append(host);
    const shadow = host.attachShadow({ mode: "open" });
    const inner = document.createElement("div");
    shadow.append(inner);
    const nested = inner.attachShadow({ mode: "open" });
    nested.innerHTML =
      '<b>safe</b><img src=x onerror="alert(1)"><script>alert(1)</script>';
    DOMPurify.sanitize(root, { IN_PLACE: true });
    return {
      text: nested.querySelector("b").textContent,
      scripts: nested.querySelectorAll("script").length,
      handlers: nested.querySelectorAll("[onerror]").length,
    };
  });
  expect(result).toEqual({ text: "safe", scripts: 0, handlers: 0 });
});

test("DOMPurify keeps harmless markup and strips executable HTML and SVG", async ({
  page,
}) => {
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const html = DOMPurify.sanitize(
      '<b>safe</b><img src=x onerror="alert(1)"><script>alert(1)</script><svg><a xlink:href="javascript:alert(1)">link</a></svg>',
    );
    const root = document.createElement("div");
    root.innerHTML = html;
    return {
      version: DOMPurify.version,
      text: root.querySelector("b").textContent,
      scripts: root.querySelectorAll("script").length,
      handlers: root.querySelectorAll("[onerror]").length,
      unsafeLinks: [...root.querySelectorAll("a")].some((a) =>
        /javascript:/i.test(a.getAttribute("xlink:href") || ""),
      ),
    };
  });
  expect(result).toEqual({
    version: "3.4.15",
    text: "safe",
    scripts: 0,
    handlers: 0,
    unsafeLinks: false,
  });
});

test("jQuery UI preserves the legacy button API and multiselect integration", async ({
  page,
}) => {
  await page.setContent(
    '<button id="button">Settings</button><div id="dialog">Dialog</div><select id="select" multiple><option value="1">One</option><option value="2">Two</option></select>',
  );
  await load(
    page,
    "jquery.js",
    "jquery-ui.js",
    "jquery.multiselect.js",
    "jquery.multiselect.filter.js",
  );
  const result = await page.evaluate(() => {
    $("#button").button({ icons: { primary: "ui-icon-gear" }, text: false });
    $("#dialog").dialog({ autoOpen: false }).dialog("open");
    const open = $("#dialog").dialog("isOpen");
    $("#dialog").dialog("close");
    $("#select").multiselect().multiselectfilter();
    $("#select").multiselect("checkAll");
    return {
      version: $.ui.version,
      compat: $.uiBackCompat,
      icon: $("#button .ui-icon-gear").length,
      open,
      closed: !$("#dialog").dialog("isOpen"),
      selected: $("#select").val(),
      filter: $(".ui-multiselect-filter input").length,
    };
  });
  expect(result).toEqual({
    version: "1.14.2",
    compat: true,
    icon: 1,
    open: true,
    closed: true,
    selected: ["1", "2"],
    filter: 1,
  });
});

test("tablesorter core, widgets and pager work together", async ({ page }) => {
  await page.setContent(
    '<table id="table"><thead><tr><th>Value</th></tr></thead><tbody><tr><td>3</td></tr><tr><td>1</td></tr><tr><td>2</td></tr></tbody></table><div id="pager"><input class="pagedisplay"><select class="pagesize"><option selected>2</option></select></div>',
  );
  await load(
    page,
    "jquery.js",
    "jquery.tablesorter.js",
    "jquery.tablesorter.widgets.js",
    "jquery.tablesorter.pager.js",
  );
  await page.evaluate(() => {
    $("#table")
      .tablesorter({ sortList: [[0, 0]], widgets: ["zebra"] })
      .tablesorterPager({ container: $("#pager"), size: 2 });
  });
  await expect
    .poll(() =>
      page.evaluate(() =>
        $("#table tbody tr:visible")
          .map((_, row) => row.textContent)
          .get(),
      ),
    )
    .toEqual(["1", "2"]);
  expect(await page.evaluate(() => $.tablesorter.version)).toBe("2.32.0");
  expect(await page.locator("#table tbody tr.even").count()).toBeGreaterThan(0);
});

test("D3 retains the browser global, scales and SVG rendering", async ({
  page,
}) => {
  await page.setContent("<svg></svg>");
  await load(page, "d3.js");
  const result = await page.evaluate(() => {
    const scale = d3.scaleLinear().domain([0, 10]).range([0, 100]);
    d3.select("svg")
      .append("circle")
      .attr("cx", scale(5))
      .attr("cy", 10)
      .attr("r", 4);
    return {
      version: d3.version,
      cx: document.querySelector("circle").getAttribute("cx"),
    };
  });
  expect(result).toEqual({ version: "7.9.0", cx: "50" });
});
