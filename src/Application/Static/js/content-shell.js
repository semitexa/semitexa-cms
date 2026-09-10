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

  // --- the cover image ----------------------------------------------------
  //
  // An enhancement over a control that already posts: the hidden input carries
  // the asset id, so with this file blocked the field still submits whatever it
  // was loaded with. What is added is choosing a new one and taking one off.
  //
  // Clearing writes an EMPTY STRING rather than removing the input. A field
  // that vanishes from the post is a field the module cannot tell apart from
  // one nobody touched — which is the difference between "leave the picture"
  // and "take the picture off".

  var UPLOAD_URL = '/os/app/cms/media';
  var ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

  // Each selection starts its own request, and they do not finish in order.
  // Without a generation the second choice can be overwritten by the first
  // arriving late — and a clear can be undone by a response for the image the
  // author just removed. The counter is per cover; a response whose generation
  // is no longer current is dropped.
  var coverGeneration = 0;
  var coverPending = 0;

  function csrfToken() {
    var input = form.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  }

  function coverShow(cover, assetId, url) {
    var hidden = cover.querySelector('input[type="hidden"]');
    var img = cover.querySelector('.cover__img');
    var empty = cover.querySelector('.cover__empty');
    var clear = cover.querySelector('.cover__clear');
    var pick = cover.querySelector('.cover__pick');
    var has = !!assetId;

    if (hidden) { hidden.value = assetId; }
    if (img) { img.hidden = !has; if (has && url) { img.src = url; } }
    if (empty) { empty.hidden = has; }
    if (clear) { clear.hidden = !has; }
    // The label is the only affordance when there is nothing yet, so it has to
    // say which of the two things it does.
    var pickText = pick ? pick.querySelector('span') : null;
    if (pickText) { pickText.textContent = has ? 'Замінити' : 'Вибрати зображення'; }
    markDirty();
  }

  function coverFail(cover, message) {
    var err = cover.querySelector('.cover__err');
    if (!err) { return; }
    err.textContent = message;
    err.hidden = false;
  }

  form.addEventListener('change', function (event) {
    var input = event.target;
    if (!input.matches || !input.matches('.cover__file')) { return; }

    var cover = input.closest('.cover');
    var file = input.files && input.files[0];
    input.value = '';
    if (!cover || !file) { return; }

    var err = cover.querySelector('.cover__err');
    if (err) { err.hidden = true; }

    // Checked here so an obvious mistake costs nothing; the ingest checks the
    // BYTES, which is the check that decides.
    if (ACCEPTED.indexOf(file.type) === -1) {
      coverFail(cover, 'Можна додавати лише зображення: JPEG, PNG або WebP.');
      return;
    }

    var data = new FormData();
    data.append('file', file);
    data.append('_csrf', csrfToken());

    var generation = ++coverGeneration;
    coverPending += 1;
    setState('saving');

    fetch(UPLOAD_URL, { method: 'POST', body: data, headers: { 'Accept': 'application/json' } })
      .then(function (response) { return response.json().then(function (body) { return { ok: response.ok, body: body }; }); })
      .then(function (result) {
        // Superseded by a later choice, or by the author clearing the field.
        if (generation !== coverGeneration) { return; }
        if (!result.ok || !result.body || !result.body.assetId) {
          // The server says which collection refused it and why — wrong format,
          // too large, over quota — and the author is standing there waiting.
          coverFail(cover, (result.body && result.body.error) || 'Не вдалося зберегти зображення.');
          return;
        }
        coverShow(cover, result.body.assetId, result.body.url);
      })
      .catch(function () {
        if (generation !== coverGeneration) { return; }
        coverFail(cover, 'Не вдалося зберегти зображення.');
      })
      .then(function () {
        coverPending -= 1;
        if (coverPending <= 0) { coverPending = 0; setState(dirty ? 'dirty' : 'clean'); }
      });
  });

  form.addEventListener('click', function (event) {
    var button = event.target.closest ? event.target.closest('.cover__clear') : null;
    if (!button) { return; }
    event.preventDefault();
    // Supersedes anything in flight, so a late response cannot put back the
    // image the author just removed.
    coverGeneration += 1;
    coverShow(button.closest('.cover'), '', '');
  });

  // Saving mid-upload would post the PREVIOUS id — the author would watch the
  // picture they chose not arrive, with nothing to explain it.
  form.addEventListener('submit', function (event) {
    if (coverPending > 0) {
      event.preventDefault();
      setState('saving');
    }
  });

  setState('clean');
})();
