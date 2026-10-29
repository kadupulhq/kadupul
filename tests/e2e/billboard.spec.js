// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
const path = require("node:path");
const { test, expect } = require("@playwright/test");

// Plugins call bb.generate() on the globals lib/html.php loads, so each case
// mirrors a configuration shipped by intropage, flowview, syslog, servcheck,
// reportit or evidence and uses the same script order.
let pageErrors;

test.beforeEach(async ({ page }) => {
  pageErrors = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));
  await page.goto("/tests/e2e/theme-smoke.html");
  await page.setContent('<div id="chart" style="width:640px"></div><div id="legend"></div>');
  await page.addStyleTag({ url: "/include/themes/classic/billboard.css" });
  for (const file of ["jquery.js", "d3.js", "billboard.js"]) {
    await page.addScriptTag({
      path: path.resolve(__dirname, "../../include/js", file),
    });
  }
});

test.afterEach(() => {
  expect(pageErrors).toEqual([]);
});

async function generate(page, config) {
  await page.addScriptTag({
    content: `window.chart = bb.generate(Object.assign({ bindto: "#chart" }, ${config}));`,
  });
}

const count = (page, selector) =>
  page.evaluate((s) => document.querySelectorAll(`#chart ${s}`).length, selector);

const times = '"2026-10-28 10:00:00", "2026-10-28 10:05:00", "2026-10-28 10:10:00", "2026-10-28 10:15:00"';

test("exposes the UMD global with every API the v4 ESM build made optional", async ({ page }) => {
  await generate(page, '{ data: { columns: [["a", 1, 2, 3]] } }');
  const api = await page.evaluate(() => ({
    version: bb.version,
    d3: d3.version,
    methods: ["export", "flow", "xgrids", "ygrids", "regions", "category", "categories", "resize", "destroy", "load", "tooltip"]
      .filter((name) => typeof chart[name] !== (name === "tooltip" ? "object" : "function")),
  }));
  expect(api).toEqual({ version: "4.1.3", d3: "7.9.0", methods: [] });

  await page.evaluate(() => {
    chart.xgrids([{ value: 1, text: "x" }]);
    chart.ygrids([{ value: 2, text: "y" }]);
    chart.regions([{ axis: "x", start: 0, end: 1 }]);
  });
  await expect.poll(() => count(page, ".bb-xgrid-line")).toBe(1);
  await expect.poll(() => count(page, ".bb-ygrid-line")).toBe(1);
  await expect.poll(() => count(page, ".bb-region")).toBe(1);

  const exported = await page.evaluate(() => new Promise((resolve) => chart.export({ mimeType: "image/png" }, resolve)));
  expect(exported).toMatch(/^data:image\/png;base64,/);
  await page.evaluate(() => chart.flow({ columns: [["a", 4]], duration: 0 }));
  await expect.poll(() => page.evaluate(() => chart.data.values("a"))).toEqual([2, 3, 4]);
});

