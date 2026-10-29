# Legacy JavaScript libraries for plugins

Four browser libraries in `include/js` are deprecated. Core does not use them. They stay on the page so that plugins written for Cacti keep working.

| Key | Library | Also loads |
| --- | --- | --- |
| `chart` | Chart.js 2.9.4 | |
| `dygraphs` | dygraph-combined.js | |
| `sparkline` | jquery.sparkline.js | |
| `colorpicker` | jquery.colorpicker.js | the theme's `jquery.colorpicker.css` and the colorpicker language file |

## Status by release

In 1.3 the page head still loads all four on every page. In 1.4 it will not. A plugin that uses one of them should declare the need now, so that nothing changes for it when 1.4 ships.

## Declaring a library

Call `html_require_legacy_js()` from the plugin's page code, with one of the keys above:

```php
html_require_legacy_js('sparkline');
```

In 1.3 the call records the request and prints nothing, because the library is already in the head. Repeated calls and unknown keys print nothing. An unknown key is logged at debug verbosity as a warning and does not stop the page.

From 1.4 the head will print only the libraries requested before it runs, at the same position and with the same attributes as today. A request made after the head, for example from a later hook, prints the library's tags at that point, once.

## Partial responses

In-app navigation requests most pages with `header=false`. Those responses carry no head, so the page that is already open never receives one. When a page is answering such a request, `html_require_legacy_js()` prints the library's tags at the call site, inside the partial response, and the browser runs them when it inserts the response. The tags carry the request's script nonce. A request that is not a `header=false` response and has not reached the head yet waits for the head, as described above.

This matters from 1.4 on, and in 1.3 only if `KADUPUL_LEGACY_JS_ON_DEMAND` is defined. In the default 1.3 mode the library is already on the open page.

## Load order

The libraries are loaded with `async`, so a library can finish loading after the plugin's own inline script has run. Do not call the library at the point the script appears. Start the plugin's code from a load handler or from a callback that runs once the library's global (`Chart`, `Dygraph`, `$.fn.sparkline` or `$.fn.colorpicker`) exists. In a partial response the library is fetched after the response is inserted, so the same rule applies.

## Trying the 1.4 behavior

Define `KADUPUL_LEGACY_JS_ON_DEMAND` in `include/config.php` to load only requested libraries on a 1.3 install:

```php
define('KADUPUL_LEGACY_JS_ON_DEMAND', true);
```

The release that changes the default will change `html_legacy_js_global()` in `lib/html.php`, which is the single place that decides between the two modes.
