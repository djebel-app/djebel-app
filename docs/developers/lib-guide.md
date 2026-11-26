# Djebel Lib Guide

What a lib is, where it lives, and how it is loaded and named. Plugins have their own guide
(`plugin-guide.md`), and the tests of both follow `testing.md`.

## A lib shares code; a plugin acts

A **lib** is shared code: classes other code calls. It registers no hooks of its own, does
nothing when it loads, and never answers a request. A **plugin** acts: it hooks into the request,
decides, and answers. Protecting a site, drawing a form and sending a mail are a plugin's jobs.

When a feature needs both, split it. The lib holds what any caller can reuse, and a small plugin
wires it into the request. A CSV lib is the shape: it reads and writes rows; a plugin that logs a
form's submissions calls it, and so does a CLI tool that exports them.

- A lib works where plugins never load: a CLI tool, a test, or an app that boots djebel with
  `app.core.run` off.
- A lib may fire filters so its callers can change its data. Offering a filter is not acting.

## Where a lib lives

A lib is a folder in the site's private dir, `.ht_djebel/app/lib/<lib_id>/`, and its main file is
`lib.php`, which opens with a header:

```php
<?php
/*
lib_id: djebel-core-lib-csv
lib_name: Djebel Core CSV
version: 1.0.0
description: Assoc-row CSV reading and writing.
min_php_ver: 7.4
*/
```

A distributable lib has its own repo and reaches each site as a git submodule, the way a plugin
does (`plugin-guide.md`, Distribution).

## Loading it

- **On demand:** `Dj_App_Lib::loadLib('<lib_id>')`, once the loader is on: `[app] load_lib_loader = 1`
  in app.ini, or `DJEBEL_APP_CORE_LOAD_LIB_LOADER=1` in the environment.
- **At bootstrap:** `[app] load_libs` set to `1`, `true` or `*` loads them all, or to a list of ids
  or globs (`orbisius*`).

Libs load before any plugin and before the bootstrap checks `app.core.run`, so a caller that boots
djebel with `app.core.run` off can still use them.

## Names

An official lib lives in https://github.com/djebel-app-libs/. A core lib's id is
`djebel-core-lib-<name>` and its class `Dj_App_Core_Lib_<Name>`. Its hooks, and its settings, sit
under `app.core.lib.<name>.*`, such as `app.core.lib.http.filter.curl_options`.

## Tests

A lib ships test files only, under `tests/` (`testing.md`). From a djebel-app checkout:
`php tools/testing/run.php <the lib's dir>`.
