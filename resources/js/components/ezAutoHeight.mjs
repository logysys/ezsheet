/* release-1013 item 10: autoHeight / frameless support for EzSheetPreview.
   Plain ESM with no imports so the node tests can load it directly.

   wallAutoHeightReporter runs INSIDE the wall srcdoc (EzSheetPreview injects it with
   Function.prototype.toString, so it must stay self-contained: no outer references).
   It measures the wall document's content height (in-flow content only, never the
   viewport, so growing the iframe can't grow the measurement) and posts
   {__ezWallDocH: h} to the parent. Reports are rAF-batched and changes under 2px are
   dropped. It re-measures on body/masonry/zone resize, DOM changes, images/iframes
   loading, fonts and viewport (window) resize. */
export function wallAutoHeightReporter(win) {
  var doc = win.document, last = -1, queued = false;
  var raf = win.requestAnimationFrame ? win.requestAnimationFrame.bind(win) : function (f) { return win.setTimeout(f, 16); };
  function measure() {
    var b = doc.body; if (!b) return 0;
    var cs = win.getComputedStyle(b), sy = win.scrollY || 0, bottom = 0;
    for (var i = 0; i < b.children.length; i++) {
      var el = b.children[i], s = win.getComputedStyle(el);
      if (s.position === 'fixed' || s.display === 'none' || el.tagName === 'SCRIPT' || el.tagName === 'STYLE') continue;
      var r = el.getBoundingClientRect();
      var mb = parseFloat(s.marginBottom) || 0;
      if (r.bottom + mb + sy > bottom) bottom = r.bottom + mb + sy;
    }
    var bt = b.getBoundingClientRect().top + sy;
    if (bottom < bt) bottom = bt;
    return Math.ceil(bottom + (parseFloat(cs.paddingBottom) || 0) + (parseFloat(cs.borderBottomWidth) || 0) + (parseFloat(cs.marginBottom) || 0));
  }
  function send() {
    queued = false;
    var h = measure();
    if (h <= 0 || (last >= 0 && Math.abs(h - last) < 2)) return;
    last = h;
    try { win.parent.postMessage({ __ezWallDocH: h }, '*'); } catch (e) {}
  }
  function schedule() { if (!queued) { queued = true; raf(send); } }
  win.addEventListener('load', schedule);
  win.addEventListener('resize', schedule);
  doc.addEventListener('load', schedule, true); /* images / card iframes inside the wall */
  if (win.ResizeObserver) {
    var ro = new win.ResizeObserver(schedule);
    var watch = function () { if (doc.body) { ro.observe(doc.body); ['wall', 'zoneA', 'zoneC'].forEach(function (id) { var e = doc.getElementById(id); if (e) ro.observe(e); }); } };
    watch();
    if (win.MutationObserver && doc.body) new win.MutationObserver(function () { watch(); schedule(); }).observe(doc.body, { childList: true });
  }
  if (doc.fonts && doc.fonts.ready) doc.fonts.ready.then(schedule, function () {});
  schedule();
  win.setTimeout(schedule, 300); win.setTimeout(schedule, 1500);
  return { measure: measure, send: send, schedule: schedule };
}

/* Parent side. push(h) is called for every {__ezWallDocH} message. Heights are batched per
   animation frame (last value wins), clamped to minH, changes under 2px are ignored, and a
   frame that keeps flipping between two heights is pinned to the larger one (grow only),
   so resize -> report -> resize can't loop. apply(h) writes the iframe height directly
   (no React state, so no re-render and no payload echo back into the wall). */
export function createWallHeightDamper(apply, opts) {
  opts = opts || {};
  var minH = opts.minH || 240, maxH = opts.maxH || 200000;
  var raf = opts.raf || (typeof requestAnimationFrame === 'function' ? requestAnimationFrame : function (f) { return setTimeout(f, 16); });
  var last = -1, prev = -1, flips = 0, pin = 0, pend = -1, queued = false;
  function flush() {
    queued = false;
    var h = pend; pend = -1;
    if (!(h > 0)) return;
    h = Math.min(maxH, Math.max(minH, Math.ceil(h)));
    if (pin) { if (h <= pin) return; pin = h; }
    if (last >= 0 && Math.abs(h - last) < 2) return;
    if (prev >= 0 && Math.abs(h - prev) < 2 && last !== h) {
      if (++flips >= 3) { pin = Math.max(h, last); h = pin; if (h === last) return; }
    }
    prev = last; last = h; apply(h);
  }
  return {
    push: function (h) { if (typeof h !== 'number' || !isFinite(h)) return; pend = h; if (!queued) { queued = true; raf(flush); } },
    state: function () { return { last: last, pin: pin, flips: flips }; },
    reset: function () { last = prev = -1; flips = pin = 0; pend = -1; },
  };
}
