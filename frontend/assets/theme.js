/* avocloud theme controller. Dark is the brand default; light is an explicit
   choice, stored in localStorage and shown with the kit's `.avo-light` class.
   `dark` is kept in step for Tailwind's dark: variants on older pages.
   The early guard in partials/head.php applies the stored choice before paint;
   this file wires up every [data-avo-theme-toggle] button. */
(function () {
  'use strict';

  var STORAGE_KEY = 'avo-theme';
  var CANVAS = { dark: '#141518', light: '#F4F6F7' }; // --avo-canvas per theme

  function current() {
    return document.documentElement.classList.contains('avo-light') ? 'light' : 'dark';
  }

  function syncMeta() {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', CANVAS[current()]);
  }

  function syncButtons() {
    var light = current() === 'light';
    document.querySelectorAll('[data-avo-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(light));
      btn.setAttribute('aria-label', light ? 'Switch to dark mode' : 'Switch to light mode');
      btn.setAttribute('title', light ? 'Dark mode' : 'Light mode');
    });
  }

  function apply(theme) {
    var root = document.documentElement;
    var light = theme === 'light';
    root.classList.toggle('avo-light', light);
    root.classList.toggle('dark', !light);
    try { localStorage.setItem(STORAGE_KEY, light ? 'light' : 'dark'); } catch (e) {}
    syncButtons();
    syncMeta();
    document.dispatchEvent(new CustomEvent('avo:theme', { detail: { theme: light ? 'light' : 'dark' } }));
  }

  function toggle() { apply(current() === 'light' ? 'dark' : 'light'); }

  window.avoTheme = { toggle: toggle, apply: apply, current: current };

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-avo-theme-toggle]').forEach(function (btn) {
      btn.addEventListener('click', toggle);
    });
    syncButtons();
    syncMeta();
  });
})();
