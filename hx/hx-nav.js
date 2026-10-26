/* In-page catalog navigation. A click on a catalog link (subcategory, part, search result) fetches only the catalog
 * markup from hxweb (X-HX-Nav) and swaps it into #scscpq_page; the page around it, its scripts and its styles stay.
 * Resting the pointer on a link fetches it ahead of the click. A click still waiting after WAIT_MS greys the current
 * view and runs a thin bar across its top until the new one is in. Back and Forward put back the exact views already
 * shown. Anything unexpected falls back to a normal page load. Links to the catalog home always load the page:
 * its banner rotator starts a timer that must not run twice. */
(function () {
  var page = document.getElementById('scscpq_page');
  if (!page || !window.fetch || !history.pushState || !window.URL) return;
  var lang = (document.documentElement.lang || 'en').slice(0, 2).toLowerCase();
  var views = {}, order = [], inflight = {}, MAX_VIEWS = 20, PREFETCH_MS = 80, WAIT_MS = 120, GIVE_UP_MS = 10000;
  // Only the newest navigation (click or Back) may render: an older, slower answer arriving later is ignored.
  var seq = 0;
  var style = document.createElement('style');
  style.textContent = '#scscpq_page.hx-wait { position: relative; }' +
    '#scscpq_page.hx-wait > .hx-view { opacity: .45; filter: grayscale(1); transition: opacity .15s, filter .15s; pointer-events: none; }' +
    '#scscpq_page.hx-wait::before { content: ""; position: absolute; z-index: 5; left: 0; right: 0; top: 0; height: 3px;' +
    ' background: linear-gradient(90deg, transparent, #0981be, transparent) no-repeat; background-size: 40% 100%;' +
    ' animation: hx-bar 1s linear infinite; }' +
    '@keyframes hx-bar { from { background-position: -40% 0; } to { background-position: 140% 0; } }';
  document.head.appendChild(style);

  var current = document.createElement('div');
  current.className = 'hx-view';
  while (page.firstChild) current.appendChild(page.firstChild);
  page.appendChild(current);
  var here = location.pathname + location.search;
  current.hxFlags = flagHrefs();
  remember(here, current);
  document.documentElement.setAttribute('data-hx-url', here);

  var loaded = {};
  Array.prototype.forEach.call(document.scripts, function (s) { if (s.src) loaded[s.src] = true; });

  function navigable(a) {
    if (!a || a.target || a.hasAttribute('download')) return null;
    var href = a.getAttribute('href');
    if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return null;
    return swappable(href);
  }

  function swappable(href) {
    var u;
    try { u = new URL(href, location.href); } catch (e) { return null; }
    if (u.origin !== location.origin || !/^\/(catalog|catalogue)\/$/.test(u.pathname)) return null;
    if (/[?&]hxlang=/.test(u.search)) return null;
    if (!/[?&](action=(subcategory|sku|related)|search_source=)/.test(u.search)) return null;
    var ulang = u.pathname === '/catalogue/' || /[?&]lang=fr(&|$)/.test(u.search) ? 'fr' : 'en';
    return ulang === lang ? u.pathname + u.search : null;
  }

  function load(url) {
    if (!inflight[url] || Date.now() - inflight[url].at > 60000) {
      var p = fetch(url, { headers: { 'X-HX-Nav': '1' }, credentials: 'same-origin' }).then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      });
      p.catch(function () { delete inflight[url]; });
      inflight[url] = { at: Date.now(), p: p };
    }
    return inflight[url].p;
  }

  function remember(url, view) {
    if (!views[url]) order.push(url);
    views[url] = view;
    while (order.length > MAX_VIEWS) delete views[order.shift()];
  }

  function flagHrefs() {
    var f = {};
    ['en', 'fr'].forEach(function (l) {
      var a = document.querySelector('li.wpml-ls-item-' + l + ' > a');
      if (a) f[l] = a.getAttribute('href');
    });
    return f;
  }

  function setFlags(f) {
    if (!f) return;
    Object.keys(f).forEach(function (l) {
      document.querySelectorAll('li.wpml-ls-item-' + l + ' > a').forEach(function (a) { a.setAttribute('href', f[l]); });
    });
  }

  function show(view, url) {
    if (view !== current) {
      page.replaceChild(view, current);
      current = view;
      setFlags(view.hxFlags);
    }
    document.documentElement.setAttribute('data-hx-url', url);
  }

  function runScripts(view) {
    var scripts = Array.prototype.slice.call(view.querySelectorAll('script'));
    var chain = Promise.resolve();
    scripts.forEach(function (old) {
      chain = chain.then(function () {
        var s = document.createElement('script');
        for (var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
        if (old.src) {
          if (loaded[old.src]) { old.parentNode.removeChild(old); return; }
          loaded[old.src] = true;
          return new Promise(function (done) {
            s.onload = s.onerror = done;
            old.parentNode.replaceChild(s, old);
          });
        }
        s.text = old.text;
        old.parentNode.replaceChild(s, old);
      });
    });
    return chain;
  }

  function render(url, data) {
    if (data.reload || typeof data.html !== 'string') throw new Error('reload');
    var view = document.createElement('div');
    view.className = 'hx-view';
    view.innerHTML = data.html;
    view.hxFlags = data.flags;
    show(view, url);
    remember(url, view);
    return runScripts(view);
  }

  function idle() {
    page.classList.remove('hx-wait');
    document.documentElement.style.cursor = '';
  }

  function go(url, push) {
    var mine = ++seq;
    // The unit filter may have changed the address without telling us; remember the view under the real one.
    here = location.pathname + location.search;
    remember(here, current);
    history.replaceState({ hx: here, y: window.scrollY }, '');
    document.documentElement.style.cursor = 'progress';
    var wait = setTimeout(function () { if (mine === seq) page.classList.add('hx-wait'); }, WAIT_MS);
    var giveUp = new Promise(function (resolve, reject) { setTimeout(function () { reject(new Error('slow')); }, GIVE_UP_MS); });
    Promise.race([load(url), giveUp]).then(function (data) {
      delete inflight[url];
      if (mine !== seq) return;
      clearTimeout(wait);
      idle();
      if (data.redirect) { location.href = data.redirect; return; }
      if (push) history.pushState({ hx: url, y: 0 }, '', url);
      here = url;
      window.scrollTo(0, 0);
      return render(url, data);
    }).catch(function () {
      if (mine === seq) location.href = url;
    }).then(function () {
      clearTimeout(wait);
      if (mine === seq) idle();
    });
  }

  document.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]');
    var url = navigable(a);
    if (!url || !page.contains(a)) return;
    e.preventDefault();
    go(url, true);
  });

  var timer = null;
  function intent(e) {
    var a = e.target.closest && e.target.closest('a[href]');
    var url = navigable(a);
    if (!url || !page.contains(a) || url === here) return;
    clearTimeout(timer);
    timer = setTimeout(function () { load(url).catch(function () {}); }, e.type === 'touchstart' ? 0 : PREFETCH_MS);
  }
  document.addEventListener('mouseover', intent);
  document.addEventListener('touchstart', intent, { passive: true });
  document.addEventListener('mouseout', function () { clearTimeout(timer); });

  // The search box's pick (scscpq_wp.php search_content) comes here; false when the address is not swappable.
  window.hxGo = function (href) {
    var url = swappable(href);
    if (!url) return false;
    go(url, true);
    return true;
  };

  // Called by the gate the page registers in <head> (window listeners run in registration order, and the catalog's
  // unit-filter script registers its own popstate handler while the page parses; it reloads the page whenever the
  // restored address has no unit summary). Only entries this script created reach here.
  window.hxPopstate = function (e) {
    seq++;
    idle();
    var url = location.pathname + location.search;
    var v = views[url];
    here = url;
    if (v) {
      show(v, url);
      window.scrollTo(0, e.state.y || 0);
    } else if (swappable(url)) {
      go(url, false);
    } else {
      location.reload();
    }
  };
})();
