<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentRow;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Core\Attribute\AsService;

/**
 * What the console renders when it is not editing one record.
 *
 * A list of what is here; a choice between several places that could have been
 * meant; and the short answers — nothing was asked for, a name matched nothing,
 * a ref names nothing, the module does not author here or refused, a record is
 * about to be taken off the site.
 *
 * They left {@see ContentEditorPage} because they are not the editor, and the
 * structural budget is what said so out loud: six of them arrived in one day as
 * the CMS learned to list, create and remove, and the class crossed the outlier
 * threshold on methods that render a sentence and a link.
 *
 * The boundary is not size. Everything here answers a question about the site;
 * the editor renders ONE record with fields, a draft and a save. A grid that
 * grew a "new item" button belongs on this side of that line, not the other.
 */
#[AsService]
final class ContentAnswerPage
{
    /**
     * A ref that names nothing.
     *
     * The deletion hint belongs HERE and only here: something was asked for by
     * name or by ref and could not be found, which really is what a removed
     * record looks like. It used to be shown when nothing had been asked for at
     * all, so opening the editor with no ref told the author a record they never
     * named might have been deleted — an accusation out of thin air.
     */
    public function renderMissing(string $ref): string
    {
        return $this->emptyState(
            'Немає чого відкрити для «' . $this->escape($ref) . '».',
            'Можливо, запис видалено — оновіть карту.',
        );
    }

    /**
     * Nothing was asked for.
     *
     * Not an error and not a deletion: the editor was opened without a place,
     * which is what happens when a dialog is raised before anything is chosen.
     * The map is the action — {@see ContentEditorHandler} renders it as a
     * chooser when it has one, and this is the fallback for a site that has
     * none yet.
     */
    public function renderNothingChosen(): string
    {
        return $this->emptyState(
            'Оберіть, що відкрити.',
            'Ця консоль показує сторінки і списки з карти сайту. Її ще не побудовано — <code>cms:map:build</code>.',
        );
    }

    /**
     * A name nobody on the map is called.
     *
     * Distinct from {@see renderMissing()} on purpose. A ref that resolves to
     * nothing may well be a deleted record; a NAME that matches nothing is
     * usually just a name this site never used, and telling the author their
     * work may have been deleted because they said "Contacts" instead of
     * "Kontakty" is the same accusation in a different place.
     */
    public function renderNameNotFound(string $name): string
    {
        return $this->emptyState(
            'Не знайшов нічого з назвою «' . $this->escape($name) . '».',
            'Попросіть перелік вмісту, щоб побачити, що тут є.',
        );
    }

    /** The module lists these records and does not author them. */
    public function renderCannotCreate(string $ref): string
    {
        return $this->emptyState(
            'Тут не можна створити запис.',
            'Список «' . $this->escape($ref) . '» показує записи, які веде інший модуль.',
        );
    }

    /**
     * The module refused, or created something nothing can open.
     *
     * The module's own message is shown rather than swallowed: it is the only
     * party that knows why, and an author told "не вдалося" learns nothing.
     */
    public function renderCreateFailed(string $ref, string $why): string
    {
        return $this->emptyState(
            'Не вдалося створити запис у «' . $this->escape($ref) . '».',
            $this->escape($why),
        );
    }

    /** Why this record cannot be taken off the site. */
    public function renderCannotRemove(string $ref, string $why): string
    {
        return $this->emptyState(
            'Не можу прибрати «' . $this->escape($ref) . '».',
            $this->escape($why),
        );
    }

