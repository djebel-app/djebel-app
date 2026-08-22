# djebel-app TODOs

Framework-level changes the user has flagged but hasn't scheduled. Each entry
has a clear acceptance shape so the work can be picked up by anyone (or by
Claude in a future session) without re-deriving the design.

---

## 1. `Dj_App_Assets` — inline / external asset enqueue system

**Status:** implemented 2026-08-21. **The design lives in
[`.claude/prd/assets.md`](prd/assets.md)** — read that, not this entry.

Superseded here on 2026-08-21: the design outgrew a TODO entry and the two would
have drifted. The PRD replaces the old `addFile()` / `addInline()` split with one
auto-detecting `add()`, adds plugin-relative file resolution, `getContentUrl()`,
a hook surface at both ends, and records the pipeline facts (which seam to inject
on, why non-public plugins must auto-inline) so the implementing session does not
re-derive them.

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
