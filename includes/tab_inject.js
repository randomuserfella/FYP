/**
 * tab_inject.js — per-tab session isolation
 *
 * WHAT WAS BROKEN:
 * The old version only redirected when ?tab= was completely absent.
 * On F5, Chrome keeps the full URL so ?tab= stays — but if two tabs
 * have different sessionStorage IDs the URL's ?tab= may belong to the
 * OTHER tab's session. Nothing caught this mismatch.
 *
 * THE FIX:
 * Compare URL's ?tab= against sessionStorage on EVERY page load.
 * If they don't match exactly → replace the URL with the correct one.
 * sessionStorage is always tab-local and survives F5, so it's the
 * ground truth.
 *
 * Also patches fetch() and XHR so chat_api.php and all AJAX calls
 * automatically carry ?tab= without manual changes to each call.
 */
(function () {
    var TAB_KEY = 'pt_tab_id';

    // ── 1. Stable tab-scoped ID ───────────────────────────────────────────
    var tabId = sessionStorage.getItem(TAB_KEY);
    if (!tabId || !/^[a-z0-9]{16}$/.test(tabId)) {
        tabId = Math.random().toString(36).slice(2, 10) +
                Math.random().toString(36).slice(2, 10);
        sessionStorage.setItem(TAB_KEY, tabId);
    }

    // ── 2. Every page load: verify URL matches sessionStorage ─────────────
    var params = new URLSearchParams(window.location.search);
    if (params.get('tab') !== tabId) {
        params.set('tab', tabId);
        var corrected = window.location.pathname + '?' + params.toString()
                        + window.location.hash;
        window.location.replace(corrected);
        return; // page is reloading
    }

    window.__ptTabId = tabId;

    // ── 3. Patch fetch() — covers chat_api, push, calendar, all AJAX ─────
    var _fetch = window.fetch;
    window.fetch = function (input, init) {
        try {
            if (typeof input === 'string' &&
                !/^https?:\/\//.test(input) &&
                input.indexOf('tab=') === -1) {
                input += (input.indexOf('?') === -1 ? '?' : '&') + 'tab=' + tabId;
            }
        } catch (e) {}
        return _fetch.call(this, input, init);
    };

    // ── 4. Patch XHR — covers any legacy $.ajax / XMLHttpRequest calls ────
    var _open = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        try {
            if (typeof url === 'string' &&
                !/^https?:\/\//.test(url) &&
                url.indexOf('tab=') === -1) {
                url += (url.indexOf('?') === -1 ? '?' : '&') + 'tab=' + tabId;
            }
        } catch (e) {}
        return _open.apply(this, [method, url].concat(
            Array.prototype.slice.call(arguments, 2)));
    };

    // ── 5. Stamp <a> links ────────────────────────────────────────────────
    function injectLinks() {
        document.querySelectorAll('a[href]').forEach(function (a) {
            var href = a.getAttribute('href');
            if (!href || href.charAt(0) === '#') return;
            if (/^(mailto|javascript|https?):/.test(href)) return;
            if (href.indexOf('tab=') !== -1) return;
            a.setAttribute('href', href +
                (href.indexOf('?') === -1 ? '?' : '&') + 'tab=' + tabId);
        });
    }

    // ── 6. Stamp <form> hidden inputs ─────────────────────────────────────
    function injectForms() {
        document.querySelectorAll('form').forEach(function (f) {
            if (f.querySelector('input[name="tab"]')) return;
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'tab'; inp.value = tabId;
            f.appendChild(inp);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        injectLinks();
        injectForms();
    });

    new MutationObserver(function () {
        injectLinks();
        injectForms();
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
