<?php

declare(strict_types=1);

namespace Semitexa\Cms\Application\Service;

use Semitexa\Cms\Domain\Model\ContentDraft;
use Semitexa\Cms\Domain\Model\ContentField;
use Semitexa\Cms\Domain\Model\ContentRows;
use Semitexa\Cms\Domain\Model\ContentSeo;
use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
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
    /**
     * What the page says about itself to a search engine, and which of it a
     * person claimed.
     *
     * Read here rather than threaded through every caller: four handlers render
     * this page and none of them has anything else to do with metadata. Absent
     * under a bare `new`, which is how the unit tests build it — then the
     * section is simply not offered, which is the right answer for an
     * installation whose CMS store is not wired.
     */
    #[InjectAsReadonly]
    protected SeoStore $seoStore;

    /**
     * The metadata fields the panel offers, in the order an author reads them.
     *
     * `jsonLd` is generated but deliberately absent: it is a graph for machines,
     * and a textarea of raw JSON in a content console is a way to store
     * something no crawler can parse. It regenerates freely and nobody has to
     * look at it.
     *
     * @var array<string, array{0: string, 1: string}> field => [label, hint]
     */
    private const SEO_FIELDS = [
        'title' => ['Заголовок у пошуку', ''],
        'description' => ['Опис у пошуку', 'Приблизно 160 символів — далі Google обрізає.'],
        'ogTitle' => ['Заголовок у соцмережах', ''],
        'ogDescription' => ['Опис у соцмережах', ''],
        'ogImage' => ['Картинка для соцмереж', 'Адреса зображення, яке показують при поширенні.'],
        'canonical' => ['Канонічна адреса', 'Головна адреса сторінки, якщо їх кілька.'],
        'robots' => ['Індексація', 'Наприклад index,follow або noindex.'],
    ];

    /**
     * @param ?string $warning something the save took away, when it did. Shown
     *                         in place of «Збережено.» rather than beside it:
     *                         one notice line, and the thing worth reading is
     *                         the loss, not the success it happened under.
     */
    public function render(
        ContentDraft $draft,
        string $csrfToken,
        ?string $savedMessage = null,
        ?string $error = null,
        ?string $warning = null,
    ): string {
        // Editing a page is writing, not filling in a form. So the draft is
        // split by what the author is actually doing: the name of the thing,
        // the thing itself, and the settings about it. Only the first two are
        // on the canvas; everything else waits behind «Властивості» until it
        // is asked for. A flat list of equal-weight fields makes the author
        // decide, on every visit, which of them today's work is about.
        [$titleField, $bodyField, $rest] = self::split($draft->fields);

        $rich = $bodyField !== null && $bodyField->kind === ContentField::HTML;
        $richHead = $rich ? $this->richEditorHead() : '';
        $shellJs = $this->escape(AssetManager::getUrl('js/content-shell.js', 'cms'));

        $title = $this->escape($draft->title);
        $ref = $this->escape($draft->ref);
        $token = $this->escape($csrfToken);

        $notice = '';
        if ($error !== null) {
            $notice = '<p class="notice notice--bad" role="alert">' . $this->escape($error) . '</p>';
        } elseif ($warning !== null) {
            // role="alert" like the error, not like the success: this one has
            // to reach an author who is already reaching for the close button.
            $notice = '<p class="notice notice--warn" role="alert">' . $this->escape($warning) . '</p>';
        } elseif ($savedMessage !== null) {
            $notice = '<p class="notice notice--ok">' . $this->escape($savedMessage) . '</p>';
        }

        $view = $draft->publicUrl === null || $draft->publicUrl === ''
            ? ''
            : '<a class="ghost" href="' . $this->escape($draft->publicUrl) . '" target="_blank" rel="noopener">Подивитись&nbsp;↗</a>';

        $titleControl = $titleField === null
            ? ''
            : '<input class="doctitle" type="text" name="' . $this->escape($titleField->name) . '"'
                . ' value="' . $this->escape($titleField->value) . '"'
                . ' placeholder="' . $this->escape($titleField->label) . '"'
                . ' aria-label="' . $this->escape($titleField->label) . '"'
                . ($titleField->required ? ' required' : '') . ' autocomplete="off">';

        $bodyControl = '';
        if ($bodyField !== null) {
            $bodyControl = '<div class="writing">'
                . ($rich
                    ? $this->richControl(
                        $this->escape($bodyField->name),
                        $this->escape($bodyField->value),
                        $bodyField->required ? ' required' : '',
                        self::positionOf($draft->fields, $bodyField),
                    )
                    : '<textarea class="plain" name="' . $this->escape($bodyField->name) . '"'
                        . ($bodyField->required ? ' required' : '') . '>' . $this->escape($bodyField->value) . '</textarea>')
                . '</div>';
        }

        // The panel exists only when something belongs in it; a button that
        // opens an empty drawer is worse than no button.
        $panelFields = '';
        foreach ($rest as $field) {
            $panelFields .= $this->field($field, self::positionOf($draft->fields, $field));
        }
        $panelFields .= $this->seoSection($draft->ref);

        $panel = '';
        $panelButton = '';
        if ($panelFields !== '') {
            $panel = '<aside class="panel" id="panel" aria-label="Властивості">'
                . '<div class="panel__head"><span>Властивості</span>'
                . '<button class="icon" type="button" data-act="props" aria-label="Закрити">×</button></div>'
                . '<div class="panel__body">' . $panelFields . '</div></aside>';
            $panelButton = '<button class="ghost" type="button" data-act="props" aria-expanded="false" aria-controls="panel">Властивості</button>';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="uk"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$title}</title>
{$richHead}
<style>
  *{box-sizing:border-box} html,body{margin:0;height:100%}
  body{font-family:ui-sans-serif,system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);
       font-size:14px;-webkit-font-smoothing:antialiased}
  .doc{display:flex;flex-direction:column;height:100%}

  /* --- the bar that never scrolls away: where you are, whether your work is
         safe, and the two things you might want next --- */
  .top{display:flex;align-items:center;gap:12px;flex:0 0 auto;padding:0 14px;height:46px;
       border-bottom:1px solid rgba(var(--line-rgb),.16);background:var(--bg)}
  .top__acts{margin-left:auto;display:flex;align-items:center;gap:8px}
  .state{font-size:12px;color:var(--dim);display:inline-flex;align-items:center;gap:6px;white-space:nowrap}
  .state::before{content:"";width:7px;height:7px;border-radius:50%;background:var(--ok);flex:none}
  .state[data-state="dirty"]{color:var(--warn)} .state[data-state="dirty"]::before{background:var(--warn)}
  .state[data-state="saving"]{color:var(--dim)} .state[data-state="saving"]::before{background:var(--dim)}
  button,.ghost{font-family:inherit;font-size:13px;border-radius:8px;cursor:pointer}
  .ghost{padding:7px 12px;border:1px solid rgba(var(--line-rgb),.22);background:transparent;color:var(--text);
         text-decoration:none;white-space:nowrap;transition:background 120ms,border-color 120ms}
  .ghost:hover{background:rgba(var(--line-rgb),.12);border-color:rgba(var(--line-rgb),.36)}
  .primary{padding:8px 16px;border:none;background:var(--accent);color:#04121f;font-weight:600}
  .primary:hover{filter:brightness(1.08)}
  .icon{border:none;background:transparent;color:var(--mute);font-size:18px;line-height:1;padding:2px 6px}
  .icon:hover{color:var(--strong)}

  .notice{margin:0;padding:9px 16px;font-size:13px;flex:0 0 auto}
  .notice--ok{color:var(--ok);background:rgba(94,234,212,.08)}
  .notice--bad{color:var(--danger);background:rgba(255,107,130,.10)}
  .notice--warn{color:var(--warn);background:rgba(245,196,81,.12)}

  /* --- metadata: what the page says about itself, and who said it --- */
  .seo{margin-top:18px;padding-top:14px;border-top:1px solid rgba(var(--line-rgb),.16)}
  .seo__head{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin-bottom:6px}
  .seo__field{display:block;margin-top:12px}
  .seo__label{display:flex;align-items:center;gap:8px;font-size:13px;color:var(--mute);margin-bottom:4px}
  .tag{font-size:10px;text-transform:uppercase;letter-spacing:.05em;padding:2px 6px;border-radius:999px;
       background:rgba(var(--line-rgb),.16);color:var(--dim)}
  .tag--mine{background:rgba(var(--accent-rgb),.18);color:var(--accent)}
  .tag--none{background:transparent;border:1px dashed rgba(var(--line-rgb),.3)}

  .body{flex:1;min-height:0;display:flex}

  /* --- the canvas: one column, the width of something readable --- */
  .canvas{flex:1;min-width:0;overflow:auto;padding:28px 32px 64px}
  .canvas>*{max-width:760px;margin-inline:auto}
  .doctitle{display:block;width:100%;border:none;background:transparent;color:var(--strong);
    font-family:inherit;font-size:29px;font-weight:650;letter-spacing:-.02em;line-height:1.25;padding:0 0 10px}
  .doctitle::placeholder{color:var(--dim)}
  .doctitle:focus{outline:none}
  .writing{margin-top:6px}
  textarea.plain{width:100%;min-height:60vh;padding:0;border:none;background:transparent;color:var(--text);
    font-family:inherit;font-size:16px;line-height:1.7;resize:none}
  textarea.plain:focus{outline:none}

  /* --- properties: present, but not in the way --- */
  .panel{flex:0 0 320px;border-left:1px solid rgba(var(--line-rgb),.16);background:rgba(var(--ink-rgb),.35);
         display:none;flex-direction:column;min-height:0}
  body.props .panel{display:flex}
  .panel__head{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:12px 14px;
    font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--dim);
    border-bottom:1px solid rgba(var(--line-rgb),.14)}
  .panel__body{flex:1;overflow:auto;padding:14px;display:grid;gap:16px;align-content:start}
  .panel label,.panel .field{display:grid;gap:6px;font-size:12px;color:var(--mute)}
  .panel input,.panel textarea{width:100%;padding:9px 11px;border-radius:8px;
    border:1px solid rgba(var(--line-rgb),.25);background:rgba(var(--ink-rgb),.6);color:var(--strong);
    font-size:13px;font-family:inherit}
  .panel textarea{min-height:88px;line-height:1.55;resize:vertical}
  .panel input:focus,.panel textarea:focus{outline:none;border-color:var(--accent);
    box-shadow:0 0 0 3px rgba(var(--accent-rgb),.18)}
  .hint{font-size:11px;color:var(--dim);line-height:1.5}
  .cover__frame{display:grid;place-items:center;min-height:104px;border-radius:8px;overflow:hidden;
                border:1px dashed rgba(148,163,184,.35);background:rgba(148,163,184,.06)}
  .cover__img{display:block;width:100%;max-height:220px;object-fit:cover}
  .cover__empty{font-size:11px;color:var(--dim)}
  .cover__acts{display:flex;gap:8px;align-items:center}
  .cover__pick,.cover__clear{font:inherit;font-size:11px;padding:5px 10px;border-radius:6px;cursor:pointer;
                             border:1px solid rgba(148,163,184,.35);background:transparent;color:var(--text)}
  /* Reachable by keyboard, invisible to the eye — `hidden` would remove it from
     the tab order and the label is not focusable in its place. */
  .cover__file{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;
               clip:rect(0 0 0 0);white-space:nowrap;border:0}
  .cover__pick{position:relative}
  .cover__pick:focus-within{outline:2px solid rgba(148,163,184,.7);outline-offset:2px}
  .cover__clear{border-color:rgba(248,113,113,.4);color:#fca5a5}
  .cover__err{font-size:11px;color:#fca5a5}
  .tool{display:grid;gap:8px;font-size:12px;color:var(--mute);padding-top:4px;
        border-top:1px solid rgba(var(--line-rgb),.14)}
  .tool .ghost{justify-self:start}

  @media (max-width:820px){
    /* Offset 0, not the 46px of .top: the containing block is .body, which
       already begins below the bar, so a 46px inset counts its height twice
       and opens a gap the canvas shows through. */
    .panel{position:absolute;inset:0 0 0 auto;width:min(340px,86vw);z-index:5;background:var(--bg);
           box-shadow:-24px 0 48px rgba(2,8,23,.5)}
    .body{position:relative}
  }

  :root{color-scheme:dark;--bg:#0f172a;--text:#dbe7ff;--strong:#eaf2ff;--mute:#a8b4cc;--dim:#6f7d99;
    --line-rgb:148,163,184;--ink-rgb:2,8,23;--accent:#37b7ff;--accent-rgb:55,183,255;
    --ok:#5eead4;--warn:#f5c451;--danger:#ff6b82}
</style>
<script src="{$shellJs}" defer{$this->nonceAttr()}></script>
</head>
<body>
  <form class="doc" method="post" action="/os/app/cms/save" data-editor>
    <input type="hidden" name="ref" value="{$ref}">
    <input type="hidden" name="_csrf" value="{$token}">

    <header class="top">
      <span class="state" id="state" data-state="clean" aria-live="polite">Збережено</span>
      <span class="top__acts">{$view}{$panelButton}<button class="primary" type="submit">Зберегти</button></span>
    </header>
    {$notice}

    <div class="body">
      <main class="canvas">
        {$titleControl}
        {$bodyControl}
      </main>
      {$panel}
    </div>
  </form>
</body></html>
HTML;
    }

    /**
     * Split a draft into the name, the thing, and everything else.
     *
     * The rule is about kinds, not names, so it holds for any editor: the
     * first single-line field is what the record is called, the first rich
     * field — or failing that the first long-text one — is the record itself.
     * A draft that fits neither shape simply puts everything in the panel,
     * which is still a readable screen rather than a broken one.
     *
     * @param  list<ContentField> $fields
     * @return array{0: ?ContentField, 1: ?ContentField, 2: list<ContentField>}
     */
    private static function split(array $fields): array
    {
        $title = null;
        $body = null;

        foreach ($fields as $field) {
            if ($title === null && $field->kind === ContentField::LINE) {
                $title = $field;
                continue;
            }
            if ($body === null && $field->kind === ContentField::HTML) {
                $body = $field;
            }
        }

        if ($body === null) {
            foreach ($fields as $field) {
                if ($field !== $title && $field->kind === ContentField::TEXT) {
                    $body = $field;
                    break;
                }
            }
        }

        $rest = [];
        foreach ($fields as $field) {
            if ($field !== $title && $field !== $body) {
                $rest[] = $field;
            }
        }

        return [$title, $body, $rest];
    }

    /**
     * Where a field sits in the draft — the number {@see richControl()} folds
     * into its element id, which must stay unique across the whole draft even
     * though the fields are now rendered in three separate places.
     *
     * @param list<ContentField> $fields
     */
    private static function positionOf(array $fields, ContentField $needle): int
    {
        foreach ($fields as $position => $field) {
            if ($field === $needle) {
                return (int) $position;
            }
        }

        return 0;
    }

    private function nonceAttr(): string
    {
        return ScriptNonceSource::attribute();
    }

    /**
     * The list behind a collection.
     *
     * A row is a link to its own editor and nothing else — no inline editing,
     * no bulk actions. A grid whose rows are half-editable is a grid where it
     * is never clear which half you are in.
     */

    /**
     * One field of the properties panel.
     *
     * `required` is announced but not enforced here, for the same reason
     * {@see richControl()} does not enforce it: the browser cannot report a
     * violation it is unable to show. This panel is `display:none` until the
     * author opens it, and a hidden control is not focusable — so the browser
     * refuses the submit, declines to focus anything, and says nothing. The
     * Зберегти button simply stops working, with no message anywhere.
     *
     * MEASURED in Chrome on the rendered markup: with the panel closed,
     * `form.reportValidity()` is false and `document.activeElement` is
     * unchanged; with it open, the same call moves focus onto the offending
     * field and shows its bubble. Only the second is a usable error.
     *
     * So `aria-required` carries the fact to assistive technology, and
     * {@see ContentSaveHandler::missingRequired()} — which already walks every
     * field of the draft, not just the rich one, and names the empty field in
     * its message — stays the single gate. It has to be, regardless: a form can
     * be posted without ever loading this page.
     *
     * The canvas keeps real `required` on its title input, because that control
     * is always visible and the browser can point at it.
     */
    /**
     * The page's metadata, with its provenance visible by construction.
     *
     * The CMS writes this from the page's own text, and the promise it makes is
     * that a person's decision is never overwritten. That promise is only real
     * if an author can SEE which values are theirs — so the box shows what it
     * shows for a reason:
     *
     *   * a value a person claimed is IN the field, as a value;
     *   * a generated value is the PLACEHOLDER, grey, with the field empty.
     *
     * That is not decoration, it is the mechanism. The form posts every field
     * on every save, and {@see ContentSeo::withAuthored()} reads a non-empty
     * value as a claim — so rendering generated text as a value would make the
     * first unrelated save claim all of it and switch the generator off for
     * good. Empty means "still the machine's", which is exactly what it is.
     * Clearing a field you own hands it back, and the placeholder returning is
     * how you know it worked.
     *
     * Empty string when the store is not wired: an editor cannot offer to keep
     * metadata it has nowhere to put.
     */
    private function seoSection(string $ref): string
    {
        if (!isset($this->seoStore) || trim($ref) === '') {
            return '';
        }

        try {
            $seo = $this->seoStore->get($ref);
        } catch (\Throwable) {
            // A console that will not open because the metadata table is not
            // there yet is worse than a console without the section.
            return '';
        }

        $fields = '';
        foreach (self::SEO_FIELDS as $name => [$label, $hint]) {
            $fields .= $this->seoField($seo, $name, $label, $hint);
        }

        return '<div class="seo"><div class="seo__head">Для пошуку і соцмереж</div>'
            . '<p class="hint">Порожнє поле CMS заповнює сама, з тексту сторінки — сіре нижче саме звідти.'
            . ' Напишіть своє, і воно перестане оновлюватись; зітріть — і повернеться підказка.</p>'
            . $fields . '</div>';
    }

    private function seoField(ContentSeo $seo, string $name, string $label, string $hint): string
    {
        $authored = $seo->isAuthored($name);
        $value = $seo->{$name};
        $generatable = in_array($name, ContentSeo::GENERATED_FIELDS, true);

        $tag = match (true) {
            $authored => '<span class="tag tag--mine">ваше</span>',
            !$generatable => '',
            $value !== '' => '<span class="tag">згенеровано</span>',
            default => '<span class="tag tag--none">ще не згенеровано</span>',
        };

        // A generated value is a placeholder, never a value — see seoSection().
        // A field nothing generates (an address, an indexing rule) has no
        // second provenance to confuse and carries its value plainly.
        $shown = $authored || !$generatable ? $value : '';
        $placeholder = !$authored && $generatable && $value !== ''
            ? ' placeholder="' . $this->escape($value) . '"'
            : '';

        $longer = in_array($name, ['description', 'ogDescription'], true);
        $control = $longer
            ? '<textarea name="seo[' . $this->escape($name) . ']" rows="3"' . $placeholder . '>'
                . $this->escape($shown) . '</textarea>'
            : '<input type="text" name="seo[' . $this->escape($name) . ']" value="'
                . $this->escape($shown) . '"' . $placeholder . '>';

        return '<label class="seo__field"><span class="seo__label">' . $this->escape($label) . $tag . '</span>'
            . $control
            . ($hint === '' ? '' : '<span class="hint">' . $this->escape($hint) . '</span>')
            . '</label>';
    }

    private function field(ContentField $field, int $position): string
    {
        $name = $this->escape($field->name);
        $label = $this->escape($field->label);
        $value = $this->escape($field->value);
        $required = $field->required ? ' aria-required="true"' : '';
        $hint = $field->hint === '' ? '' : '<span class="hint">' . $this->escape($field->hint) . '</span>';

        $control = match ($field->kind) {
            ContentField::LINE => '<input type="text" name="' . $name . '" value="' . $value . '"' . $required . '>',
            // The browser's own picker: it formats the day in the reader's
            // locale and submits ISO regardless, so the console needs no
            // calendar of its own. platform-ui HAS one — platform.date-field —
            // but it renders through the component runtime, and this page is
            // standalone HTML in an iframe with neither Twig nor that runtime.
            // Pulling the kit in for one field would cost the page its
            // independence to gain a picker the platform already provides.
            ContentField::DATE => '<input type="date" name="' . $name . '" value="' . $value . '"' . $required . '>',
            ContentField::HTML => $this->richControl($name, $value, $required, $position),
            // The RAW id: imageControl() escapes it itself, and escaping twice
            // posts 'a&b' back as 'a&amp;b' — the value changes on every save.
            ContentField::IMAGE => $this->imageControl($name, $field->previewUrl(), $field->value, $required),
            default => '<textarea name="' . $name . '"' . $required . '>' . $value . '</textarea>',
        };

        // A <label> forwards a click anywhere inside it to the first labelable
        // control it contains — and Trix inserts its toolbar INTO this wrapper,
        // ahead of the editor. Inside a label that makes the toolbar's first
        // button the labelled control, so clicking the editor body pressed
        // «Bold» instead of placing the caret. The rich field gets a plain
        // wrapper; the others keep the label they are correctly paired with.
        // Same reason as the rich field: the control an author clicks is not a
        // labelable element, so a <label> would forward the click somewhere
        // surprising.
        if ($field->kind === ContentField::HTML || $field->kind === ContentField::IMAGE) {
            return '<div class="field"><span>' . $label . '</span>' . $control . $hint . '</div>';
        }

        return '<label>' . $label . $control . $hint . '</label>';
    }

    /**
     * The cover control: a preview, a file input, and a way to take it off.
     *
     * The hidden input is what posts — it carries the ASSET ID, and clearing
     * sets it to the empty string rather than removing it, because a field that
     * vanishes from the post is a field the module cannot tell apart from one
     * nobody touched. That is the difference between "leave the picture" and
     * "take the picture off", and the contract says an image field arrives
     * empty when cleared.
     *
     * The file input uploads through the route that already exists and hands
     * back an id; nothing about the storage layout reaches this page.
     */
    private function imageControl(string $name, string $previewUrl, string $assetId, string $required): string
    {
        $has = $assetId !== '';
        $preview = '<img class="cover__img" src="' . $previewUrl . '" alt=""' . ($has ? '' : ' hidden') . '>';
        $empty = '<span class="cover__empty"' . ($has ? ' hidden' : '') . '>Немає зображення</span>';

        return '<div class="cover" data-cover="' . $name . '">'
            . '<input type="hidden" name="' . $name . '" value="' . $this->escape($assetId) . '"' . $required . '>'
            . '<div class="cover__frame">' . $preview . $empty . '</div>'
            . '<div class="cover__acts">'
            // Visually hidden rather than `hidden`: the attribute takes the input
            // out of the tab order, and the label around it is not focusable
            // either, so a keyboard-only author could not choose an image at all.
            . '<label class="cover__pick"><input type="file" accept="image/*" class="cover__file">'
            . '<span>' . ($has ? 'Замінити' : 'Вибрати зображення') . '</span></label>'
            . '<button type="button" class="cover__clear"' . ($has ? '' : ' hidden') . '>Прибрати</button>'
            . '</div>'
            . '<span class="cover__err" hidden></span>'
            . '</div>';
    }

    /**
     * The rich-text control: a hidden input Trix reads from and writes back to.
     *
     * The input carries the value in both directions, so the field posts under
     * its own name exactly as the textarea did — nothing downstream learns that
     * the control changed.
     *
     * `required` is carried here for the record, not for enforcement: a hidden
     * input is barred from constraint validation exactly as <trix-editor> is,
     * so the browser checks neither. ContentSaveHandler is what actually
     * refuses an empty required field, which is also the only place that can —
     * a form can be posted without ever loading this page.
     */
    private function richControl(string $escapedName, string $escapedValue, string $required, int $position): string
    {
        // The id must be UNIQUE, not merely valid: Trix resolves its `input`
        // attribute with getElementById, so two editors sharing an id would
        // both write into the first hidden input and one field would silently
        // overwrite the other. Field names are author-facing keys and nothing
        // stops two of them slugging the same way («body.title», «body:title»),
        // so the position — which cannot repeat within a draft — carries the
        // uniqueness and the slug is only there to keep the id readable.
        $id = 'rich-' . $position . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $escapedName);

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
  /* The toolbar rides with the text rather than sitting in a box of its own:
     it is a property of the writing surface, not another field. */
  trix-toolbar{position:sticky;top:0;z-index:2;background:var(--bg);padding:2px 0 8px;margin-bottom:2px}
  trix-toolbar .trix-button-group{border:none;margin:0 10px 0 0}
  trix-toolbar .trix-button{background:transparent;border:none;width:30px;height:30px;border-radius:7px}
  trix-toolbar .trix-button:not(:disabled):hover{background:rgba(var(--line-rgb),.16)}
  trix-toolbar .trix-button.trix-active{background:rgba(var(--accent-rgb),.18)}
  trix-toolbar .trix-button:disabled{opacity:.25}
  /* Trix draws each icon as a dark SVG on ::before, sized for the light
     toolbar it ships with. On a dark surface that is dark on dark — the
     buttons look empty. Inverting the glyph is what makes them visible;
     without it the whole toolbar reads as broken. */
  trix-toolbar .trix-button--icon::before{filter:invert(1);opacity:.72}
  trix-toolbar .trix-button--icon:hover::before{opacity:1}
  trix-toolbar .trix-button.trix-active::before{opacity:1}
  trix-toolbar .trix-dialog{background:var(--bg);border:1px solid rgba(var(--line-rgb),.28);border-radius:10px;
    box-shadow:0 18px 44px rgba(2,8,23,.55)}
  trix-toolbar .trix-input--dialog{background:rgba(var(--ink-rgb),.6);border:1px solid rgba(var(--line-rgb),.25);
    color:var(--strong);border-radius:7px;padding:8px 10px;font-family:inherit}
  trix-toolbar .trix-button--dialog{background:var(--accent);color:#04121f;border-radius:7px;border:none;padding:7px 12px}

  /* The writing surface itself: no frame, because the frame is the window. */
  trix-editor.rich{min-height:58vh;line-height:1.7;font-size:16px;padding:0;border:none;background:transparent;
    color:var(--text)}
  trix-editor.rich:focus{outline:none;box-shadow:none}
  trix-editor.rich h1{font-size:22px;font-weight:650;letter-spacing:-.015em;color:var(--strong);margin:1.4em 0 .5em}
  trix-editor.rich a{color:var(--accent)}
  trix-editor.rich blockquote{border-left:2px solid rgba(var(--line-rgb),.4);margin:0;padding-left:14px;color:var(--mute)}
  trix-editor.rich ul,trix-editor.rich ol{padding-left:1.3em}
  trix-editor.rich img{max-width:100%;height:auto;border-radius:8px}
</style>
CSS;

        // Ours, loaded after the editor: it only binds trix-* listeners, and
        // those fire on events the bundle dispatches.
        $wiring = $this->escape(AssetManager::getUrl('js/content-editor.js', 'cms'));

        return $meta
            . '<link rel="stylesheet" href="' . $css . '">' . "\n"
            . $skin . "\n"
            . '<script src="' . $js . '" defer' . ScriptNonceSource::attribute() . '></script>' . "\n"
            . '<script src="' . $wiring . '" defer' . ScriptNonceSource::attribute() . '></script>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
