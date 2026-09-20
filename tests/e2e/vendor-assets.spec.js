// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const path = require("node:path");
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

test("DOMPurify template scrubbing tolerates a form-associated selector clobber", async ({
  page,
}) => {
  await page.setContent(
    '<form id="clobber-target"><template><span>{{</span><span>unsafe}}</span></template><img onerror="window.__unsafe = true"></form><input form="clobber-target" name="querySelectorAll">',
  );
  await load(page, "purify.js");
  const result = await page.evaluate(() => {
    const form = document.getElementById("clobber-target");
    const clobbered = typeof form.querySelectorAll !== "function";
    DOMPurify.sanitize(form, { IN_PLACE: true, SAFE_FOR_TEMPLATES: true });
    const template = Element.prototype.querySelector.call(form, "template");
    return {
      clobbered,
      handlers: Element.prototype.querySelectorAll.call(form, "[onerror]")
        .length,
      text: template.content.textContent,
    };
  });
  expect(result.clobbered).toBe(true);
  expect(result.handlers).toBe(0);
  expect(result.text).not.toContain("{{");
});

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
