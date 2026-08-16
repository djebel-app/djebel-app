# djebel-app TODOs

Framework-level changes the user has flagged but hasn't scheduled. Each entry
has a clear acceptance shape so the work can be picked up by anyone (or by
Claude in a future session) without re-deriving the design.

---

## 1. `Dj_App_Assets` — inline / external asset enqueue system

**Status:** designed, not implemented.
**Location-to-be:** `src/core/lib/assets.php`, registered in bootstrap.
**Filed:** plugin-side work in kolatati-web-app (May 2026) showed every plugin
that wants a `<script>` or `<style>` in the page rewrites the same
`str_ireplace('</body>', ..., $buff)` boilerplate against `app.page.full_content`.
A first-party enqueue API would centralize that.

### Why a system at all (not just hooks)

Right now every plugin must:
- register its own filter on `app.page.full_content`
- locate `</head>` / `</body>` itself
- dedupe its own emissions
- escape its own URLs

That's N copies of the same logic across plugins. A single `Dj_App_Assets`
class fixes it once and gives the framework a place to hang things like CSP
nonces, dependency ordering, and SRI hashes later without revisiting every
plugin.

### Requirements (per user direction)

- **Queue-based.** Multiple items can be enqueued from different plugins; the
  system holds them all until the page renders.
- **Priorities.** Each enqueued item has a numeric priority so plugins can
  influence render order without coordinating with each other. Default 10,
  lower number = earlier in the rendered block. Stable sort within the same
  priority (registration order).
- **IDs.** Each enqueued item gets a string ID (caller-supplied or auto-generated).
  Other code can `Dj_App_Assets::remove($id)` or `replace($id, ...)` it before
  render. This is the key reason for the queue — it lets later code override
  earlier additions.
- **Inline AND external.** Both `<script src="...">` / `<link rel="stylesheet" href="...">`
  files and inline `<script>...</script>` / `<style>...</style>` blocks.
- **With OR without wrapping tags.** Plugin can pass either:
  - `'console.log("x")'` → system wraps in `<script>...</script>`
  - `'<script defer>console.log("x")</script>'` → pass-through, system detects
    the existing tag and doesn't double-wrap.
  Detection: case-insensitive `stripos($content, '<script')` or
  `stripos($content, '<style')` at position 0 (ignoring leading whitespace).
- **Head vs footer targets.** Each item is tagged for one of:
  - `'head'` — injected before `</head>`
  - `'footer'` — injected before `</body>`
- **Dedup by content hash.** If two plugins enqueue identical content with no
  explicit ID, emit only once. With explicit IDs, last-write-wins on the same
  ID.

### Proposed API surface

```php
class Dj_App_Assets
{
    // Targets
    const TARGET_HEAD   = 'head';
    const TARGET_FOOTER = 'footer';

    // Asset kinds (informs default wrapping)
    const KIND_JS  = 'js';
    const KIND_CSS = 'css';

    // Add external file (URL). Returns the item id (auto or supplied).
    public static function addFile($params = [])
    // $params = [
    //     'id' => 'kolatati-main',       // optional; auto-generated if omitted
    //     'url' => '/dj-content/.../main.js',
    //     'kind' => self::KIND_JS,       // or KIND_CSS
    //     'target' => self::TARGET_FOOTER,
    //     'priority' => 10,              // default 10
    //     'attrs' => [ 'defer' => true, ], // extra HTML attrs on the tag
    // ];

    // Add inline content. Detects existing tag wrapper; wraps if absent.
    public static function addInline($params = [])
    // $params = [
    //     'id' => 'kolatati-config',
    //     'content' => 'var cfg = {...};', // OR full '<script>...</script>'
    //     'kind' => self::KIND_JS,
    //     'target' => self::TARGET_HEAD,
    //     'priority' => 10,
    // ];

    // Remove a queued item by id (no-op if not queued).
    public static function remove($id)

    // Replace a queued item (shortcut for remove + add).
    public static function replace($id, $params)

    // Internal: install the app.page.full_content filter once.
    protected static function installHooks()

    // Internal: the actual injector. Builds head block + footer block,
    // str_ireplace's them in. Dedupes by content hash.
    public static function injectIntoPage($buff)
}
```

### Plugin-side usage example

```php
// In a plugin's bootstrap:
Dj_App_Assets::addFile([
    'id' => 'kolatati-main-js',
    'url' => Dj_App_Util::getContentDirUrl() . '/plugins/kolatati-web-app/assets/main.js',
    'kind' => Dj_App_Assets::KIND_JS,
    'target' => Dj_App_Assets::TARGET_FOOTER,
    'attrs' => [ 'defer' => true, ],
]);

// Another plugin wants to disable the kolatati script for some reason:
Dj_App_Assets::remove('kolatati-main-js');

// Or swap it for a forked copy:
Dj_App_Assets::replace('kolatati-main-js', [
    'url' => '/dj-content/plugins/other/forked-main.js',
    ...
]);
```

### Rendering rules (the injector)

Inside `injectIntoPage($buff)`:

