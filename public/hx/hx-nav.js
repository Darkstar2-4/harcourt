/* Generated from web/src/hx-nav.ts by "npm run build" in web/. Edit the TypeScript, not this file. */
"use strict";
/* In-page catalog navigation. A click on a catalog link (subcategory, part, search result) fetches only the catalog
 * markup from hxweb (X-HX-Nav) and swaps it into #scscpq_page; the page around it, its scripts and its styles stay.
 * Resting the pointer on a link fetches it ahead of the click. A click still waiting after WAIT_MS greys the current
 * view and runs a thin bar across its top until the new one is in. Back and Forward put back the exact views already
 * shown. Anything unexpected falls back to a normal page load. Links to the catalog home always load the page:
 * its banner rotator starts a timer that must not run twice. */
(function () {
    const found = document.getElementById('scscpq_page');
    if (!found || typeof window.fetch !== 'function' || typeof history.pushState !== 'function' || typeof window.URL !== 'function')
        return;
    const page = found;
    const lang = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    const MAX_VIEWS = 20;
    const PREFETCH_MS = 80;
    const WAIT_MS = 120;
    const GIVE_UP_MS = 10000;
    const views = new Map();
    const order = [];
    const inflight = new Map();
    const flagsOf = new WeakMap();
    // Only the newest navigation (click or Back) may render: an older, slower answer arriving later is ignored.
    let seq = 0;
    const style = document.createElement('style');
    style.textContent =
        '#scscpq_page.hx-wait { position: relative; }' +
            '#scscpq_page.hx-wait > .hx-view { opacity: .45; filter: grayscale(1); transition: opacity .15s, filter .15s; pointer-events: none; }' +
            '#scscpq_page.hx-wait::before { content: ""; position: absolute; z-index: 5; left: 0; right: 0; top: 0; height: 3px;' +
            ' background: linear-gradient(90deg, transparent, #0981be, transparent) no-repeat; background-size: 40% 100%;' +
            ' animation: hx-bar 1s linear infinite; }' +
            '@keyframes hx-bar { from { background-position: -40% 0; } to { background-position: 140% 0; } }';
    document.head.appendChild(style);
    let current = document.createElement('div');
    current.className = 'hx-view';
    while (page.firstChild)
        current.appendChild(page.firstChild);
    page.appendChild(current);
    let here = location.pathname + location.search;
    flagsOf.set(current, flagHrefs());
    remember(here, current);
    document.documentElement.setAttribute('data-hx-url', here);
    const loaded = new Set();
    for (const s of Array.from(document.scripts))
        if (s.src)
            loaded.add(s.src);
    function navigable(a) {
        if (!a || a.target || a.hasAttribute('download'))
            return null;
        const href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href))
            return null;
        return swappable(href);
    }
    function swappable(href) {
        let u;
        try {
            u = new URL(href, location.href);
        }
        catch {
            return null;
        }
        if (u.origin !== location.origin || !/^\/(catalog|catalogue)\/$/.test(u.pathname))
            return null;
        if (/[?&]hxlang=/.test(u.search))
            return null;
        if (!/[?&](action=(subcategory|sku|related)|search_source=)/.test(u.search))
            return null;
        const ulang = u.pathname === '/catalogue/' || /[?&]lang=fr(&|$)/.test(u.search) ? 'fr' : 'en';
        return ulang === lang ? u.pathname + u.search : null;
    }
    /** The one place hxweb's JSON is read. Anything that is not a redirect or a string of markup means "reload". */
    function parseReply(raw) {
        if (typeof raw !== 'object' || raw === null)
            return { kind: 'reload' };
        const r = raw;
        if (typeof r.redirect === 'string' && r.redirect)
            return { kind: 'redirect', url: r.redirect };
        if (r.reload || typeof r.html !== 'string')
            return { kind: 'reload' };
        return { kind: 'view', html: r.html, flags: parseFlags(r.flags) };
    }
    function parseFlags(raw) {
        if (typeof raw !== 'object' || raw === null)
            return undefined;
        const flags = {};
        for (const [code, href] of Object.entries(raw))
            if (typeof href === 'string')
                flags[code] = href;
        return flags;
    }
    function load(url) {
        const known = inflight.get(url);
        if (known && Date.now() - known.at <= 60000)
            return known.p;
        const p = fetch(url, { headers: { 'X-HX-Nav': '1' }, credentials: 'same-origin' })
            .then((r) => {
            if (!r.ok)
                throw new Error('HTTP ' + r.status);
            return r.json();
        })
            .then(parseReply);
        p.catch(() => {
            inflight.delete(url);
        });
        inflight.set(url, { at: Date.now(), p });
        return p;
    }
    function remember(url, view) {
        if (!views.has(url))
            order.push(url);
        views.set(url, view);
        while (order.length > MAX_VIEWS) {
            const oldest = order.shift();
            if (oldest !== undefined)
                views.delete(oldest);
        }
    }
    function flagHrefs() {
        var _a;
        const f = {};
        for (const l of ['en', 'fr']) {
            const href = (_a = document.querySelector('li.wpml-ls-item-' + l + ' > a')) === null || _a === void 0 ? void 0 : _a.getAttribute('href');
            if (typeof href === 'string')
                f[l] = href;
        }
        return f;
    }
    function setFlags(f) {
        if (!f)
            return;
        for (const [l, href] of Object.entries(f)) {
            document.querySelectorAll('li.wpml-ls-item-' + l + ' > a').forEach((a) => a.setAttribute('href', href));
        }
    }
    function show(view, url) {
        if (view !== current) {
            page.replaceChild(view, current);
            current = view;
            setFlags(flagsOf.get(view));
        }
        document.documentElement.setAttribute('data-hx-url', url);
    }
    function runScripts(view) {
        let chain = Promise.resolve();
        for (const old of Array.from(view.querySelectorAll('script'))) {
            chain = chain.then(() => {
                const parent = old.parentNode;
                if (!parent)
                    return;
                const s = document.createElement('script');
                for (const attr of Array.from(old.attributes))
                    s.setAttribute(attr.name, attr.value);
                if (old.src) {
                    if (loaded.has(old.src)) {
                        parent.removeChild(old);
                        return;
                    }
                    loaded.add(old.src);
                    return new Promise((done) => {
                        s.onload = s.onerror = () => done();
                        parent.replaceChild(s, old);
                    });
                }
                s.text = old.text;
                parent.replaceChild(s, old);
            });
        }
        return chain;
    }
    function render(url, reply) {
        if (reply.kind !== 'view')
            throw new Error('reload');
        const view = document.createElement('div');
        view.className = 'hx-view';
        view.innerHTML = reply.html;
        if (reply.flags)
            flagsOf.set(view, reply.flags);
        show(view, url);
        remember(url, view);
        return runScripts(view);
    }
    function idle() {
        page.classList.remove('hx-wait');
        document.documentElement.style.cursor = '';
    }
    function go(url, push) {
        const mine = ++seq;
        // The unit filter may have changed the address without telling us; remember the view under the real one.
        here = location.pathname + location.search;
        remember(here, current);
        const saved = { hx: here, y: window.scrollY };
        history.replaceState(saved, '');
        document.documentElement.style.cursor = 'progress';
        const wait = setTimeout(() => {
            if (mine === seq)
                page.classList.add('hx-wait');
        }, WAIT_MS);
        const giveUp = new Promise((_resolve, reject) => {
            setTimeout(() => reject(new Error('slow')), GIVE_UP_MS);
        });
        Promise.race([load(url), giveUp])
            .then((reply) => {
            inflight.delete(url);
            if (mine !== seq)
                return;
            clearTimeout(wait);
            idle();
            if (reply.kind === 'redirect') {
                location.href = reply.url;
                return;
            }
            if (push) {
                const next = { hx: url, y: 0 };
                history.pushState(next, '', url);
            }
            here = url;
            window.scrollTo(0, 0);
            return render(url, reply);
        })
            .catch(() => {
            if (mine === seq)
                location.href = url;
        })
            .then(() => {
            clearTimeout(wait);
            if (mine === seq)
                idle();
        });
    }
    function linkIn(target) {
        if (!(target instanceof Element))
            return null;
        const a = target.closest('a[href]');
        return a instanceof HTMLAnchorElement ? a : null;
    }
    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey)
            return;
        const a = linkIn(e.target);
        const url = navigable(a);
        if (!url || !a || !page.contains(a))
            return;
        e.preventDefault();
        go(url, true);
    });
    let timer;
    function intent(e) {
        const a = linkIn(e.target);
        const url = navigable(a);
        if (!url || !a || !page.contains(a) || url === here)
            return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            load(url).catch(() => { });
        }, e.type === 'touchstart' ? 0 : PREFETCH_MS);
    }
    document.addEventListener('mouseover', intent);
    document.addEventListener('touchstart', intent, { passive: true });
    document.addEventListener('mouseout', () => clearTimeout(timer));
    // The search box's pick (scscpq_wp.php search_content) comes here; false when the address is not swappable.
    window.hxGo = (href) => {
        const url = swappable(href);
        if (!url)
            return false;
        go(url, true);
        return true;
    };
    function savedY(state) {
        if (typeof state === 'object' && state !== null && 'y' in state && typeof state.y === 'number')
            return state.y || 0;
        return 0;
    }
    // Called by the gate the page registers in <head> (window listeners run in registration order, and the catalog's
    // unit-filter script registers its own popstate handler while the page parses; it reloads the page whenever the
    // restored address has no unit summary). Only entries this script created reach here.
    window.hxPopstate = (e) => {
        seq++;
        idle();
        const url = location.pathname + location.search;
        const v = views.get(url);
        here = url;
        if (v) {
            show(v, url);
            window.scrollTo(0, savedY(e.state));
        }
        else if (swappable(url)) {
            go(url, false);
        }
        else {
            location.reload();
        }
    };
})();
