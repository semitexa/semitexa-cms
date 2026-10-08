# Semitexa CMS

`semitexa/cms`

A site described as a map of places: pages that open an editor and collections that open a grid. Content is edited from the Semitexa OS shell, through the **Content** app at `/os/app/cms`.

## Install

Not included by the installer. Add it to an existing project from the project root:

```bash
docker compose run --rm --no-deps --user "$(id -u):$(id -g)" app composer require semitexa/cms
bin/semitexa server:restart
bin/semitexa orm:sync
```

It depends on `semitexa/os` and `semitexa/weave` (also not in the installer's set); Composer installs them with it.

## What it provides

- Attributes (namespace `Semitexa\Cms\Attribute`):
  - `#[AsSiteMap]` — the class that authors one site's map (one per tenant);
  - `#[AsContentEditor]` — the editor for one kind of record;
  - `#[AsContentCollection]` — the source of rows for one kind of collection;
  - `#[AsContentTranslator]` — keeps one kind of record's languages in step.
- OS app and assistant skills: `Content` (opens `/os/app/cms`) and `content-list` (lists the pages and collections that can be edited).
- Routes under `/os/app/cms` (`/create`, `/save`, `/delete`, `/media`, `/media/{assetId}`).
- Tables `cms_content_seo` and `cms_translation_task`.
- Scheduled jobs `cms.seo` and `cms.translate` (every 5 minutes by default; `CMS_SEO_CRON` / `CMS_TRANSLATE_CRON`), run by the `semitexa/scheduler` worker (`bin/semitexa scheduler:work`).
- Console commands: `cms:map:build`, `cms:map:check`, `cms:seo:drain`, `cms:translate:drain`.

## Documentation

- Attributes: https://semitexa.com/docs/reference/attributes-cms
- Commands: https://semitexa.com/docs/reference/commands-cms

## License

MIT, see [LICENSE](LICENSE).
