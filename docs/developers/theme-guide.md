# Djebel Theme Guide

The theme counterpart to [plugin-guide.md](plugin-guide.md). Themes follow the **same
tiered rule** — drop the redundant `theme` where the thing already sits inside a themes
container; keep the full `djebel-theme-` only where the identifier floats loose in a global
namespace.

## Naming Conventions (MANDATORY)

For a theme named `<name>` (e.g. `clear`):

### Tier 1 — Repo-context: `djebel-<name>`

These already live inside a themes container (a themes org, the `dj-content/themes/` dir,
the `data/app/themes/` dir, the `djebel.com/themes/` URL), so repeating `theme` is redundant.

| What | Form | Example |
|------|------|---------|
| repo / directory | `djebel-<name>` | `djebel-clear` |
| `theme_uri` slug | `https://djebel.com/themes/djebel-<name>` | `…/djebel-clear` |
| `app.ini` `theme_id` | `djebel-<name>` | `djebel-clear` |
| data subdir | `getCorePrivateDataDir(['theme' => 'djebel-<name>'])` | `…/themes/djebel-clear` |

### Tier 2 — Global namespace: `djebel-theme-<name>`

These float loose in a namespace shared with plugins, core, and the browser, so they carry
the **fully-described** prefix.

| What | Form | Example |
|------|------|---------|
| `text_domain` | `djebel-theme-<name>` | `djebel-theme-clear` |
| CSS / JS classes & ids | `djebel-theme-<name>-<descriptor>` | `djebel-theme-clear-site-header` |

### Reserved prefix — `dj-app-*`

`dj-app-*` is the **core framework** CSS prefix (e.g. `dj-app-menu-container`,
`dj-app-default-body`). Themes and plugins must **not** redefine those — use your own
`djebel-theme-<name>-*` / `djebel-plugin-<name>-*` classes and let the core ones be.

### CSS / id names must be DESCRIPTIVE

Name the element by its role, never by an abbreviation:

- ✅ `djebel-theme-clear-site-header`, `djebel-theme-clear-sidebar`, `djebel-theme-clear-nav`
- ❌ `djebel-theme-clear-h`, `djebel-theme-clear-s1`

## Private (`site`) themes

A site-specific, non-distributable theme inserts `site` the same way a private plugin does:
dir `djebel-site-<name>`, `text_domain` / CSS `djebel-site-theme-<name>`. (See the public vs
private section in [plugin-guide.md](plugin-guide.md).)

## Theme Header

The main file is `index.php` and opens with a header comment block. `theme_uri` is the
repo-context (Tier 1) form, `text_domain` the global (Tier 2) form:

```php
<?php
/*
theme_name: Clear
theme_uri: https://djebel.com/themes/djebel-clear
description: Clean, minimal blog theme. Large serif fonts, centered layout with sidebar.
version: 1.0.0
tags: minimal, clean, blog, serif, sidebar
stable_version: 1.0.0
min_php_ver: 5.6
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-theme-clear
*/
?>
```

## Magic vars & shortcodes

The theme markup uses core-replaced placeholders — see `replaceMagicVars()` in core
`src/core/lib/util.php` for the authoritative list — such as `__SITE_TITLE__`,
`__SITE_WEB_PATH__`, `__SITE_URL__`, and `__THEME_URL__`. Navigation and page content are
pulled in through shortcodes like `[djebel_page_nav]`, so a theme stays pure layout/markup
and never hard-codes content.

A theme may register its own shortcodes from `functions.php` (loaded when the site sets
`theme_load_functions = 1`) for composite blocks the markup can't express statically.
Gotcha: a shortcode renderer's OUTPUT is never re-scanned for shortcodes — don't emit
`[...]` tags from a renderer; keep plugin shortcodes literal in the template markup.

## Whole-buffer manipulation (`app.page.full_content`)

The ENTIRE rendered page passes through the `app.page.full_content` filter before output —
core itself rides it for shortcode replacement and magic-var / asset-link rewriting. When a
theme needs a site-specific tweak the static markup can't express, don't put PHP in the
template — add a focused listener in `functions.php` and change ONLY the specific case via
the filter. Templates stay static markup; the dynamic exception lives in one method.

Rules for a buffer filter:

- Cheapest checks first — early-return so the common case pays nearly nothing.
- Parse only the region you need (e.g. the first 100 chars for the `<html>` tag), then
  reassemble the buffer.
- Always return the buffer — unchanged on any no-match or `preg_*` error.

Example — the template ships static `<html lang="en">`; the theme class rewrites the
attribute only when the site's `[site] lang` config differs:

```php
// functions.php — inside the theme class's register()
Dj_App_Hooks::addFilter('app.page.full_content', [ $this, 'filterHtmlLang' ]);

public function filterHtmlLang($buff, $ctx = [])
{
    if (empty($buff)) {
        return $buff;
    }

    $options_obj = Dj_App_Options::getInstance();
    $site_lang = $options_obj->get('site.lang', 'en');

    // The theme markup already ships lang="en"
    if ($site_lang == 'en') {
        return $buff;
    }

    // The <html> tag sits at the very start — parse only the first 100 chars.
    $head_chunk = substr($buff, 0, 100);

    if (stripos($head_chunk, '<html') === false) {
        return $buff;
    }

    $site_lang_esc = Djebel_App_HTML::escAttr($site_lang);

    // Matches lang="..." inside the opening <html ...> tag; replaces only the value.
    $replacement = '${1}' . $site_lang_esc . '${2}';
    $new_head_chunk = preg_replace('#(<html\b[^>]*\blang=")[^"]*(")#i', $replacement, $head_chunk, 1);

    if (empty($new_head_chunk) || $new_head_chunk == $head_chunk) {
        return $buff;
    }

    $rest_chunk = substr($buff, 100);
    $buff = $new_head_chunk . $rest_chunk;

    return $buff;
}
```

## Legacy themes (migrate later)

The `clear` theme's `text_domain` is already on the Tier 2 form (`djebel-theme-clear`). Still
legacy and to migrate deliberately (not as a side effect of other work): the theme directory
and `app.ini` `theme_id` (`clear` → Tier 1 `djebel-clear`), and the `clear-*` CSS classes
(→ Tier 2 `djebel-theme-clear-*`).
