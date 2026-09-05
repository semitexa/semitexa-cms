# trix

Vendored, unmodified, from the npm release. Do not edit these files: an upgrade
is a file swap, and a local edit turns that swap into archaeology. If Trix needs
to behave differently, reach for `Trix.config` and the `trix-*` events first —
that covers more than it looks like it does. If it genuinely cannot, the answer
is a fork kept as a patch set on upstream, living as our own package with our
own tests, not an edited copy here.

- package:   trix
- version:   2.1.19
- license:   MIT (see LICENSE — Copyright (c) 37signals, LLC)
- tarball:   https://registry.npmjs.org/trix/-/trix-2.1.19.tgz
- integrity: sha512-E7RA3EOeUiUwNJlrF5onIOkqCA06xUU6GmHOVxXyMnGMValrDK3Ce7uaMVgiVUOvVt4mzUERAHAzD10mxoLpOg==
  (verified on download against the value npm publishes)

Files taken: `dist/trix.umd.min.js`, `dist/trix.css`, `LICENSE`.
The UMD build is deliberate — it loads from a plain `<script>` tag with no build
step, which is what this project has.

## Upgrading

    curl -sL https://registry.npmjs.org/trix/-/trix-<version>.tgz -o trix.tgz
    openssl dgst -sha512 -binary trix.tgz | openssl base64 -A   # compare to npm's integrity
    tar xzf trix.tgz package/dist/trix.umd.min.js package/dist/trix.css package/LICENSE

Then update the version, tarball and integrity above.