test("intropage timeseries line chart with a secondary axis and drag zoom", async ({ page }) => {
  await generate(page, `{
    size: { height: 100, width: 150 },
    point: { r: 1.5 },
    zoom: { enabled: "true", type: "drag" },
    data: {
      type: "line", x: "x", Format: "%Y-%m-%d %H:%M:%S",
      colors: { "Hosts DOWN": "#ff0000" },
      columns: [["x", ${times}], ["Hosts UP", 3, 4, 5, 6], ["Hosts DOWN", 1, 0, 2, 1]],
      axes: { "Hosts UP": "y", "Hosts DOWN": "y2" }
    },
    axis: {
      x: { type: "timeseries", tick: { format: "%H:%M", culling: { max: 6 } } },
      y: { tick: { culling: { max: 8 } }, label: { text: "up" }, show: true },
      y2: { tick: { culling: { max: 8 } }, label: { text: "down" }, show: true }
    }
  }`);
  await expect.poll(() => count(page, "path.bb-line")).toBe(2);
  expect(await count(page, ".bb-axis-y2")).toBe(1);
  expect(await page.evaluate(() => chart.data.colors()["Hosts DOWN"])).toBe("#ff0000");
  expect(await page.locator("#chart .bb-axis-x .tick text").first().textContent()).toBe("10:00");
  const before = await page.evaluate(() => chart.zoom().map(Number));
  const box = await page.locator("#chart .bb-event-rect").boundingBox();
  await page.mouse.move(box.x + box.width * 0.25, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width * 0.75, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();
  await expect.poll(() => page.evaluate(() => chart.zoom().map(Number))).not.toEqual(before);
});

test("intropage stacked timeseries bar chart", async ({ page }) => {
  await generate(page, `{
    size: { height: 100, width: 150 },
    zoom: { enabled: "true", type: "drag" },
    data: {
      type: "bar", x: "x", Format: "%Y-%m-%d %H:%M:%S",
      columns: [["x", ${times}], ["ok", 1, 2, 3, 4], ["failed", 0, 1, 0, 1]],
      groups: [["ok", "failed"]], axes: { ok: "y", failed: "y" }
    },
    bar: { width: 7 },
    axis: { x: { type: "timeseries", tick: { format: "%H:%M", culling: { max: 6 } } }, y: { label: { text: "polls" }, show: true } }
  }`);
  await expect.poll(() => count(page, "path.bb-bar")).toBe(8);
});

test("intropage and flowview pie charts format labels", async ({ page }) => {
  await generate(page, `{
    padding: { top: 40, right: 100, bottom: 40, left: 100 },
    size: { height: 300, width: 600 },
    data: { type: "pie", columns: [["tcp", 70], ["udp", 30]], labels: { colors: "#fff" } },
    pie: { label: { format: function(value, ratio) { return value + "\\n" + (ratio * 100) + " %"; } } },
    legend: { position: "right" }
  }`);
  await expect.poll(() => count(page, "path.bb-arc")).toBe(2);
  expect(await count(page, ".bb-legend-item")).toBe(2);
  await expect.poll(() => page.locator("#chart .bb-chart-arc text").first().locator("tspan").allTextContents()).toEqual(["70", "70 %"]);
});

for (const [name, extra] of [
  ["intropage/evidence treemap", 'treemap: { label: { threshold: 0.03, show: true } }'],
  ["reportit treemap", 'tile: "dice", treemap: { label: { threshold: 0.03, show: false } }, interaction: { inputType: { mouse: true, touch: false } }'],
  ["flowview treemap", 'treemap: { tile: "binary", label: { threshold: 0.03, format: function(value, ratio, id) { return id + "\\n" + value + " flows"; } } }'],
]) {
  test(`${name} renders tiles`, async ({ page }) => {
    await generate(page, `{
      size: { height: 350, width: 600 },
      data: { type: "treemap", columns: [["web", 50], ["db", 30], ["dns", 20]], labels: { position: { x: 0, y: 15 }, colors: "#fff" } },
      ${extra}
    }`);
    await expect.poll(() => count(page, ".bb-treemap g, .bb-chart-treemaps g")).toBeGreaterThanOrEqual(3);
    await expect.poll(() => count(page, "rect")).toBeGreaterThanOrEqual(3);
  });
}

test("flowview JSON category bar chart with per-index colours and resize", async ({ page }) => {
  await page.evaluate(() => { window.pattern = ["#111111", "#222222", "#333333"]; });
  await generate(page, `{
    title: { text: "Top talkers" },
    size: { height: 300, width: 600 },
    onresize: function() { this.resize({ width: 500 }); },
    data: {
      type: "bar", mimeType: "json",
      json: [{ name: "10.0.0.1", value: 3000, index: 0 }, { name: "10.0.0.2", value: 2000, index: 1 }],
      keys: { x: "name", value: ["value"], index: ["index"] },
      color: function(color, d) { return pattern[d.index]; }
    },
    legend: { hide: true },
    axis: {
      x: { type: "category", tick: { rotate: 15, multiline: false }, height: 80 },
      y: { label: "flows", position: "outer-middle", tick: { format: function(d) { return (d / 1000) + "K"; } } }
    }
  }`);
  await expect.poll(() => count(page, "path.bb-bar")).toBe(2);
  expect(await page.locator("#chart .bb-title").textContent()).toBe("Top talkers");
  expect(await page.locator("#chart .bb-axis-x .tick text").allTextContents()).toEqual(["10.0.0.1", "10.0.0.2"]);
  expect(await page.locator("#chart path.bb-bar").evaluateAll((bars) => bars.map((bar) => bar.style.fill || bar.getAttribute("fill")))).toEqual(["rgb(17, 17, 17)", "rgb(34, 34, 34)"]);
  for (const item of await page.locator("#chart .bb-legend-item").all()) await expect(item).toBeHidden();
  await page.setViewportSize({ width: 1000, height: 900 });
  await expect.poll(() => page.evaluate(() => document.querySelector("#chart svg").getAttribute("width"))).toBe("500");
});

test("syslog donut and category series charts survive destroy and regenerate", async ({ page }) => {
  await generate(page, `{
    data: { columns: [["kern", 4], ["auth", 6]], type: "donut" },
    donut: { title: "Facilities", label: { show: false } },
    legend: { position: "right" }, padding: { right: 16 }
  }`);
  await expect.poll(() => count(page, "path.bb-arc")).toBe(2);
  expect(await page.locator("#chart .bb-chart-arcs-title").textContent()).toBe("Facilities");
  for (const type of ["line", "area", "bar"]) {
    await page.evaluate(() => chart.destroy());
    await generate(page, `{
      data: { x: "x", columns: [["x", "10:00", "11:00", "12:00"], ["records", 1, 5, 2]], names: { records: "Errors" }, type: "${type}" },
      legend: { show: false },
      axis: { x: { type: "category", tick: { culling: { max: 8 }, multiline: false } }, y: { tick: { culling: { max: 5 } }, min: 0, padding: { bottom: 0 } } },
      padding: { right: 16 }
    }`);
    await expect.poll(() => count(page, type === "bar" ? "path.bb-bar" : "path.bb-line")).toBe(type === "bar" ? 3 : 1);
  }
  expect(await count(page, "path.bb-area")).toBe(0);
});

test("servcheck timeseries area chart parses its x format", async ({ page }) => {
  await generate(page, `{
    size: { height: 300, width: 600 },
    point: { r: 1.5 },
    data: { type: "area", x: "x", xFormat: "%Y-%m-%d %H:%M:%S", axes: [], columns: [["x", ${times}], ["Duration", 0.1, 0.2, 0.15, 0.3]] },
    axis: { x: { type: "timeseries", tick: { format: "%m-%d %H:%M", culling: { max: 6 } } }, y: { tick: { label: { text: "Response in ms" }, show: true } } }
  }`);
  await expect.poll(() => count(page, "path.bb-area")).toBe(1);
  expect(await page.locator("#chart .bb-axis-x .tick text").first().textContent()).toBe("10-28 10:00");
});

test("Midwinter repaints the 4.x tooltip with its theme variables", async ({ page }) => {
  await page.addStyleTag({ content: ":root { --background-lowlight: rgb(1, 2, 3); --background-superlowlight: rgb(4, 5, 6); --text-color-light: rgb(7, 8, 9); }" });
  await page.addStyleTag({ url: "/include/themes/midwinter/billboard.css" });
  await page.addStyleTag({ url: "/include/themes/midwinter/billboard.midwinter.css" });
  await generate(page, '{ data: { columns: [["a", 1, 2]] } }');
  await page.evaluate(() => chart.tooltip.show({ index: 0 }));
  await expect.poll(() => page.locator("#chart .bb-tooltip th").count()).toBe(1);
  const style = await page.evaluate(() => {
    const css = (selector) => getComputedStyle(document.querySelector(`#chart ${selector}`));
    return { table: css(".bb-tooltip").backgroundColor, th: css(".bb-tooltip th").backgroundColor, color: css(".bb-tooltip th").color, radius: css(".bb-tooltip").borderTopLeftRadius };
  });
  expect(style).toEqual({ table: "rgb(1, 2, 3)", th: "rgb(4, 5, 6)", color: "rgb(7, 8, 9)", radius: "5px" });
});

// Plugin data reaches labels, names and formatter output unescaped.
const hostile = [
  '<img src=x onerror="window.__xss=1">',
  "<script>window.__xss=2</script>",
  '<a href="javascript:window.__xss=3">a</a>',
  '<svg><a xlink:href="javascript:window.__xss=4"><text>t</text></a></svg>',
  "<IMG SRC=x OnError=window.__xss=5>",
  '<a href=" &#x6A;avascript:window.__xss=6">b</a>',
  '<a href="javascript&#58window.__xss=7">c</a>',
  '<svg><a href="jav&#x09;ascript:window.__xss=8"><text>u</text></a></svg>',
  '<div style="background:url(javascript:window.__xss=9)">d</div>',
];

async function executableMarkup(page) {
  return page.evaluate(() => {
    const found = [];
    for (const node of document.querySelectorAll("#chart *, #legend *")) {
      const name = node.localName;
      if (name === "script" || name === "iframe" || name === "object" || name === "embed") found.push(`<${name}>`);
      for (const attribute of node.attributes) {
        const attr = attribute.name.toLowerCase();
        if (attr.startsWith("on")) found.push(`${name}[${attr}]`);
        if (attr === "href" || attr === "xlink:href" || attr === "src" || attr === "action") {
          let protocol = "";
          try { protocol = new URL(attribute.value, location.href).protocol; } catch { protocol = ""; }
          if (protocol !== "http:" && protocol !== "https:" && protocol !== "mailto:") found.push(`${name}[${attr}=${attribute.value}]`);
        }
        if (attr === "style" && /url\s*\(\s*['"]?\s*javascript/i.test(attribute.value)) found.push(`${name}[style]`);
      }
    }
    return found;
  });
}

test("hostile series names stay inert in SVG text, legends and tooltips", async ({ page }) => {
  await generate(page, `{
    title: { text: ${JSON.stringify(hostile.join(" "))} },
    data: {
      x: "x",
      columns: [["x", ${JSON.stringify(hostile.join("|"))}, "b"]].concat(${JSON.stringify(hostile)}.map(function(n, i) { return [n, i, i + 1]; })),
      labels: { format: function(v, id) { return id; } }
    },
    axis: { x: { type: "category", tick: { format: function(i) { return ${JSON.stringify(hostile[0])}; } } }, y: { label: ${JSON.stringify(hostile[2])} } },
    tooltip: { format: { title: function() { return ${JSON.stringify(hostile[3])}; }, name: function(n) { return n; }, value: function(v, r, id) { return "value-" + v + " " + id; } } }
  }`);
  await page.evaluate(() => chart.tooltip.show({ index: 0 }));
  await expect.poll(() => page.locator("#chart .bb-tooltip").count()).toBe(1);
  // Text sinks keep the literal string instead of parsing it.
  expect(await page.locator("#chart .bb-title").textContent()).toBe(hostile.join(" "));
  expect(await page.locator("#chart .bb-legend-item text").allTextContents()).toEqual(hostile);
  // An over-eager sanitizer that empties the tooltip must fail, not pass.
  expect(await page.locator("#chart .bb-tooltip").textContent()).toContain("value-0");
  expect(await executableMarkup(page)).toEqual([]);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => window.__xss)).toBeUndefined();
});

for (const [index, payload] of hostile.entries()) {
  test(`tooltip contents and legend templates neutralize payload ${index + 1}`, async ({ page }) => {
    await generate(page, `{
      data: { columns: [[${JSON.stringify(payload)}, 1, 2]] },
      legend: { contents: { bindto: "#legend", template: "<span>{=TITLE}</span>" } },
      tooltip: { contents: function() { return ${JSON.stringify(`<table class="bb-tooltip"><tr><td>${payload}</td></tr></table>`)}; } }
    }`);
    await page.evaluate(() => chart.tooltip.show({ index: 0 }));
    await expect.poll(() => page.locator("#chart .bb-tooltip").count()).toBe(1);
    await expect(page.locator("#legend span")).toHaveCount(1);
    expect(await executableMarkup(page)).toEqual([]);
    await page.waitForTimeout(100);
    expect(await page.evaluate(() => window.__xss)).toBeUndefined();
  });
}
