// Uploads for images dropped into an article body.
//
// Trix hands us an attachment and then waits: until setAttributes() gives it a
// url the figure stays in "pending" state, and until setUploadProgress()
// reaches 100 the author sees a progress bar. Doing nothing at all is what
// leaves a picture stuck half-transparent forever, which is why the failure
// paths below always end by removing the attachment rather than returning
// quietly.
//
// Our own file, not a patch to the vendored bundle: Trix is kept exactly as
// its author ships it, and everything we need is reachable from its events.
(function () {
  'use strict';

  var UPLOAD_URL = '/os/app/cms/media';
  // Kept in step with ContentImageCollection deliberately. The server decides —
  // this only spares the author a round trip and a wait to be told no.
  var ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

  function csrfToken(form) {
    var input = form && form.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  }

  function announce(editor, message) {
    // The editor is inside a dialog with no room for a toast, and a silent
    // refusal reads as a broken editor. The browser's own dialog is ugly and
    // unmistakable, which is the right trade for something that only fires
    // when a file was rejected.
    window.alert(message);
    void editor;
  }

  document.addEventListener('trix-file-accept', function (event) {
    var file = event.file;
    if (!file) { return; }

    if (ACCEPTED.indexOf(file.type) === -1) {
      event.preventDefault();
      announce(event.target, 'Можна додавати лише зображення: JPEG, PNG або WebP.');
    }
  });

  document.addEventListener('trix-attachment-add', function (event) {
    var attachment = event.attachment;
    if (!attachment || !attachment.file) { return; }

    var editor = event.target;
    var form = editor.closest('form');

    var data = new FormData();
    data.append('file', attachment.file);
    data.append('_csrf', csrfToken(form));

    var request = new XMLHttpRequest();
    request.open('POST', UPLOAD_URL, true);
    request.setRequestHeader('Accept', 'application/json');

    request.upload.addEventListener('progress', function (progress) {
      if (progress.lengthComputable) {
        attachment.setUploadProgress((progress.loaded / progress.total) * 100);
      }
    });

    request.addEventListener('load', function () {
      var body = null;
      try { body = JSON.parse(request.responseText); } catch (e) { body = null; }

      if (request.status >= 200 && request.status < 300 && body && body.url) {
        attachment.setAttributes({ url: body.url, href: body.url });
        return;
      }

      attachment.remove();
      announce(editor, (body && body.error) || 'Не вдалося завантажити зображення.');
    });

    request.addEventListener('error', function () {
      attachment.remove();
      announce(editor, 'Не вдалося завантажити зображення — перевірте зʼєднання.');
    });

    request.send(data);
  });
})();
