// The editor shell: knowing whether your work is safe, and reaching the
// settings without losing sight of the text.
//
// Everything here is an enhancement over a form that already works. With this
// file blocked the page is still a title, a body and a Зберегти button — which
// is why nothing below is required for a save to happen.
(function () {
  'use strict';

  var form = document.querySelector('form[data-editor]');
  if (!form) { return; }

  var state = document.getElementById('state');
  var dirty = false;

  // --- is my work safe? ---------------------------------------------------
  //
  // An author who cannot tell saved from unsaved saves constantly, or not at
  // all. Three words in the bar remove the question.

  function setState(next) {
    if (!state) { return; }
    state.dataset.state = next;
    state.textContent = next === 'dirty' ? 'Незбережені зміни'
      : next === 'saving' ? 'Зберігаю…'
      : 'Збережено';
  }

  function markDirty() {
    if (dirty) { return; }
    dirty = true;
    setState('dirty');
  }

  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  // Trix writes through a hidden input, whose programmatic updates raise no
  // input event — so the editor's own change event is the only signal here.
  document.addEventListener('trix-change', markDirty);

  form.addEventListener('submit', function () {
    dirty = false;
    setState('saving');
  });

  // The browser only honours this when the author has interacted with the
  // page, which is exactly when there is something to lose.
  window.addEventListener('beforeunload', function (event) {
    if (!dirty) { return; }
    event.preventDefault();
    event.returnValue = '';
  });

  // Ctrl+S is what a person who writes for a living already presses.
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && (event.key === 's' || event.key === 'S')) {
      event.preventDefault();
      if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
    }
  });

  // --- the properties drawer ----------------------------------------------

  function toggleProps() {
    var open = document.body.classList.toggle('props');
    var buttons = form.querySelectorAll('[data-act="props"][aria-expanded]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (open) {
      var first = document.querySelector('.panel__body input, .panel__body textarea');
      if (first) { first.focus(); }
    }
  }

  form.addEventListener('click', function (event) {
    var act = event.target.closest ? event.target.closest('[data-act]') : null;
    if (!act) { return; }
    if (act.dataset.act === 'props') { event.preventDefault(); toggleProps(); }
  });

  setState('clean');
})();
