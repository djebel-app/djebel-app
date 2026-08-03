# Djebel Configuration Reference

Every configuration key the framework core reads, where it comes from, and what it
defaults to. Plugin settings are a separate topic — see
[plugin-guide.md](plugin-guide.md) → *Reading config*.

## Two tiers, and why it matters

Djebel reads configuration from two places, through two different classes. **They are
not interchangeable**, and picking the wrong one is the most common config mistake:

| | `Dj_App_Config::cfg($key, $default)` | `Dj_App_Options::getInstance()->get($key, $default)` |
|---|---|---|
| Reads | env vars + PHP constants | `app.ini` |
| Does NOT read | `app.ini` | env vars / constants |
| Set by | hosting env, `.env`, `define()` | the site owner, in a text file |
| Typical use | infrastructure: where things live, kill switches | site behavior: title, theme, plugins |

`cfg()` never opens `app.ini`. A key read only through `cfg()` **cannot be set from
`app.ini`, no matter how it is spelled there.**

### Making a key settable from `app.ini`

A key is app.ini-settable only when someone explicitly bridges the two tiers — Options
first, `cfg()` as the fallback default:

```php
$load_libs = $options_obj->get('app.load_libs', Dj_App_Config::cfg('app.core.load_libs'));
```

For a boolean, use the Options helper rather than nesting the two calls:

```php
$process_all_default = Dj_App_Config::cfg('app.core.shortcodes.process_all', false);
$process_all = $options_obj->isEnabled('app.shortcodes.process_all', $process_all_default);
```

`isEnabled($key, $default = false)` / `isDisabled($key, $default = true)` accept
`1` / `true` / `yes` / `on` / `enabled`. The `$default` matters: it distinguishes an
ABSENT key from an explicit `0`.

Only three core keys are bridged today — `app.load_lib_loader`, `app.load_libs` and
`app.shortcodes.process_all`. Everything else in the `app.core.*` tables below is
env/constant only.

## How each tier resolves

**`cfg('app.core.foo')`** tries, in order:

1. `getenv('app.core.foo')` — the raw key
2. `getenv('APP_CORE_FOO')` — non-word chars to `_`, uppercased
3. `getenv('DJEBEL_APP_CORE_FOO')` — same, `DJEBEL_` prefixed
4. `constant('APP_CORE_FOO')`, then `constant('DJEBEL_APP_CORE_FOO')`
5. the `$default` argument

The resolved value passes through `replaceSystemVars()` and the `app.core.cfg` filter,
then is written back to the environment so later reads in the same request are cheap.

**Options** parses `app.ini` from the private conf dir (`.ht_djebel/conf/app.ini` by
default). Dotted keys nest: `[app] shortcodes.process_all` is read as
`app.shortcodes.process_all`, exactly like `[plugins] djebel-faq.sort_by` is read as
`plugins.djebel-faq.sort_by`.

### Conditional values — `@dj_if`

An `app.ini` value may be decided by the environment:

```ini
; on when DEV_ENV is truthy, empty otherwise
djebel-static-content.cache = @dj_if env.DEV_ENV:0

; compare against a value; != negates
djebel-faq.cache = @dj_if env.APP_ENV=prod:1
```

Format is `@dj_if env.CONDITION:RESULT`. With no `=`, the condition is true when the env
var holds an enabled value. With `=` / `!=`, the env value is matched against the
expected one. **No match yields an empty string**, not the untouched directive — so an
unmatched conditional reads as "not set" and the consumer's own default applies.

## `app.sys.*` — where the framework lives

Resolved before anything else loads; override only when relocating the install.

| Key | Default |
|---|---|
| `app.sys.app_base_dir` | the directory `index.php` sits in |
| `app.sys.app_src_dir` | `<base>/src` |
| `app.sys.app_core_dir` | `<src>/core` |
| `app.sys.app_lib_dir` | `<core>/lib` |
| `app.sys.plugins.shared_plugins_dir` | *(unset)* |
| `app_doc_root_dir` | detected document root |
| `app_site_root_dir` | detected site root |
| `env_file` | `<conf dir>/.env` |

## `app.core.*` — core subsystem toggles

Env/constant only unless the *app.ini* column names a key.

