/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Fullscreen shim over the native Fullscreen API. It keeps the public surface
 * of screenfull 3.x (enabled, isFullscreen, element, raw, request, exit,
 * toggle, on, off, onchange, onerror, and `false` when unsupported) because
 * third-party plugins call window.screenfull directly. Only the standard,
 * WebKit-prefixed (Safari before 16.4) and Moz-prefixed (Firefox before 64)
 * names are probed.
 */
(function () {
    'use strict';

    const doc = typeof window !== 'undefined' && typeof window.document !== 'undefined' ? window.document : {};
    const isCommonjs = typeof module !== 'undefined' && module.exports;

    const nameMaps = [
        ['requestFullscreen', 'exitFullscreen', 'fullscreenElement', 'fullscreenEnabled', 'fullscreenchange', 'fullscreenerror'],
        ['webkitRequestFullscreen', 'webkitExitFullscreen', 'webkitFullscreenElement', 'webkitFullscreenEnabled', 'webkitfullscreenchange', 'webkitfullscreenerror'],
        ['mozRequestFullScreen', 'mozCancelFullScreen', 'mozFullScreenElement', 'mozFullScreenEnabled', 'mozfullscreenchange', 'mozfullscreenerror'],
    ];

    // raw maps the standard names to whichever set this browser implements.
    const raw = (function () {
        for (const names of nameMaps) {
            if (names[1] in doc) {
                const found = {};
                nameMaps[0].forEach((standard, index) => {
                    found[standard] = names[index];
                });
                return found;
            }
        }
        return false;
    })();

    function publish(value) {
        if (isCommonjs) {
            module.exports = value;
        } else {
            window.screenfull = value;
        }
    }

    if (!raw) {
        publish(false);
        return;
    }

    const eventNames = {
        change: raw.fullscreenchange,
        error: raw.fullscreenerror,
    };

    // 3.x returned undefined, so legacy callers never catch. Marking the promise
    // handled keeps their fire-and-forget calls from logging unhandled
    // rejections; callers that chain still observe the rejection.
    function handled(promise) {
        promise.catch(() => {});
        return promise;
    }

    // Prefixed implementations return undefined, so settle on the next event.
    function settle(start) {
        let result;
        try {
            result = start();
        } catch (error) {
            return handled(Promise.reject(error));
        }
        if (result && typeof result.then === 'function') {
            return handled(result);
        }
        return handled(new Promise((resolve, reject) => {
            const finish = (callback, value) => {
                doc.removeEventListener(eventNames.change, onChange, false);
                doc.removeEventListener(eventNames.error, onError, false);
                callback(value);
            };
            const onChange = () => finish(resolve);
            const onError = () => finish(reject, new Error('Fullscreen request failed'));
            doc.addEventListener(eventNames.change, onChange, false);
            doc.addEventListener(eventNames.error, onError, false);
        }));
    }

    const screenfull = {
        request(elem) {
            elem = elem || doc.documentElement;
            return settle(() => elem[raw.requestFullscreen]());
        },
        exit() {
            if (!doc[raw.fullscreenElement]) {
                return Promise.resolve();
            }
            return settle(() => doc[raw.exitFullscreen]());
        },
        toggle(elem) {
            return this.isFullscreen ? this.exit() : this.request(elem);
        },
        onchange(callback) {
            this.on('change', callback);
        },
        onerror(callback) {
            this.on('error', callback);
        },
        on(event, callback) {
            const name = eventNames[event];
            if (name) {
                doc.addEventListener(name, callback, false);
            }
        },
        off(event, callback) {
            const name = eventNames[event];
            if (name) {
                doc.removeEventListener(name, callback, false);
            }
        },
        raw: raw,
    };

    Object.defineProperties(screenfull, {
        isFullscreen: {
            get() {
                return Boolean(doc[raw.fullscreenElement]);
            },
        },
        element: {
            enumerable: true,
            get() {
                return doc[raw.fullscreenElement];
            },
        },
        enabled: {
            enumerable: true,
            get() {
                return Boolean(doc[raw.fullscreenEnabled]);
            },
        },
    });

    publish(screenfull);
})();
