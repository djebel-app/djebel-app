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
| `app.core.assets.load` | `true` | — | Asset queue toggle (`Dj_App_Assets`). Off = plugin `register()` calls have nowhere to go, so no asset tags are emitted. |
| `app.core.assets.use_min` | `Dj_App_Env::isLive()` | `[app] core.assets.use_min` | Serve `name.min.js` / `name.min.css` when one sits beside the file a plugin named. See below. |
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

### `assets.use_min` — serving minified builds

A plugin registers the file it wrote. When this is on, and a build sits **beside** that
file, the build is what ships:

```
assets/main.js       <- what the plugin registered
assets/main.min.js   <- what the browser gets
```

Only `.js` and `.css` are ever looked for — nobody ships a minified font — and the check
runs on the file that was already found, so it costs **one** extra `is_file()` and only
when there was something to serve. A name that already carries the marker is left alone;
nothing ever asks for `main.min.min.js`.

**The environment decides the default.** A dev box serves what was asked for, so what runs
is what you are editing and a stale build cannot quietly shadow a source change. Everywhere
else prefers the build — note that `Dj_App_Env::isLive()` answers **true on staging**, so a
staging box behaves like production here.

Set it explicitly to override the environment:

```ini
[app]
core.assets.use_min = 0
```

A filter gets the last word, and it runs for **every** asset — so a site can decide per
request or per asset, and one registered after assets have already been queued still counts:

```php
Dj_App_Hooks::addFilter('app.core.assets.filter.use_min', ['My_Plugin', 'filterUseMin']);
```

A single asset opts out with `skip_min`, leaving the rest of the site on builds:

```php
Dj_App_Assets::register([
    'plugin' => 'my-plugin',
    'file' => '/assets/main.js',
    'skip_min' => 1,
]);
```

## `app.*` — request and error handling

| Key | Default | What it does |
|---|---|---|
| `app.debug` | `false` | Debug mode. |
| `app.error_logging` | `true` | Write PHP errors to a log. |
| `app.error_log_file` | *(derived)* | Where those errors go. |
| `app.request.finish_request_time_limit` | `120` | Seconds allowed for post-response work after the client is released. |
| `env` | *(unset)* | Environment name (`dev`, `staging`, `live`). See below. |

### The environment name

`env` is resolved by `Dj_App_Env::getAppEnv()` — once per request, normalized. Ask the
predicates rather than comparing the string yourself: `live`, `prod` and `production` all
mean the same thing, and only `isLive()` knows that.

```php
Dj_App_Env::isDev();       // dev, development
Dj_App_Env::isStaging();   // any name containing "staging"
Dj_App_Env::isLive();      // live / prod / production — AND anything undeclared
Dj_App_Env::isWorkEnv();   // dev or staging
```

**An undeclared environment answers LIVE on purpose.** An install that never said what it
is gets the careful treatment, not the permissive one. Note this also makes `isLive()` true
on staging — `isWorkEnv()` is the one that separates a work box from production.

A filter gets the last word, so a fleet can answer for every install at once — by host, by
install dir, by whatever it decides — instead of every install repeating itself in its own
`.env`:

```php
Dj_App_Hooks::addFilter('app.core.env.filter.name', ['My_Plugin', 'filterEnvName']);
```

The resolved name is remembered for the request, so a listener has to be registered before
anything asks — plugins load early enough for that. `Dj_App_Env::set()` drops what was
remembered, which is how a test moves between environments.

### The run id

Every log line carries a `req:` id so the lines of one run can be read together, and
`Dj_App_Util::reqId()` is where it comes from.

```php
$req_id = Dj_App_Util::reqId();     // read — generated on the first ask, then remembered
Dj_App_Util::reqId('abc123');       // set, e.g. from an upstream X-Request-Id
```

**It is never empty.** A CLI tool, a cron run and anything loading the framework as a
library all get one, because it does not depend on a request object being constructed.

It resolves in order: a value a caller set, then the filter, then a generated one.

```php
Dj_App_Hooks::addFilter('app.core.log.req_id', ['My_Plugin', 'filterReqId']);
```

Resolved once per run — an id that changed halfway through would correlate nothing.

A failure shown to a visitor carries this id as its ref — the fatal-error page and the
plugin-crash box both show it, always, whether or not the log entry got written. The public
page never says whether logging worked: two different pages would tell a visitor how the site
is configured. With `app.debug` on, both also say when the entry was not written.

`Dj_App_Log::logAppError()` returns a bool: `true` when the entry reached the app error log
(`app.error_log_file`, or PHP's own log when that setting is blank). `false` when error
logging is off (nothing is written) or when that file could not be written (the entry lands
in PHP's own log instead).

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

## `[assets]` — site-declared assets

Each entry is one asset: the entry key is its id, and every key under it is a parameter
`Dj_App_Assets::add()` accepts. They are registered before the first plugin runs, on every
page, so a shared library needs no plugin to ask for it.

```ini
[assets]
jquery.file = /site/shared/jquery/jquery.min.js
login_css.file = /plugins/djebel-login/assets/login.css
login_css.load_if_url = /login|/register
```

| Key | What it does |
|---|---|
| `<id>.load_if_url` | Render the asset only on a request whose site-relative path contains one of the listed paths, `\|`-separated, case-insensitive. Decided when the page renders, so it follows whatever the request looks like by then. Absent means every page. Same word and the same substring test as `plugins.<id>.load_if_url`; that one is case-sensitive and matches the full request path, web path included. |

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
