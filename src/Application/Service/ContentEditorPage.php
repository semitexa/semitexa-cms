<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Core\Attribute\AsService;

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
        foreach ($draft->fields as $field) {
            $fields .= $this->field($field);
        }

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

        $control = $field->kind === ContentField::LINE
            ? '<input type="text" name="' . $name . '" value="' . $value . '"' . $required . '>'
            : '<textarea name="' . $name . '"' . $required . '>' . $value . '</textarea>';

        return '<label>' . $label . $control . $hint . '</label>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
