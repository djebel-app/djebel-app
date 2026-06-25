# Djebel Plugin Guide

How to build a Djebel plugin, and — most importantly — the **naming conventions** every
plugin MUST follow. Consistency is the #1 rule: a reader should be able to guess a plugin's
dir, CSS classes, hooks, and option keys from its name alone, with zero surprises.

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

## Public vs Private (`site`) plugins

Some plugins are **distributable** (public, shipped to other sites). Others are
**site-specific / private** — built for one site, never distributed (e.g. an internal
download counter). Mark a private plugin with a `site` segment; everything else is the same
rule. The `site-` in the name **is** the declaration — no separate header field needed.

| Tier | Public | Private (`site`) |
|------|--------|------------------|
| repo-context | `djebel-<name>` | `djebel-site-<name>` |
| global namespace | `djebel-plugin-<name>` | `djebel-site-plugin-<name>` |
| hooks | `app.plugin.<name>.*` | `app.plugin.<name>.*` (same) |

Example — a private download counter named `dl`: dir `djebel-site-dl`, `text_domain`
`djebel-site-plugin-dl`, CSS `djebel-site-plugin-dl-*`, class `Djebel_Site_Plugin_Dl`. The
`site-` makes it obvious in the dir listing, the markup, and the CSS that this one isn't a
distributable.

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

The plugin's data dir (CSV exports, caches, etc.) comes from the Tier 1 helper:

```php
$params = [
    'plugin' => 'djebel-contact',
];

$dir = Dj_App_Util::getCorePrivateDataDir($params);
```

## Legacy plugins (migrate later)

The sibling plugins' **dirs** already conform to Tier 1 (`djebel-utm`, `djebel-seo`,
`djebel-markdown`, `djebel-external-links`, `djebel-static-content`,
`djebel-simple-newsletter`). What's legacy is their **Tier 2** identifiers — `text_domain`,
CSS, and option keys still use the short `djebel-<name>` instead of `djebel-plugin-<name>`.
Migrate those later — do **not** rename them as a side effect of other work.

`djebel-contact` is the reference implementation of the current convention. The private
`djebel-site-dl` has the dir right (Tier 1); its `text_domain` (`djebel-download-counter`)
and internal ids are the legacy bit — they would become `djebel-site-plugin-dl`.
