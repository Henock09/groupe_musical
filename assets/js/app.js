(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  document.addEventListener('DOMContentLoaded', function () {

    // Menu mobile
    $$('[data-toggle]').forEach(function (b) {
      b.addEventListener('click', function () { var t = document.getElementById(b.dataset.toggle); if (t) t.classList.toggle('open'); });
    });
    document.addEventListener('click', function (e) {
      var side = $('#side');
      if (side && side.classList.contains('open') && !side.contains(e.target) && !e.target.closest('[data-toggle]')) side.classList.remove('open');
    });

    // Confirmation avant action sensible (formulaires marqués data-confirm)
    $$('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) { if (!window.confirm(f.dataset.confirm)) e.preventDefault(); });
    });

    // Boîtes de dialogue : ouverture, remplissage des champs, fermeture
    $$('[data-open-dialog]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var d = document.getElementById(btn.dataset.openDialog);
        if (!d) return;
        $$('[data-fill]', d).forEach(function (inp) { if (btn.dataset[inp.dataset.fill] !== undefined) inp.value = btn.dataset[inp.dataset.fill]; });
        $$('[data-text]', d).forEach(function (el) { if (btn.dataset[el.dataset.text] !== undefined) el.textContent = btn.dataset[el.dataset.text]; });
        if (d.showModal) d.showModal(); else d.setAttribute('open', '');
        var first = $('input:not([type=hidden]), select', d); if (first) first.focus();
      });
    });
    $$('[data-close]').forEach(function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });
    $$('dialog').forEach(function (d) {
      d.addEventListener('click', function (e) { if (e.target === d) d.close(); });
    });

    // Filtre instantané d'un tableau
    $$('[data-filter]').forEach(function (inp) {
      inp.addEventListener('input', function () {
        var t = document.getElementById(inp.dataset.filter), q = inp.value.trim().toLowerCase();
        if (!t) return;
        $$('tbody tr', t).forEach(function (tr) { tr.style.display = tr.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : ''; });
      });
    });

    // Générateur de mot de passe provisoire
    $$('[data-genpw]').forEach(function (b) {
      b.addEventListener('click', function () {
        var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '', a = new Uint32Array(8);
        (window.crypto || window.msCrypto).getRandomValues(a);
        for (var i = 0; i < a.length; i++) out += chars[a[i] % chars.length];
        document.getElementById(b.dataset.genpw).value = out;
      });
    });

    // Tout cocher / décocher
    $$('[data-check-all]').forEach(function (b) {
      b.addEventListener('click', function () {
        var boxes = $$('input[type=checkbox]', document.getElementById(b.dataset.checkAll));
        var all = boxes.every(function (c) { return c.checked; });
        boxes.forEach(function (c) { c.checked = !all; });
      });
    });

    // Champs affichés selon le type choisi (cotisation mensuelle/extraordinaire, don argent/nature)
    $$('form').forEach(function (form) {
      var conditional = $$('[data-show-for]', form);
      if (!conditional.length) return;
      var sel = $('select', form);
      var update = function () { conditional.forEach(function (el) { el.hidden = el.dataset.showFor !== sel.value; }); };
      sel.addEventListener('change', update); update();
    });

    // Les messages de succès disparaissent seuls
    setTimeout(function () { $$('.flash-ok').forEach(function (f) { f.style.transition = 'opacity .6s'; f.style.opacity = '0'; setTimeout(function () { f.remove(); }, 700); }); }, 6000);
  });
})();
