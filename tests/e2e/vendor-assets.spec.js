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
