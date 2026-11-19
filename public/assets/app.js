/* BarberLead UI helpers. No inline scripts (CSP: script-src 'self'). */
(function () {
  'use strict';
  var csrf = document.querySelector('meta[name="csrf"]');
  csrf = csrf ? csrf.content : '';

  // Mobile menu
  var toggle = document.getElementById('menu-toggle');
  if (toggle) {
    toggle.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('menu-open') && !e.target.closest('.side') && e.target !== toggle) {
        document.body.classList.remove('menu-open');
      }
    });
  }

  // Sidebar area search: shows matching areas (and their parents), opens their branches.
  var areaSearch = document.getElementById('area-search');
  if (areaSearch) {
    var norm = function (s) { return s.toLowerCase().replace(/ё/g, 'е'); };
    areaSearch.addEventListener('input', function () {
      var q = norm(areaSearch.value.trim());
      var items = document.querySelectorAll('.tree li');
      items.forEach(function (li) { li.classList.remove('hidden'); });
      if (!q) {
        document.querySelectorAll('.tree details[data-auto]').forEach(function (d) { d.open = false; d.removeAttribute('data-auto'); });
        return;
      }
      items.forEach(function (li) {
        var text = norm(li.getAttribute('data-search') || '');
        var match = text.indexOf(q) !== -1 || li.querySelector('[data-search*="' + q.replace(/"/g, '') + '"]');
        if (!match) { li.classList.add('hidden'); return; }
        var d = li.querySelector(':scope > details');
        if (d && !d.open) { d.open = true; d.setAttribute('data-auto', '1'); }
      });
    });
  }

  // Confirm before destructive / important actions
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // Selects that submit their form on change
  document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Copy outreach text
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var el = document.getElementById(btn.getAttribute('data-copy'));
      if (!el) return;
      var text = el.innerText;
      var done = function () { var t = btn.textContent; btn.textContent = 'Копирано ✓'; setTimeout(function () { btn.textContent = t; }, 1600); };
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); }
      else { var r = document.createRange(); r.selectNodeContents(el); var s = getSelection(); s.removeAllRanges(); s.addRange(r); document.execCommand('copy'); done(); }
    });
  });

  // Kanban: drag & drop (desktop) + select fallback (mobile)
  function moveCard(card, status, col) {
    var id = card.getAttribute('data-id');
    return fetch('/businesses/' + id + '/status', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ status: status, _csrf: csrf }),
      credentials: 'same-origin'
    }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) { alert(res.j.error || 'Грешка'); return; }
        if (col) col.querySelector('.cards').prepend(card);
        card.setAttribute('data-status', status);
        var sel = card.querySelector('select'); if (sel) sel.value = status;
        updateCounts();
      }).catch(function () { alert('Няма връзка със сървъра.'); });
  }
  function updateCounts() {
    document.querySelectorAll('.col').forEach(function (c) {
      var n = c.querySelectorAll('.kcard').length; var el = c.querySelector('.cnt'); if (el) el.textContent = n;
    });
  }
  var dragged = null;
  document.querySelectorAll('.kcard').forEach(function (card) {
    card.addEventListener('dragstart', function (e) {
      dragged = card;
      // Firefox only starts a drag when data is set.
      if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', card.getAttribute('data-id')); }
    });
    // Links inside the card must not start their own (URL) drag.
    card.querySelectorAll('a').forEach(function (a) { a.setAttribute('draggable', 'false'); });
    var sel = card.querySelector('select');
    if (sel) sel.addEventListener('change', function () {
      var col = document.querySelector('.col[data-statuses~="' + sel.value + '"]');
      moveCard(card, sel.value, col);
    });
  });
  document.querySelectorAll('.col').forEach(function (col) {
    col.addEventListener('dragover', function (e) { e.preventDefault(); col.classList.add('over'); });
    col.addEventListener('dragleave', function () { col.classList.remove('over'); });
    col.addEventListener('drop', function (e) {
      e.preventDefault(); col.classList.remove('over');
      if (!dragged || dragged.closest('.col') === col) return;
      moveCard(dragged, col.getAttribute('data-target'), col);
      dragged = null;
    });
  });
})();
