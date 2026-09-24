/* ══════════════════════════════════════════════════════════════
   DuplicateToolkit — documentation site
   ──────────────────────────────────────────────────────────────
   One document, two halves: a landing page at #/home and a guide
   at #/intro … #/changelog. The router swaps between them; the
   chrome — permalinks, copy buttons, the palette, the theme, the
   progress bar — is declared once here and serves both.

   The landing page's rig runs inside a closure at the bottom, so
   its own show(), wait() and ROWS shadow nothing the router needs.
   ══════════════════════════════════════════════════════════════ */
(function () {
  "use strict";

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var RM = matchMedia("(prefers-reduced-motion: reduce)").matches;

  var homeWrap = $("#home"), docWrap = $(".doc");
  var pages = $$("main > section");
  var byId = {};
  pages.forEach(function (p) { byId[p.id] = p; });
  /* The rail, the pager and the search index all read from this one ordered
     list, so they cannot disagree about what comes after what. #/home is not
     in it: it has no rail entry, no neighbours and no pager. */
  var order = pages.map(function (p) { return p.id; });

  var rail = new Map($$(".toc a:not(.out)").map(function (a) {
    return [a.getAttribute("href").replace(/^#\//, ""), a];
  }));
  var navLinks = $$(".menu a[data-nav]");

  /* ── permalink anchors + copy buttons, once ───────────── */
  /* Assigned up front rather than per page view: the rail, the contents list
     and the search index all read headings, and doing this lazily meant
     whichever ran first decided whether an id existed. */
  pages.forEach(function (page) {
    $$("h2, h3", page).forEach(function (h) {
      if (!h.id) {
        h.id = page.id + "-" + h.textContent.trim().toLowerCase()
          .replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
      }
      // Captured before the glyph is appended, or every label would end in "#".
      h.dataset.label = h.textContent.trim();
      var a = document.createElement("a");
      a.className = "anchor";
      a.href = "#/" + page.id + "#" + h.id;
      a.textContent = "#";
      a.setAttribute("aria-label", "Copy link to " + h.dataset.label);
      a.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var url = location.href.split("#")[0] + a.getAttribute("href");
        if (navigator.clipboard) navigator.clipboard.writeText(url).catch(function () {});
        history.replaceState(null, "", a.getAttribute("href"));
        h.scrollIntoView({ block: "start" });
        a.classList.add("done");
        setTimeout(function () { a.classList.remove("done"); }, 1400);
      });
      h.appendChild(a);
    });

    /* A guide is a place people take things from, so every block gets a
       control. The landing page's code sits inside laid-out figures, where a
       hovering button would be a guide affordance in the wrong room. */
    $$(".codewrap", page).forEach(function (w) {
      var b = document.createElement("button");
      b.type = "button";
      b.textContent = "Copy";
      b.addEventListener("click", function () {
        var pre = $("pre", w);
        if (navigator.clipboard && pre) navigator.clipboard.writeText(pre.textContent).catch(function () {});
        b.textContent = "Copied";
        setTimeout(function () { b.textContent = "Copy"; }, 1400);
      });
      w.appendChild(b);
    });
  });

  /* ── copy-on-click rows ───────────────────────────────── */
  /* The whole row is the control, because what people want is the string and
     aiming at a small icon to get it is a tax. */
  $$("[data-copy]").forEach(function (el) {
    el.addEventListener("click", function () {
      if (navigator.clipboard) navigator.clipboard.writeText(el.dataset.copy).catch(function () {});
      var prev = el.innerHTML;
      el.innerHTML = "<b>&#10003;</b> copied to clipboard";
      setTimeout(function () { el.innerHTML = prev; }, 1500);
    });
  });

  /* ── theme ────────────────────────────────────────────── */
  /* Dark is the default because the plate and the terminal are drawn in it.
     The choice is stamped on the root so it beats the OS in either direction,
     and remembered, because a reader who turned the lights on once should not
     have to do it again on the next page. */
  var root = document.documentElement, tbtn = $("#theme");
  function store(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  function read(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
  function setTheme(t) {
    root.setAttribute("data-theme", t);
    tbtn.textContent = t === "dark" ? "Light" : "Dark";
    store("dt-theme", t);
  }
  setTheme(read("dt-theme") || "dark");
  tbtn.addEventListener("click", function () {
    setTheme(root.getAttribute("data-theme") === "dark" ? "light" : "dark");
  });

  /* ── reading progress ─────────────────────────────────── */
  var bar = $("#progress");
  function progress() {
    var max = document.documentElement.scrollHeight - innerHeight;
    bar.style.width = (max > 0 ? Math.min(scrollY / max, 1) * 100 : 0) + "%";
  }
  addEventListener("scroll", progress, { passive: true });
  addEventListener("resize", progress);

  /* ── on this page ─────────────────────────────────────── */
  var subs = $("#subs"), onthis = $("#onthis"), subLinks = [], headings = [];

  function buildSubs(page) {
    subs.innerHTML = "";
    headings = $$("h2, h3", page);
    subLinks = headings.map(function (h) {
      var a = document.createElement("a");
      a.href = "#/" + page.id + "#" + h.id;
      if (h.tagName === "H3") a.className = "sub";
      a.textContent = h.dataset.label || h.textContent.trim();
      /* Clicking the entry you are already on leaves the hash unchanged, so
         hashchange never fires and the page sits there. Scroll explicitly. */
      a.addEventListener("click", function (e) {
        e.preventDefault();
        history.replaceState(null, "", a.getAttribute("href"));
        h.scrollIntoView({ block: "start" });
      });
      subs.appendChild(a);
      return a;
    });
    // Nothing rather than a box with a heading over one entry.
    onthis.classList.toggle("empty", headings.length < 2);
    spy();
  }

  function spy() {
    if (!headings.length) return;
    var best = 0;
    for (var i = 0; i < headings.length; i++) {
      if (headings[i].getBoundingClientRect().top <= 120) best = i;
    }
    subLinks.forEach(function (a, i) {
      if (i === best) a.setAttribute("aria-current", "true");
      else a.removeAttribute("aria-current");
    });
  }
  addEventListener("scroll", spy, { passive: true });

  /* ── the rail's height ────────────────────────────────── */
  /* A pinned rail taller than the window overhangs the grid and prints over
     the footer. Measured here rather than expressed in CSS, because no CSS
     length can be trusted to match the viewport this page ends up in. */
  var railBox = $(".rail");
  function fitRail() {
    railBox.classList.toggle("is-tall", railBox.scrollHeight > innerHeight - 100);
  }
  addEventListener("resize", fitRail);

  /* ── pager ────────────────────────────────────────────── */
  /* Forward and back, named — so the guide can be read straight through
     without returning to the rail between every page. */
  var pager = $("#pager"), pgPrev = $("#pgprev"), pgNext = $("#pgnext");

  function setPager(id) {
    var i = order.indexOf(id);
    var prev = i > 0 ? order[i - 1] : null;
    var next = i > -1 && i < order.length - 1 ? order[i + 1] : null;
    pgPrev.hidden = !prev;
    pgNext.hidden = !next;
    /* A missing neighbour leaves its half of the row empty rather than letting
       the other card stretch across and read as a different control. */
    pager.classList.toggle("only-next", !prev && !!next);
    if (prev) {
      pgPrev.href = "#/" + prev;
      $("b", pgPrev).textContent = byId[prev].dataset.title;
    }
    if (next) {
      pgNext.href = "#/" + next;
      $("b", pgNext).textContent = byId[next].dataset.title;
    }
  }

  /* ── routing ──────────────────────────────────────────── */
  /* Routes are #/page and #/page#heading. An unknown route is the landing
     page rather than a blank screen. */
  function route() {
    var raw = location.hash.replace(/^#\//, "");
    var deep = raw.split("#")[1];
    var id = raw.split("#")[0];
    var page = byId[id];
    var isHome = !page;

    homeWrap.classList.toggle("hidden", !isHome);
    docWrap.classList.toggle("hidden", isHome);
    document.body.dataset.route = isHome ? "home" : "guide";

    rail.forEach(function (a) { a.removeAttribute("aria-current"); });
    navLinks.forEach(function (a) { a.classList.remove("here"); });

    if (isHome) {
      pages.forEach(function (p) { p.classList.remove("on"); });
      navLinks.forEach(function (a) { if (a.dataset.nav === "home") a.classList.add("here"); });
      document.title = "DuplicateToolkit — read the drawing before you cut";
      if (deep && $("#" + deep, homeWrap)) $("#" + deep, homeWrap).scrollIntoView({ block: "start" });
      else scrollTo(0, 0);
      requestAnimationFrame(progress);
      return;
    }

    pages.forEach(function (p) { p.classList.toggle("on", p === page); });
    var link = rail.get(page.id);
    if (link) link.setAttribute("aria-current", "true");
    /* The menu names five destinations and the guide has nineteen pages, so a
       page without a link of its own marks Guide — the door it came through. */
    var marked = false;
    navLinks.forEach(function (a) { if (a.dataset.nav === page.id) { a.classList.add("here"); marked = true; } });
    if (!marked) navLinks.forEach(function (a) { if (a.dataset.nav === "intro") a.classList.add("here"); });

    document.title = page.dataset.title + " · DuplicateToolkit";
    buildSubs(page);
    setPager(page.id);
    fitRail();
    requestAnimationFrame(progress);

    if (deep && $("#" + deep, page)) $("#" + deep, page).scrollIntoView({ block: "start" });
    else scrollTo(0, 0);
  }
  addEventListener("hashchange", route);

  /* ── search ───────────────────────────────────────────── */
  /* The guide's entries are read off the document, so a page added to it is
     searchable without anybody remembering to list it twice. The landing
     page's stops are listed by hand: its sections are laid out rather than
     titled, and their headings are sentences, not labels. */
  var index = [
    { t: "Home", c: "Page", h: "#/home" },
    { t: "See the command run", c: "Home", h: "#/home#h-plate" },
    { t: "Relation strategies, in brief", c: "Home", h: "#/home#h-strategies" },
    { t: "The result object, in brief", c: "Home", h: "#/home#h-result" },
    { t: "The four commands", c: "Home", h: "#/home#h-commands" },
    { t: "Install it", c: "Home", h: "#/home#h-install" },
    { t: "Support this package", c: "Home", h: "#/home#h-support" }
  ];
  pages.forEach(function (p) {
    index.push({ t: p.dataset.title, c: "Page", h: "#/" + p.id });
    $$("h2, h3", p).forEach(function (h) {
      index.push({ t: h.dataset.label, c: p.dataset.title, h: "#/" + p.id + "#" + h.id });
    });
  });
  index = index.concat([
    { t: "UPGRADE.md on GitHub", c: "GitHub", h: "https://github.com/gabrielesbaiz/duplicate-toolkit/blob/main/UPGRADE.md" },
    { t: "CHANGELOG.md on GitHub", c: "GitHub", h: "https://github.com/gabrielesbaiz/duplicate-toolkit/blob/main/CHANGELOG.md" },
    { t: "Security policy", c: "GitHub", h: "https://github.com/gabrielesbaiz/duplicate-toolkit/blob/main/SECURITY.md" },
    { t: "Contributing", c: "GitHub", h: "https://github.com/gabrielesbaiz/duplicate-toolkit/blob/main/CONTRIBUTING.md" }
  ]);

  var kwrap = $("#kwrap"), kq = $("#kq"), kres = $("#kres"), sel = 0, hits = [];

  function esc(t) {
    return String(t).replace(/[&<>]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c];
    });
  }
  function render() {
    var q = kq.value.trim().toLowerCase();
    hits = (q === "" ? index.slice(0, 10) : index.filter(function (r) {
      return (r.t + " " + r.c).toLowerCase().indexOf(q) > -1;
    }).slice(0, 14));
    sel = 0;
    if (!hits.length) { kres.innerHTML = '<p class="kempty">Nothing here by that name.</p>'; return; }
    kres.innerHTML = hits.map(function (r, i) {
      return '<a href="' + r.h + '" class="' + (i === 0 ? "sel" : "") + '">' +
        '<span class="t">' + esc(r.t) + '</span><span class="c">' + esc(r.c) + "</span></a>";
    }).join("");
    $$("a", kres).forEach(function (a, i) {
      a.addEventListener("click", close);
      a.addEventListener("mouseenter", function () { sel = i; mark(); });
    });
  }
  function mark() {
    var items = $$("a", kres);
    items.forEach(function (a, i) { a.classList.toggle("sel", i === sel); });
    if (items[sel]) items[sel].scrollIntoView({ block: "nearest" });
  }
  function open() { kwrap.classList.add("on"); kq.value = ""; render(); kq.focus(); }
  function close() { kwrap.classList.remove("on"); }

  $("#openk").addEventListener("click", open);
  kwrap.addEventListener("click", function (e) { if (e.target === kwrap) close(); });
  kq.addEventListener("input", render);
  kq.addEventListener("keydown", function (e) {
    if (e.key === "ArrowDown") { e.preventDefault(); sel = Math.min(sel + 1, hits.length - 1); mark(); }
    if (e.key === "ArrowUp") { e.preventDefault(); sel = Math.max(sel - 1, 0); mark(); }
    if (e.key === "Enter" && hits[sel]) {
      var h = hits[sel].h;
      close();
      if (h.charAt(0) === "#") location.hash = h; else location.href = h;
    }
  });
  addEventListener("keydown", function (e) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === "k") {
      e.preventDefault();
      kwrap.classList.contains("on") ? close() : open();
      return;
    }
    if (e.key === "Escape") { close(); return; }
    if (kwrap.classList.contains("on")) return;
    if (e.target.matches && e.target.matches("input, textarea")) return;
    // Left and right walk the guide, the way the pager does.
    var i = order.indexOf(location.hash.replace(/^#\//, "").split("#")[0]);
    if (e.key === "ArrowRight" && i > -1 && i < order.length - 1) location.hash = "#/" + order[i + 1];
    if (e.key === "ArrowLeft" && i > 0) location.hash = "#/" + order[i - 1];
  });
  if (!/Mac|iPhone|iPad/.test(navigator.platform || "")) $("#kbdhint").textContent = "Ctrl K";

  /* ══════════════════════════════════════════════════════════
     THE LANDING PAGE
     Nothing above depends on any of this. It runs in a closure
     because its show() and wait() are a different shape from the
     router's — shadowing them here is the point, rather than
     renaming one of two sets of honest names.
     ══════════════════════════════════════════════════════════ */
  (function () {

  /* ── reveals ──────────────────────────────────────────── */
  var reveals = $$(".reveal");
  if (RM) {
    reveals.forEach(function (el) { el.classList.add("in"); });
  } else {
    var ro = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (e.isIntersecting) { e.target.classList.add("in"); ro.unobserve(e.target); }
      });
    }, { rootMargin: "0px 0px -10% 0px" });
    reveals.forEach(function (el) { ro.observe(el); });
  }

  var out = $("#out");
  if (!out) return;

  /* ── the plate ────────────────────────────────────────── */
  /* Every path length is measured, never written down. Hand-guessed
     dasharrays were the bug that cut half the lines short, and a path edited
     later would have silently gone back to being wrong. */
  $$(".ed").forEach(function (p) {
    var len = Math.ceil(p.getTotalLength()) + 1;
    var authored = p.getAttribute("stroke-dasharray");
    p.dataset.len = len;
    p.dataset.dash = authored || "";
    p.style.strokeDasharray = len + " " + len;
    p.style.strokeDashoffset = len;
  });

  function ink(el) {
    if (!el) return;
    el.style.strokeDashoffset = "0";
    /* A dashed edge has to be drawn solid — the dash pattern is the same
       property the draw uses — so the pattern the legend promises is put
       back once the stroke has arrived. */
    if (el.dataset.dash) {
      setTimeout(function () {
        el.style.transition = "none";
        el.style.strokeDasharray = el.dataset.dash;
        el.style.strokeDashoffset = "0";
      }, RM ? 0 : 520);
    }
  }
  function reveal(id) { var el = document.getElementById(id); if (el) el.classList.add("on"); }

  /* Each relation is its own short stub off the spine. Sharing a segment
     would mean the last edge drawn paints over the ones before it, and the
     strategy colours would stop meaning anything. */
  var ROWS = [
    { id: "versions",     a: "  versions           HasMany         Version       ", c: "cp", b: "copy\n" },
    { id: "descriptions", a: "    └─ descriptions  HasMany         Description   ", c: "cp", b: "copy\n" },
    { id: "setting",      a: "  setting            HasOne          Setting       ", c: "cp", b: "copy\n" },
    { id: "notes",        a: "  notes              MorphMany       Note          ", c: "cp", b: "copy\n" },
    { id: "categories",   a: "  categories         BelongsToMany   Category      ", c: "rf", b: "reference\n" },
    { id: "tags",         a: "  tags               BelongsToMany   Tag           ", c: "rf", b: "reference\n" },
    { id: "auditLogs",    a: "  auditLogs          HasMany         AuditLog      ", c: "sk", b: "skip\n" }
  ];

  var fDepth = $("#f-depth"), fRows = $("#f-rows"), fState = $("#f-state");
  var timer = 0;

  function sp(cls, text) {
    var e = document.createElement("span");
    e.className = cls;
    e.textContent = text;
    out.appendChild(e);
    return e;
  }
  function wait(ms, fn) { timer = setTimeout(fn, RM ? 0 : ms); }

  function typeCmd(text, done) {
    sp("p", "$ ");
    if (RM) { sp("c", text); done(); return; }
    var el = sp("c", ""), i = 0;
    (function tick() {
      el.textContent += text.charAt(i++);
      if (i < text.length) timer = setTimeout(tick, 15);
      else wait(320, done);
    })();
  }

  function header(done) {
    sp("d", "\n  INFO  Product (products) — 7 relation(s).\n\n");
    wait(170, function () {
      sp("h", "  Relation           Type            Related       Strategy\n");
      sp("h", "  ─────────────────────────────────────────────────────\n");
      // The construction lines are set out before anything is inked, once.
      ink($("#c-trunk"));
      ink($("#c-spine"));
      wait(520, done);
    });
  }

  function rows(i) {
    if (i >= ROWS.length) { wait(620, second); return; }
    var r = ROWS[i];
    sp("d", r.a);
    sp(r.c, r.b);
    ink($("#e-" + r.id));
    reveal("n-" + r.id);
    fRows.textContent = String(i + 1);
    wait(340, function () { rows(i + 1); });
  }

  function second() {
    fState.textContent = "drawn";
    sp("d", "\n");
    typeCmd("php artisan duplicate-toolkit:duplicate Product 41 --dry-run\n", function () {
      sp("d", "\n  Plan: 161 record(s) would be created.\n\n");
      wait(260, function () {
        sp("h", "  Model         Records\n");
        sp("h", "  ─────────────────────\n");
        sp("d", "  Product             1\n  Setting             1\n  Version            12\n" +
                "  Note                3\n  Description       148\n\n");
        reveal("d-copy");
        reveal("d-ref");
        fRows.textContent = "161";
        fState.textContent = "planned";
        wait(340, function () {
          sp("ok", "  Nothing written — transaction rolled back.\n\n");
          sp("p", "$ ");
          var cr = document.createElement("span");
          cr.className = "caret";
          out.appendChild(cr);
        });
      });
    });
  }

  function run() {
    typeCmd('php artisan duplicate-toolkit:relations "App\\Models\\Product" --depth=2\n', function () {
      fDepth.textContent = "2";
      fState.textContent = "drawing";
      header(function () { rows(0); });
    });
  }

  /* It starts when the rig is actually on screen — an animation that ran
     while you were three sections below it would only ever be seen finished.
     With motion reduced it goes straight to the finished state. */
  var rig = $("#h-plate"), started = false;
  function boot() { if (!started) { started = true; run(); } }
  if (RM) {
    boot();
  } else {
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { boot(); io.disconnect(); } });
    }, { rootMargin: "0px 0px -20% 0px" });
    io.observe(rig);
  }

  })();

  /* ── boot ─────────────────────────────────────────────── */
  /* Last, so every page is populated before one of them is shown. */
  route();
  fitRail();
  progress();
})();