| Key | Default | app.ini | What it does |
|---|---|---|---|
| `app.core.run` | `true` | — | Master switch. Off = load the framework without serving a request (how the test suite boots it). |
| `app.core.headless` | `false` | — | Skip theme/page output. |
| `app.core.options.load` | `true` | — | Load `app.ini` at all. |
| `app.core.conf_dir` | *(detected)* | — | Where `app.ini` and `.env` live. |
| `app.core.private_dir_name` | `.ht_djebel` | — | Name of the private dir scanned for upward from the script dir. |
| `app.core.load_lib_loader` | *(unset)* | `[app] load_lib_loader` | Make the on-demand lib loader (`Dj_App_Lib`) available. |
| `app.core.load_libs` | *(unset)* | `[app] load_libs` | Eager-load libs at bootstrap. `1`/`true`/`*` = all, or a list of ids/globs (`orbisius*`). |
| `app.core.plugins.load_non_public_plugins` | *whether the dir exists* | — | Also load plugins from the private plugins dir. |
| `app.core.plugins.continue_loading` | `true` | — | Keep loading the remaining plugins after one fails. |
| `app.core.theme.load_theme` | `true` | `[theme] load_theme` | Theme system toggle. |
| `app.core.theme.load_theme_functions` | `[site] theme_load_functions` | `[site] theme_load_functions` | Load the theme's `functions.php`. **Reversed bridge** — app.ini supplies the default and env/constant OVERRIDES it. |
| `app.core.theme.load_theme_header` | `false` when the theme ships a main file | — | Load `header.php`. |
| `app.core.theme.load_theme_footer` | `false` when the theme ships a main file | — | Load `footer.php`. |
| `app.core.shortcodes.load` | `true` | — | Shortcode system toggle. |
| `app.core.shortcodes.full_page_replace` | `false` | — | Replace shortcodes in the whole buffer instead of only from `<body>`. |
| `app.core.shortcodes.process_all` | `false` | `[app] shortcodes.process_all` | Call the callback for EVERY occurrence. See the warning below. |
| `app.core.output.render_generator` | `true` | — | Emit the generator meta tag. |
| `app.core.process_missing_static_files` | `false` | — | Let the app handle requests for missing static files. |
| `app.core.log.file` | *(unset)* | — | Log file location. |

The plugin system itself has **no config key** — it is gated only by the
`app.core.plugins.load_plugins` filter (default `true`), so turning it off means
hooking that filter, not setting a value.

### ⚠ `shortcodes.process_all` is a performance trade

A shortcode with the same tag AND the same params yields the same output, so it is
rendered **once** and the result reused for every occurrence — N identical tags cost
ONE call. Distinct params are distinct tags and already render separately.

Turning `process_all` on invokes the callback per occurrence. That is what a counter or
a random pick needs, but it applies to **every shortcode on the site** — a page with the
same expensive shortcode three times pays three renders instead of one. Leave it off
unless a shortcode genuinely must differ per occurrence.

## `app.*` — request and error handling

| Key | Default | What it does |
|---|---|---|
| `app.debug` | `false` | Debug mode. |
| `app.error_logging` | `true` | Write PHP errors to a log. |
| `app.error_log_file` | *(derived)* | Where those errors go. |
| `app.request.finish_request_time_limit` | `45` | Seconds allowed for post-response work after the client is released. |
| `env` | *(unset)* | Environment name (`dev`, `staging`, `live`). |

## `[site]` — site identity

Read through Options, so these live in `app.ini` under `[site]`.

| Key | What it does |
|---|---|
| `site.site_title` | Site name. |
| `site.site_url` | Canonical URL; overrides detection. |
| `site.site_port` | Non-standard port. |
| `site.front_page` | Which page serves `/`. |
| `site.timezone` | Timezone for date handling. |
| `site.strip_url_segment` | Segment dropped during web-path detection (e.g. `public`). |
| `site.theme_load_functions` | Load the theme's `functions.php` (a theme shipping a theme class needs this on). |

Themes and plugins read their own `[site]` / `[theme]` keys beyond these — `[site] lang`
is a `djebel-clear` key, not a core one. Only the rows above are read by the core.

## `[theme]` — theme selection

| Key | What it does |
|---|---|
| `theme.theme_id` | Active theme. Resolved as `theme.theme` → `theme.theme_id` → `site.theme_id` → `site.theme`, first non-empty wins. |
| `theme.load_theme` | Per-site theme toggle. |
| `theme.single_page` | Single-page mode; also implied when the theme has no `pages` dir. |

## `db_*` — database

`db_prefix` comes from `cfg()` (env/constant); the rest are Options keys.

| Key | Default |
|---|---|
| `db_driver` | *(unset)* |
| `db_host` | `127.0.0.1` |
| `db_name`, `db_user`, `db_pass`, `db_port` | *(unset)* |
| `db_table_prefix` | *(unset)* |
| `db_prefix` *(cfg)* | `dj_` |

## `[plugins]` — plugin settings

Plugin keys are `plugins.<plugin-slug>.<setting>` and belong to the plugin that reads
them; see [plugin-guide.md](plugin-guide.md). One core-level key applies to any plugin:

| Key | What it does |
|---|---|
| `plugins.<id>.load_if_url` | Load the plugin only when the request URL matches — the cheapest way to keep a plugin off every other page. Also readable via `cfg()`. |

## `[page_nav]` — navigation

Read as one block by the page layer. Entries are `<id>.title`, `<id>.url`, and
`<id>.parent` for a child item.

## Adding a new key

1. Decide the tier. Infrastructure and kill switches → `cfg()`. Anything a site owner
   should control → Options, bridged to a `cfg()` default.
2. Namespace it: `app.core.*` for core subsystems, `app.sys.*` for locations,
   `site.*` / `theme.*` for site identity, `plugins.<slug>.*` for a plugin.
3. Resolve it **once**, outside any loop, and keep the default in the call rather than
   in a second `if`.
4. Expensive or behavior-changing features are **off by default** and opt-in.
5. Add it to this file and to the key list in `.claude/CLAUDE.md`.