    /**
     * Name the thing before removing it.
     *
     * A row's delete control sits next to its title in a list, which is exactly
     * where a misclick lives, so the first post never removes — it asks, and it
     * says what will go by NAME rather than by ref. The wording promises only
     * what the CMS can deliver: the item stops being on the site. Whether the
     * module keeps a copy is the module's business, and claiming otherwise here
     * would be a promise this package cannot keep.
     */
    public function renderConfirmRemoval(string $ref, string $title, string $collectionRef, string $csrfToken): string
    {
        $title = $this->escape($title);
        $ref = $this->escape($ref);
        $collectionRef = $this->escape($collectionRef);
        $csrfToken = $this->escape($csrfToken);
        $back = '/os/app/cms?ref=' . rawurlencode($collectionRef);

        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>body{margin:0;display:grid;place-items:center;height:100vh;background:#0f172a;color:#a8b4cc;
font-family:ui-sans-serif,system-ui,sans-serif;font-size:13px;text-align:center;padding:24px}
.card{max-width:420px} h1{font-size:15px;color:#e2e8f0;margin:0 0 6px} p{margin:0 0 18px;line-height:1.5}
.row{display:flex;gap:10px;justify-content:center}
button,a.btn{font:inherit;font-size:13px;padding:7px 14px;border-radius:6px;cursor:pointer;text-decoration:none;
  border:1px solid rgba(148,163,184,.35);background:transparent;color:#e2e8f0}
button{border-color:rgba(248,113,113,.5);color:#fca5a5}</style></head>
<body><div class="card">
<h1>Прибрати «{$title}»?</h1>
<p>Запис зникне з сайту. Це рішення модуля, який його веде — він може лишити копію в себе.</p>
<div class="row">
<a class="btn" href="{$back}">Скасувати</a>
<form method="post" action="/os/app/cms/delete">
<input type="hidden" name="ref" value="{$ref}">
<input type="hidden" name="collection" value="{$collectionRef}">
<input type="hidden" name="confirm" value="1">
<input type="hidden" name="_csrf" value="{$csrfToken}">
<button type="submit">Прибрати</button>
</form>
</div></div></body></html>
HTML;
    }

    /**
     * @param string $csrfToken needed by either control; both post, because a
     *        GET that writes can be followed by a crawler or a prefetch.
     * @param bool $canCreate whether the module authors into this collection
     * @param bool $canRemove whether it also lets items be taken off the site
     *
     * Creating and removing are asked for separately because they are separate
     * rights: a module may offer either, both or neither, and a control that
     * answers with a refusal teaches an author the console is unreliable.
     */
    public function renderRows(
        ContentRows $rows,
        string $ref,
        string $csrfToken = '',
        bool $canCreate = false,
        bool $canRemove = false,
    ): string
    {
        $title = $this->escape($rows->title);
        $count = $rows->total;

        $create = '';
        if ($canCreate && $csrfToken !== '' && $ref !== '') {
            $create = '<form class="new" method="post" action="/os/app/cms/create">'
                . '<input type="hidden" name="ref" value="' . $this->escape($ref) . '">'
                . '<input type="hidden" name="_csrf" value="' . $this->escape($csrfToken) . '">'
                . '<button type="submit">+ Новий запис</button></form>';
        }

        $items = '';
        foreach ($rows->rows as $row) {
            $meta = $row->meta === [] ? '' : '<span class="meta">' . $this->escape(implode(' · ', $row->meta)) . '</span>';
            $remove = '';
            if ($canRemove && $csrfToken !== '' && $ref !== '') {
                // A GET link would let a crawler or a prefetch remove content,
                // so it posts — and the post it makes only ASKS.
                $remove = '<form class="rm" method="post" action="/os/app/cms/delete">'
                    . '<input type="hidden" name="ref" value="' . $this->escape($row->ref) . '">'
                    . '<input type="hidden" name="collection" value="' . $this->escape($ref) . '">'
                    . '<input type="hidden" name="_csrf" value="' . $this->escape($csrfToken) . '">'
                    . '<button type="submit" title="Прибрати з сайту">×</button></form>';
            }

            $items .= '<div class="row-wrap">'
                . '<a class="row" href="/os/app/cms?ref=' . rawurlencode($row->ref) . '">'
                . '<span class="row__title">' . $this->escape($row->title !== '' ? $row->title : 'Без назви') . '</span>'
                . $meta . '</a>' . $remove . '</div>';
        }

        if ($items === '') {
            $items = '<p class="empty">Тут поки порожньо.</p>';
        }

        $pager = '';
        if ($rows->pages() > 1) {
            $base = '/os/app/cms?ref=' . rawurlencode($ref) . '&page=';
            $prev = $rows->hasPrevious()
                ? '<a class="page" href="' . $base . ($rows->page - 1) . '">← Назад</a>'
                : '<span class="page page--off">← Назад</span>';
            $next = $rows->hasNext()
                ? '<a class="page" href="' . $base . ($rows->page + 1) . '">Далі →</a>'
                : '<span class="page page--off">Далі →</span>';
            $pager = '<div class="pager">' . $prev
                . '<span class="page-of">' . $rows->page . ' / ' . $rows->pages() . '</span>' . $next . '</div>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);
       display:flex;flex-direction:column}
  .bar{display:flex;align-items:baseline;gap:10px;padding:12px 16px;border-bottom:1px solid rgba(var(--line-rgb),.18)}
  .bar h1{margin:0;font-size:14px;font-weight:600;color:var(--strong)}
  .new{margin-left:auto}
  .new button{font:inherit;font-size:12px;padding:5px 11px;border-radius:6px;cursor:pointer;
              border:1px solid rgba(var(--line-rgb),.35);background:transparent;color:var(--strong)}
  .new button:hover{background:rgba(var(--line-rgb),.12)}
  .row-wrap{display:flex;align-items:stretch}
  .row-wrap .row{flex:1}
  .rm{display:flex;align-items:center}
  .rm button{font:inherit;font-size:15px;line-height:1;padding:0 12px;height:100%;cursor:pointer;
             border:0;background:transparent;color:rgba(var(--line-rgb),.55)}
  .rm button:hover{color:#f87171}
  .bar .count{font-size:12px;color:var(--dim)}
  .list{flex:1;overflow:auto;padding:6px 0}
  .row{display:flex;flex-direction:column;gap:3px;padding:11px 16px;text-decoration:none;color:inherit;
       border-bottom:1px solid rgba(var(--line-rgb),.10)}
  .row:hover{background:rgba(var(--line-rgb),.08)}
  .row__title{font-size:14px;color:var(--strong)}
  .meta{font-size:11px;color:var(--dim)}
  .empty{padding:24px 16px;font-size:13px;color:var(--dim)}
  .pager{display:flex;align-items:center;gap:14px;padding:10px 16px;border-top:1px solid rgba(var(--line-rgb),.18);font-size:12px}
  .page{color:var(--accent);text-decoration:none}
  .page--off{color:var(--dim)}
  .page-of{margin-left:auto;color:var(--dim)}
  :root{color-scheme:dark;--bg:#0f172a;--text:#dbe7ff;--strong:#eaf2ff;--dim:#6f7d99;
    --line-rgb:148,163,184;--accent:#37b7ff}
</style></head>
<body>
  <div class="bar"><h1>{$title}</h1><span class="count">{$count}</span>{$create}</div>
  <div class="list">{$items}</div>
  {$pager}
</body></html>
HTML;
    }








    private function emptyState(string $headline, string $hint): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><title>—</title>
<style>body{margin:0;display:grid;place-items:center;height:100vh;background:#0f172a;color:#a8b4cc;
font-family:ui-sans-serif,system-ui,sans-serif;font-size:13px;text-align:center;padding:24px}
code{background:#1e293b;padding:1px 5px;border-radius:3px;color:#cbd5e1}</style></head>
<body><div>{$headline}<br>{$hint}</div></body></html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
