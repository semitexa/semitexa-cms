<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Ssr\Application\Service\Asset\AssetManager;
use Semitexa\Ssr\Application\Service\Asset\ScriptNonceSource;

/**
 * Renders the editor dialog.
 *
 * Standalone HTML, like the console's other app surfaces: the Focus zone embeds
 * them in an iframe, so a page here cannot rely on the shell's stylesheet and
 * carries the few tokens it needs.
 */
#[AsService]
final class ContentEditorPage
{
    public function render(ContentDraft $draft, string $csrfToken, ?string $savedMessage = null, ?string $error = null): string
    {
        $fields = '';
        $rich = false;
        foreach ($draft->fields as $field) {
            $fields .= $this->field($field);
            $rich = $rich || $field->kind === ContentField::HTML;
        }

        // Only a draft that actually has a rich field pays for the editor.
        $richHead = $rich ? $this->richEditorHead() : '';

        $title = $this->escape($draft->title);
        $ref = $this->escape($draft->ref);
        $token = $this->escape($csrfToken);

        $notice = '';
        if ($error !== null) {
            $notice = '<p class="notice notice--bad">' . $this->escape($error) . '</p>';
        } elseif ($savedMessage !== null) {
            $notice = '<p class="notice notice--ok">' . $this->escape($savedMessage) . '</p>';
        }

        $view = $draft->publicUrl === null || $draft->publicUrl === ''
            ? ''
            : '<a class="view" href="' . $this->escape($draft->publicUrl) . '" target="_blank" rel="noopener">Подивитись на сайті ↗</a>';

        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
{$richHead}
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);
       display:flex;flex-direction:column}
  .bar{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid rgba(var(--line-rgb),.18)}
  .bar h1{margin:0;font-size:14px;font-weight:600;color:var(--strong);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .view{margin-left:auto;font-size:12px;color:var(--accent);text-decoration:none;white-space:nowrap}
  form{flex:1;overflow:auto;padding:16px;display:grid;gap:14px;align-content:start}
  label{display:grid;gap:6px;font-size:12px;color:var(--mute)}
  input,textarea{width:100%;padding:10px 12px;border-radius:9px;border:1px solid rgba(var(--line-rgb),.25);
    background:rgba(var(--ink-rgb),.6);color:var(--strong);font-size:14px;font-family:inherit}
  textarea{min-height:200px;line-height:1.6;resize:vertical}
  input:focus,textarea:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.2)}
  .hint{font-size:11px;color:var(--dim)}
  .actions{position:sticky;bottom:0;display:flex;gap:10px;align-items:center;padding:12px 16px;
    border-top:1px solid rgba(var(--line-rgb),.18);background:var(--bg)}
  button{padding:10px 18px;border:none;border-radius:9px;background:var(--accent);color:#04121f;font-size:14px;font-weight:600;
    font-family:inherit;cursor:pointer}
  button:hover{filter:brightness(1.08)}
  .notice{margin:0;padding:10px 16px;font-size:13px}
  .notice--ok{color:var(--ok)} .notice--bad{color:var(--danger)}
  :root{color-scheme:dark;--bg:#0f172a;--text:#dbe7ff;--strong:#eaf2ff;--mute:#a8b4cc;--dim:#6f7d99;
    --line-rgb:148,163,184;--ink-rgb:2,8,23;--accent:#37b7ff;--accent-rgb:55,183,255;--ok:#5eead4;--danger:#ff6b82}
</style></head>
<body>
  <div class="bar"><h1>{$title}</h1>{$view}</div>
  {$notice}
  <form method="post" action="/os/app/cms/save">
    <input type="hidden" name="ref" value="{$ref}">
    <input type="hidden" name="_csrf" value="{$token}">
    {$fields}
    <div class="actions"><button type="submit">Зберегти</button></div>
  </form>
</body></html>
HTML;
    }

    /**
     * The list behind a collection.
     *
     * A row is a link to its own editor and nothing else — no inline editing,
     * no bulk actions. A grid whose rows are half-editable is a grid where it
     * is never clear which half you are in.
     */
    public function renderRows(ContentRows $rows, string $ref): string
    {
        $title = $this->escape($rows->title);
        $count = $rows->total;

        $items = '';
        foreach ($rows->rows as $row) {
            $meta = $row->meta === [] ? '' : '<span class="meta">' . $this->escape(implode(' · ', $row->meta)) . '</span>';
            $items .= '<a class="row" href="/os/app/cms?ref=' . rawurlencode($row->ref) . '">'
                . '<span class="row__title">' . $this->escape($row->title !== '' ? $row->title : 'Без назви') . '</span>'
                . $meta . '</a>';
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
  <div class="bar"><h1>{$title}</h1><span class="count">{$count}</span></div>
  <div class="list">{$items}</div>
  {$pager}
</body></html>
HTML;
    }

    public function renderMissing(string $ref): string
    {
        $ref = $this->escape($ref);

        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><title>—</title>
<style>body{margin:0;display:grid;place-items:center;height:100vh;background:#0f172a;color:#a8b4cc;
font-family:ui-sans-serif,system-ui,sans-serif;font-size:13px;text-align:center;padding:24px}</style></head>
<body><div>Немає чого відкрити для «{$ref}».<br>Можливо, запис видалено — оновіть карту.</div></body></html>
HTML;
    }

    private function field(ContentField $field): string
    {
        $name = $this->escape($field->name);
        $label = $this->escape($field->label);
        $value = $this->escape($field->value);
        $required = $field->required ? ' required' : '';
        $hint = $field->hint === '' ? '' : '<span class="hint">' . $this->escape($field->hint) . '</span>';

        $control = match ($field->kind) {
            ContentField::LINE => '<input type="text" name="' . $name . '" value="' . $value . '"' . $required . '>',
            ContentField::HTML => $this->richControl($name, $value, $required),
            default => '<textarea name="' . $name . '"' . $required . '>' . $value . '</textarea>',
        };

        return '<label>' . $label . $control . $hint . '</label>';
    }

    /**
     * The rich-text control: a hidden input Trix reads from and writes back to.
     *
     * The input carries the value in both directions, so the field posts under
     * its own name exactly as the textarea did — nothing downstream learns that
     * the control changed. `required` stays on the input rather than on
     * <trix-editor>, which is not a form control the browser validates.
     */
    private function richControl(string $escapedName, string $escapedValue, string $required): string
    {
        // The id is derived from the field name, which is an author-facing key
        // and already escaped; the slug keeps it a valid id whatever it holds.
        $id = 'rich-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $escapedName);

        return '<input id="' . $id . '" type="hidden" name="' . $escapedName . '" value="' . $escapedValue . '"' . $required . '>'
            . '<trix-editor input="' . $id . '" class="rich"></trix-editor>';
    }

    /**
     * Stylesheet, script and CSP nonce for the vendored editor.
     *
     * Trix styles itself by inserting a <style> element into the head, and
     * reads a nonce from `<meta name="trix-csp-nonce">` before doing so — so a
     * surface under a strict `style-src` has to hand it one, or the editor
     * renders unstyled with nothing in the page to explain why. When the
     * application registers no nonce provider the meta is omitted entirely
     * rather than emitted empty, which would be a claim we cannot back.
     */
    private function richEditorHead(): string
    {
        $nonce = ScriptNonceSource::value();
        $meta = $nonce === ''
            ? ''
            : '<meta name="trix-csp-nonce" content="' . $this->escape($nonce) . '">' . "\n";

        $css = $this->escape(AssetManager::getUrl('vendor/trix/trix.css', 'cms'));
        $js = $this->escape(AssetManager::getUrl('vendor/trix/trix.umd.min.js', 'cms'));

        // Only colour and spacing are touched — nothing that changes how the
        // editor behaves. Emitted here rather than in the page's own style
        // block so a draft with no rich field carries none of it.
        $skin = <<<CSS
<style>
  trix-toolbar .trix-button-group{border-color:rgba(var(--line-rgb),.25);margin-bottom:6px}
  trix-toolbar .trix-button{background:rgba(var(--ink-rgb),.6);border-bottom:none;color:var(--strong)}
  trix-toolbar .trix-button:not(:disabled):hover{background:rgba(var(--line-rgb),.18)}
  trix-toolbar .trix-button.trix-active{background:var(--accent);color:#04121f}
  trix-toolbar .trix-button:disabled{opacity:.35}
  trix-editor.rich{min-height:220px;line-height:1.6;padding:10px 12px;border-radius:9px;
    border:1px solid rgba(var(--line-rgb),.25);background:rgba(var(--ink-rgb),.6);color:var(--strong);font-size:14px}
  trix-editor.rich:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(var(--accent-rgb),.2)}
  trix-editor.rich a{color:var(--accent)}
  trix-editor.rich blockquote{border-left:2px solid rgba(var(--line-rgb),.4);margin:0;padding-left:12px;color:var(--mute)}
</style>
CSS;

        return $meta
            . '<link rel="stylesheet" href="' . $css . '">' . "\n"
            . $skin . "\n"
            . '<script src="' . $js . '" defer' . ScriptNonceSource::attribute() . '></script>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
