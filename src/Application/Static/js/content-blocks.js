// A page of blocks: what the buttons under each passage actually do.
//
// The console owns the page format, so this file owns exactly one job — keep
// the DOM an author edits and the ONE hidden input that submits it in step.
// Everything visible here is nameless markup; the named input is written on
// submit, and on every change, so a save that happens for any other reason
// still carries what the author sees.
//
// Layout never enters the editor's own document. Trix edits the inside of one
// passage and is never handed where that passage sits — measured: Trix 2.1.19
// parses a block attribute value and serialises it away, which is how an
// author loses an alignment they set. Here alignment is a select beside the
// block and a property of the block, so nothing can eat it.
(function () {
  'use strict';

  var FORMAT = 'semitexa.cms.blocks/v1';

  function listOf(node) {
    return node.closest('[data-blocks]');
  }

  function blocksIn(list) {
    return Array.prototype.slice.call(list.querySelectorAll('[data-block]'));
  }

  function readBlock(article) {
    var kind = article.getAttribute('data-block-kind') === 'image' ? 'image' : 'text';
    var alignEl = article.querySelector('[data-block-align]');
    var sizeEl = article.querySelector('[data-block-size]');
    var block = {
      kind: kind,
      payload: '',
      layout: {
        align: alignEl ? alignEl.value : 'left',
        size: sizeEl ? sizeEl.value : 'full',
      },
    };

    if (kind === 'text') {
      // Through the input Trix was BOUND to, resolved by its own id: Trix
      // writes the value there, while the editor element's innerHTML carries
      // its cursor targets and block comments. Asking the editor would store
      // the editor's scaffolding as the author's page.
      var editor = article.querySelector('trix-editor');
      var bound = editor ? document.getElementById(editor.getAttribute('input')) : null;
      block.payload = bound ? bound.value : '';
    } else {
      // The picker's own hidden input, found the way content-shell.js finds it.
      var asset = article.querySelector('.cover input[type="hidden"]');
      var alt = article.querySelector('[data-block-alt]');
      block.payload = asset ? asset.value : '';
      if (alt && alt.value !== '') block.alt = alt.value;
    }

    return block;
  }

  function collect(list) {
    var field = document.getElementById(list.getAttribute('data-blocks'));
    if (!field) return;

    var blocks = blocksIn(list).map(readBlock).filter(function (block) {
      // An empty passage is a gap nobody meant, and an image block with no
      // picture is a figure with nothing in it. Dropping them on collect keeps
      // the stored page clean without telling the author off mid-edit.
      if (block.kind === 'image') return block.payload !== '';
      return block.payload.replace(/<[^>]*>/g, '').trim() !== '' || block.payload.indexOf('<img') !== -1;
    });

    field.value = blocks.length === 0 ? '' : JSON.stringify({ format: FORMAT, blocks: blocks });
  }

  // From the server-rendered <template> for that kind, NOT from an existing
  // block: cloning a sibling means an empty page can never gain its first
  // block, and a page holding only text can never gain a picture — both
  // buttons just do nothing, which reads as a broken console.
  function newBlock(list, kind) {
    var template = list.parentNode.querySelector('[data-block-template="' + kind + '"]');
    if (!template) return null;

    var copy = template.content.firstElementChild.cloneNode(true);
    bindFreshEditor(copy);

    return copy;
  }

  // A cloned passage needs an id of its own. Trix binds by getElementById, so
  // two passages sharing one would write into a single value and the author
  // would lose a paragraph without a word.
  function bindFreshEditor(article) {
    var old = article.querySelector('trix-editor');
    var input = article.querySelector('input[type="hidden"]');
    if (!old || !input) return;

    var id = 'blk-' + Date.now() + '-' + Math.random().toString(36).slice(2, 8);
    input.id = id;
    input.value = '';

    var editor = document.createElement('trix-editor');
    editor.setAttribute('input', id);
    editor.className = old.className;
    old.parentNode.replaceChild(editor, old);
  }

  document.addEventListener('click', function (event) {
    var target = event.target.closest
      ? event.target.closest('[data-block-move],[data-block-remove],[data-block-add]')
      : null;
    if (!target) return;

    event.preventDefault();

    var list = listOf(target) || document.querySelector('[data-blocks]');
    if (!list) return;

    if (target.hasAttribute('data-block-add')) {
      var made = newBlock(list, target.getAttribute('data-block-add'));
      if (made) list.appendChild(made);
      collect(list);
      return;
    }

    var article = target.closest('[data-block]');
    if (!article) return;

    if (target.hasAttribute('data-block-remove')) {
      article.parentNode.removeChild(article);
      collect(list);
      return;
    }

    var up = target.getAttribute('data-block-move') === 'up';
    var sibling = up ? article.previousElementSibling : article.nextElementSibling;
    if (sibling) {
      // insertBefore in both directions: moving down is moving the NEXT one up,
      // which keeps one code path and cannot drift out of step with itself.
      up ? article.parentNode.insertBefore(article, sibling)
         : article.parentNode.insertBefore(sibling, article);
    }
    collect(list);
  });

  // Layout is chosen with a select, so the page is written the moment it
  // changes: an author who picks «По центру» and closes the window should not
  // discover on reopening that only typing was saved.
  document.addEventListener('change', function (event) {
    var list = listOf(event.target);
    if (list) collect(list);
  });

  document.addEventListener('trix-change', function (event) {
    var list = listOf(event.target);
    if (list) collect(list);
  });

  // The save itself, whatever triggered it.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form || !form.querySelectorAll) return;
    form.querySelectorAll('[data-blocks]').forEach(collect);
  }, true);
})();