1. Cheap-checks: empty buffer or no `</head>` and no `</body>` → return as-is.
2. Build each target block:
   - Sort the queue by priority asc, then registration order.
   - For each item, render the tag:
     - File + JS → `sprintf('<script src="%s"%s></script>', $url_esc, $attrs_str)`
     - File + CSS → `sprintf('<link rel="stylesheet" href="%s"%s>', $url_esc, $attrs_str)`
     - Inline + JS without wrapper → `sprintf('<script>%s</script>', $content)`
     - Inline + CSS without wrapper → `sprintf('<style>%s</style>', $content)`
     - Inline with existing wrapper → emit `$content` as-is.
   - Concatenate with `\n` for diff-friendliness.
3. `str_ireplace('</head>', $head_block . '</head>', $buff)` if head block non-empty.
4. Same for `</body>` with footer block.
5. Return modified buffer.

Use `sprintf()` for ALL tag assembly (per CLAUDE.md §8b "HTML / CSS / JS string
assembly — sprintf() for one-liners"). NEVER `.` concatenation.

### Cache busting

When adding a file via `addFile`, optionally accept `'version' => $val`. If
omitted AND the URL maps to a local filesystem file under
`getContentDir()`, auto-append `?v=<filemtime>`. Same pattern as
`autoCorrectAssetLinks` in `themes.php`.

### CSP / nonce hook (future, non-blocking)

Reserve space for a `app.assets.nonce` filter so a future CSP-nonce plugin
can stamp `nonce="..."` on every emitted inline tag automatically. Don't
implement now — just don't paint into a corner that prevents adding it.

### Tests

`tests/unit_tests/lib/Assets_Test.php`:
- `addFile` + render → exact tag emitted with correct URL/attrs
- `addInline` without wrapper → wrapped tag
- `addInline` with wrapper → pass-through
- Priority ordering
- Stable sort within same priority
- `remove` works mid-pipeline
- `replace` swaps content but preserves position
- Dedup by content hash (no explicit ID)
- Head vs footer routing
- Empty queue → buffer untouched
- No `</head>` / `</body>` → relevant block skipped, no errors

### Out of scope (for v1)

- Dependency graph (`'deps' => ['jquery', ...]`) — add later if needed
- Async/preload `<link rel="preload">` — let plugins pass `'attrs' => [ 'rel' => 'preload', ]`
- SRI hashes — could be a separate filter
- WP-style "localize_script" data passing — plugin can `addInline` a small
  `<script>var foo = {...};</script>` block before the file tag using priority

### Acceptance criteria

- [ ] `src/core/lib/assets.php` exists with the API above
- [ ] One filter registered on `app.page.full_content` (priority default; runs
      after `autoCorrectAssetLinks` so URLs are already resolved)
- [ ] kolatati-web-app plugin's `injectAssets()` method is replaced with a
      single `Dj_App_Assets::addFile([...])` call at bootstrap time
- [ ] Unit tests in `tests/unit_tests/lib/Assets_Test.php`, all green
- [ ] Documentation in `.claude/prompts/djebel-coding-guide.md` showing the
      plugin-side enqueue pattern + cross-reference from CLAUDE.md §8b

---

## 2. `Dj_App_I18n` — core string translation layer

**Status:** flagged, to discuss (NOT designed yet — user deferred the design).
**Location-to-be:** `src/core/lib/i18n.php`, registered in bootstrap.
**Filed:** translating the `svetlio` site to native Bulgarian (June 2026) showed
the framework has **no i18n/string API** — every plugin and theme hardcodes
English UI strings. The only localization mechanisms today are the `djebel-lang`
URL-routing plugin (`/en/`, `/bg/`) and ad-hoc per-string shortcode attributes.

### Why a system at all

With no central layer, the only way to localize a plugin's hardcoded UI text is
to add a shortcode attribute per string (English default) and pass the
translation from the site. That was the interim fix for svetlio:
- contact plugin → added `email_placeholder`, `message_placeholder`, `submit_text`
- `djebel-simple-newsletter` → added `email_placeholder`, `submit_text`,
  `code_placeholder`, `verify_text`

This doesn't scale: N attributes per plugin, repeated at every call site, and it
can't reach strings that aren't shortcode-rendered. Still unsolved for svetlio:
both plugins' **post-submit validation/success messages**, and the **core footer**
("Powered by Djebel", "All rights reserved." in `src/core/lib/shortcode.php`).

### Proposed direction (to refine when scheduled)

- A small `Dj_App_I18n::t('key', 'English default')` helper that plugins call for
  any user-facing string. Returns the English default when no translation is
  loaded — zero config cost, English keeps working, never warns.
- Per-locale string source: a flat file like `.ht_djebel/conf/lang/bg.ini`
  (`key = превод`) loaded for the current locale, and/or an `app.i18n.translate`
  filter so sites/plugins can override programmatically.
- Locale resolution reuses the existing `djebel-lang` current-language signal
  where present; falls back to a site config key otherwise.
- Fits the existing `cfg()` / `Dj_App_Options` / hooks patterns — no new
  paradigm. Must emit zero warnings when a key or locale file is missing.

### Acceptance criteria (rough)

- [ ] `src/core/lib/i18n.php` with `Dj_App_I18n::t()` + locale/file loading
- [ ] Retrofit the contact + newsletter plugins (and the core footer) to call
      `t()` instead of hardcoded English / per-string shortcode attrs
- [ ] `bg.ini` example + docs in `.claude/prompts/djebel-coding-guide.md`
- [ ] Unit tests: key hit, missing key → default, missing locale file → defaults,
      filter override, zero warnings throughout

---

## 3. Rename path-carrying core APIs (no "path" naming rule)

**Status:** scoped 2026-07-27; deferred — many site files need the migration sweep.
**Rule:** identifiers never carry the word "path" (`_file` / `_dir` / `_url`
suffixes carry the type). Plugins were swept clean 2026-07-27 (static-content,
lang); core public API still carries it.

### The three methods + measured blast radius (excl. _backup_plugins)

| Current | Proposed | Call sites in app/sites/* | Core refs |
|---|---|---|---|
| `Dj_App_Request::getWebPath()` | `getBaseUrl()` | 15 calls / 15 files | 7 |
| `Dj_App_Request::getRelWebPath()` | `getRelUrl()` | 16 calls / 14 files | 0 |
| `Dj_App_File_Util::normalizePath()` | `normalizeSlashes()` | 27 calls / 9 files | 3 |

### Naming rationale

- `normalizePath()` handles BOTH files and dirs, so `_file`/`_dir` suffixes
  can't apply — name it by what it DOES: converts `\` to `/`, collapses
  duplicate slashes, trims, strips the trailing slash. `normalizeSlashes()`
  joins the existing `removeSlash()` / `addSlash()` family in core.
- `getWebPath()` returns the site's base URL prefix -> `getBaseUrl()`;
  `getRelWebPath()` -> `getRelUrl()`. Matches the `$base_url` / `$rel_url`
  variable names the lang + static-content plugins adopted in their
  2026-07-27 rename sweep.
- NOT in scope: hook ids (`app.core.request.web_path`, `app.core.request.detect_web_path`,
  `app.core.request.content_web_path`) and the `__SITE_WEB_PATH__` /
  `__SITE_CONTENT_WEB_PATH__` magic vars — those are wire contracts in every
  site's content/config; renaming them is a separate, bigger decision.

### Migration plan

- [ ] Core: add the new method names; old names become thin deprecated
      forwarders (BC shims are allowed for migration — remove after all sites
      migrate)
- [ ] Sweep app/sites/* call sites per site repo (~58 calls), each site
      commits on its own schedule thanks to submodule/pin isolation
- [ ] Update the docs that cite the old names (root `.claude/coding-guidelines.md`
      lists `normalizePath`; `docs/developers/*`, this repo's `.claude/CLAUDE.md`)
- [ ] Unit tests renamed/extended alongside (`File_Util_Test`, `Request_Test`)
- [ ] Remove the forwarders once `grep -rn "getWebPath\|getRelWebPath\|normalizePath" app/sites`
      is zero

---

## 4. Move `replaceTags` to `Dj_App_String_Util`

**Status:** [P30] needs the owner — it is a deprecation across repos.
**Location:** `src/core/lib/util.php` (`Dj_App_Util::replaceTags`).
**Filed:** 2026-08-16.

`Dj_App_Util::replaceTags` is the framework's tag helper — bare keys,
`{tag}` + `%tag%` + `%%tag%%`, case-insensitive. The duplicate that used to sit
beside it in `string_util.php` (`replaceMergeTags`, a strict subset with no
callers) was deleted on 2026-08-16, so there is now exactly one.

### The move

It is string manipulation, so the string util is its home. It is NOT core-only,
so this cannot be a plain rename:

- **core** — 4 in `util.php` (the definition + 3 uses in `msg()`), 12 in `Util_Test.php`
- **sites** — 21 calls across 9 sites (djebel, djebel-live, fsite, influencers,
  monitor, oterm, slavi, svetlio, wpblogger), from THREE plugins that are each
  their own git repo: `djebel-seo`, `djebel-contact-log-csv`,
  `djebel-simple-newsletter`

Renaming in place fatals every site whose core updates before its plugins do.
The migration shape (same as section 3, worth running together):

- [ ] `Dj_App_String_Util::replaceTags()` gains the implementation
- [ ] `Dj_App_Util::replaceTags()` becomes a thin forwarder — a deprecation, so
      it needs the owner's approval first
- [ ] The 3 plugin repos move to the new name on their own schedule
- [ ] Forwarder removed once `grep -rn "Dj_App_Util::replaceTags" app/sites` is zero
- [ ] `Util_Test` cases move to `String_Util_Test` with the method

### Worth knowing

`replaceTags` handles an ordering trap: `%%TAG%%` CONTAINS `%TAG%`, so the
double form must be queued first or the single form eats its middle and leaves
stray `%`. Its docblock explains this — preserve it through any move.

### Acceptance

- [ ] One tag helper, in the class its name implies
- [ ] `grep -rn "Dj_App_Util::replaceTags" app/sites src` is empty
