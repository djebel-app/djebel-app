# Djebel Plugin Guide

How to build a Djebel plugin, and — most importantly — the **naming conventions** every
plugin MUST follow. Consistency is the #1 rule: a reader should be able to guess a plugin's
dir, CSS classes, hooks, and option keys from its name alone, with zero surprises.

## Keep it lean — one job per plugin

Djebel plugins are **lean**: 0% body fat, only what's truly needed. A plugin does **one job**
and exposes hooks so *other* plugins can extend it — it never absorbs adjacent concerns.

- `djebel-contact` renders a contact form and emails it. That's all — it does **not** log
  submissions, write a DB row, or ping Slack.
- Persistence, notifications, analytics, and the like are **separate add-on plugins** that
  listen on the hooks the core plugin fires (see [Extend via hooks](#extend-via-hooks--add-on-plugins)).

The test: if a responsibility could be peeled off and the plugin still does its core job, it
doesn't belong inside the plugin — it's an add-on.

## Naming Conventions (MANDATORY)

A plugin's name appears in several places, and the prefix it carries depends on **where** it
appears. The rule is one idea: **drop the redundant `plugin` wherever the thing already sits
inside a "this is a plugin" container; keep the full `djebel-plugin-` only where the
identifier floats loose in a global namespace.** There are three tiers.

For a plugin named `<name>` (e.g. `contact`):

### Tier 1 — Repo-context: `djebel-<name>`

These already live inside a plugins container (a plugins org, the `app/plugins/` dir, the
`data/app/plugins/` dir, the `djebel.com/plugins/` URL), so the container already says
"plugin" — repeating it is redundant.

| What | Form | Example |
|------|------|---------|
| repo / directory | `djebel-<name>` | `djebel-contact` |
| `plugin_uri` slug | `https://djebel.com/plugins/djebel-<name>` | `…/djebel-contact` |
| data subdir | `getCorePrivateDataDir(['plugin' => 'djebel-<name>'])` | `…/plugins/djebel-contact` |

### Tier 2 — Global namespace: `djebel-plugin-<name>`

These float loose in a namespace shared with the theme, every other plugin, core, and the
browser. They carry the **fully-described** prefix so two plugins can never collide.

| What | Form | Example |
|------|------|---------|
| `text_domain` | `djebel-plugin-<name>` | `djebel-plugin-contact` |
| CSS / JS classes & ids | `djebel-plugin-<name>-<descriptor>` | `djebel-plugin-contact-email-input` |
| form field names | `djebel_plugin_<name>_<field>` | `djebel_plugin_contact_email` |
| PHP class | `Djebel_Plugin_<Name>` | `Djebel_Plugin_Contact` |
| option keys | `plugins.djebel-plugin-<name>.<key>` | `plugins.djebel-plugin-contact.to_email` |

### Tier 3 — Hooks: `app.plugin.<name>.*`

Hook names are already namespaced under `app.plugin.`, so the plugin segment is just the
bare snake_case `<name>`. Repeating `djebel_plugin_` would be redundant.

| What | Form | Example |
|------|------|---------|
| hook namespace | `app.plugin.<name>.<action>` | `app.plugin.contact.message_processed` |

### CSS / id names must be DESCRIPTIVE

The `<descriptor>` names the element by its **role**, never by an abbreviation or index:

- ✅ `djebel-plugin-contact-email-input`, `djebel-plugin-contact-submit-btn`,
  `djebel-plugin-contact-message-textarea`
- ❌ `djebel-plugin-contact-e1`, `djebel-plugin-contact-f2`, `djebel-plugin-contact-x`

## Official plugins and everyone else's

The `djebel-` prefix is reserved for **official** plugins — the ones approved by the author
of the framework. Every other plugin, whether it stays private to its owner's sites or is
shared with other people, never takes a `djebel-` name: it carries its **owner's prefix**
in the same place, through the same tiers. A `djebel-` name then always means "official".

The official ones each live in their own GitHub organization:

- Plugins — https://github.com/djebel-app-plugins/
- Themes — https://github.com/djebel-app-themes/
- Libs — https://github.com/djebel-app-libs/

| Tier | Official | Everyone else |
|------|----------|---------------|
| repo-context | `djebel-<name>` | `<owner>-<name>` |
| global namespace | `djebel-plugin-<name>` | `<owner>-<name>` |
| hooks | `app.plugin.<name>.*` | `app.plugin.<name>.*` (same) |

An owner-prefixed name never includes the word `plugin` or `theme`.

Example — a download plugin named `dl`, owned by Orbisius: dir, `text_domain` and CSS
prefix `orbisius-dl`, class `Orbisius_Dl`.

## Distribution — one plugin, one repo, many sites

A **distributable** plugin lives in its **own git repo** — never inside a
site's repo, and never inside djebel-app itself. **djebel-app stays pristine: the core never
gains feature code.** Each site that uses the plugin pulls it in as a **git submodule** under
that site's plugin dir (`dj-content/system_plugins/<plugin>` for a system plugin,
`.../plugins/<plugin>` for a regular one), so every site shares one source of truth and no
plugin code is ever committed into the site's own repo.

- Decide the home by *is it distributable?*, not *who needed it first*. A plugin first built
  because one site needed it is still distributable if it's generic — give it its own repo.
- **Fix once, pull everywhere:** edit + commit + push from inside any site's submodule
  checkout (the commit lands in the **plugin** repo); every other site then runs
  `git submodule update --remote <plugin>` and bumps its pinned pointer.
- Two gotchas: a submodule checks out **detached** — run `git checkout main` inside it before
  committing, or the commit is orphaned; and each change is **two commits** — one in the
  plugin repo (the fix), one in the site repo (the moved pointer). A site only sees the fix
  once its pointer is bumped — that's the feature: each site pins a known-good version.
- A **private** plugin (`<owner>-<name>`) is the exception — nothing to distribute, so it
  can stay in its site's repo.

## Plugin Header

The main file is `plugin.php` and opens with a header comment block. Note `plugin_uri` is the
repo-context (Tier 1) form, `text_domain` the global (Tier 2) form:

```php
<?php
/*
plugin_name: Djebel Contact
plugin_uri: https://djebel.com/plugins/djebel-contact
description: Contact form plugin
version: 1.0.0
load_priority: 20
tags: contact, contact form
stable_version: 1.0.0
min_php_ver: 5.6
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-plugin-contact
license: gpl2
*/
```

## Bootstrapping

Grab the singleton and hook `app.core.init`. Use a named-method callable — never a closure:

```php
$obj = Djebel_Plugin_Contact::getInstance();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);
```

Register the plugin's own hooks/shortcodes inside `init()`.

## Reading config

Plugin settings come from `app.ini` via the Options class, keyed by the Tier 2 option slug:

```php
$options_obj = Dj_App_Options::getInstance();
$to_email = $options_obj->get('plugins.djebel-plugin-contact.to_email', 'admin@localhost');
```

A plugin that stores data (CSV exports, caches, etc.) gets its data dir from the Tier 1
helper, keyed by its **own** slug — so data never lands in another plugin's tree:

```php
$params = [
    'plugin' => 'djebel-contact-log-csv',
];

$dir = Dj_App_Util::getCorePrivateDataDir($params);
```

## Extend via hooks — add-on plugins

A plugin's job is fixed; its **extension points** are open. Fire actions/filters at the
moments that matter, and let optional concerns live in their own tiny plugins that hook them —
so the core plugin never grows and never changes when you add a feature.

**The core plugin fires the event** (it owns the data, so it passes it along):

```php
$ctx = [
    'email' => $email,
    'message' => $message,
    'data' => $data,
];

Dj_App_Hooks::doAction('app.plugin.contact.message_processed', $ctx);
```

**An add-on plugin listens** — a separate, lean plugin (`djebel-contact-log-csv`) that does
nothing but persist:

```php
public function init()
{
    Dj_App_Hooks::addAction('app.plugin.contact.message_processed', [ $this, 'saveToCsv' ]);
}

public function saveToCsv($ctx)
{
    if (empty($ctx['data'])) {
        return;
    }
    // ...write $ctx['data'] to its OWN data dir (see Reading config)...
}
```

- The add-on writes to **its own** data dir — never the core plugin's dir or code.
- Delete the add-on and the core plugin still works; it just loses that one feature.
- Want a second sink (DB, webhook, a Google Sheet)? Another small plugin on the **same
  hook** — the core plugin never changes.
- A hook name shared by the firing plugin and its listeners is a **contract**: change one
  side, grep the other.

## Legacy plugins (migrate later)

The sibling plugins' **dirs** already conform to Tier 1 (`djebel-utm`, `djebel-seo`,
`djebel-markdown`, `djebel-external-links`, `djebel-static-content`,
`djebel-simple-newsletter`). What's legacy is their **Tier 2** identifiers — `text_domain`,
CSS, and option keys still use the short `djebel-<name>` instead of `djebel-plugin-<name>`.
Migrate those later — do **not** rename them as a side effect of other work.

`djebel-contact` is the reference implementation of the current convention.
