/* Tema + helper bersama. Muat di <head> agar tidak berkedip. */
(function () {
  var d = document.documentElement, K = 'pns-theme', s = null;
  try { s = localStorage.getItem(K); } catch (e) {}
  d.dataset.theme = s || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  function paint() {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.innerHTML = '<i class="fa-solid fa-' + (d.dataset.theme === 'dark' ? 'sun' : 'moon') + '"></i>';
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    paint();
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.onclick = function () {
        d.dataset.theme = d.dataset.theme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(K, d.dataset.theme); } catch (e) {}
        paint();
      };
    });
  });

  window.PNS = {
    esc: function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); },
    /* Parser CSV (mendukung tanda kutip & koma di dalam sel) */
    csv: function (t) {
      var r = [], row = [], f = '', q = false, i, c;
      for (i = 0; i < t.length; i++) {
        c = t[i];
        if (q) { if (c === '"') { if (t[i + 1] === '"') { f += '"'; i++; } else q = false; } else f += c; }
        else if (c === '"') q = true;
        else if (c === ',') { row.push(f); f = ''; }
        else if (c === '\n' || c === '\r') { if (c === '\r' && t[i + 1] === '\n') i++; row.push(f); r.push(row); row = []; f = ''; }
        else f += c;
      }
      if (f || row.length) { row.push(f); r.push(row); }
      return r;
    },
    /* Sanitasi HTML dari Google Form: hanya tag aman, tautan http(s) saja */
    clean: function (h) {
      var ok = {A:1,B:1,STRONG:1,I:1,EM:1,BR:1,UL:1,OL:1,LI:1,P:1,U:1};
      var doc = new DOMParser().parseFromString('<!doctype html><body>' + String(h).replace(/\n/g, '<br>'), 'text/html');
      (function walk(n) {
        Array.from(n.childNodes).forEach(function (c) {
          if (c.nodeType === 8) { c.remove(); return; }
          if (c.nodeType !== 1) return;
          if (/^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|LINK|META)$/.test(c.tagName)) { c.remove(); return; }
          walk(c);
          if (!ok[c.tagName]) { c.replaceWith.apply(c, Array.from(c.childNodes)); return; }
          Array.from(c.attributes).forEach(function (a) { if (!(c.tagName === 'A' && a.name === 'href')) c.removeAttribute(a.name); });
          if (c.tagName === 'A') {
            var u = c.getAttribute('href') || '';
            if (/^https?:/i.test(u)) { c.target = '_blank'; c.rel = 'noopener noreferrer'; } else c.removeAttribute('href');
          }
        });
      })(doc.body);
      return doc.body.innerHTML;
    }
  };
})();