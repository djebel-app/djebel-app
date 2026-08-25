# PRD: `Dj_App_Assets` — one-call asset registration

**Status:** implemented 2026-08-21.
**Location:** `src/core/lib/assets.php`, registered in `index.php`.
**Supersedes:** `.claude/TODO.md` entry #1, whose `addFile()` / `addInline()` split is replaced
by one auto-detecting `add()`. That entry now points here; this file is the only design.
**Design settled:** 2026-08-21. Exploration facts below were verified the same day — they are
recorded so the implementing session does not re-derive them.

---

## The problem

Every plugin that wants a `<script>` or `<style>` on the page does the same four things by
hand: hook the page pipeline, locate `</head>` / `</body>`, dedupe its own emissions, escape
its own URLs. N plugins, N copies of one job.

Worse, a plugin cannot even name its own asset. There is **no plugin→URL helper anywhere in
djebel** — no `plugins_url()`, no `getThemeUrl()`, no filesystem-path→URL converter of any
kind. Today a plugin hand-concatenates off `getContentDirUrl()`, and a theme emits a
`__THEME_URL__` token that a whole-buffer regex rewrites later.

It surfaced concretely on 2026-08-20 in `djebel-login`: submit-feedback JS had to be emitted
as an inline `<script>` from a hook listener, because there was no way to say *ship this file*.

---

## Decisions (owner, 2026-08-21)

| Topic | Decision |
|---|---|
| Entry point | Singleton + `add($ctx)` / `remove($id)`. `register($ctx)` / `deregister($id)` are static wrappers that resolve the instance and delegate. |
| Kind & placement | Inferred from **which key** the caller used. CSS → head, JS → footer. Every inference is overridable. |
| Ordering | `priority` field, default **`Dj_App_Hooks::DEFAULT_PRIORITY`** by reference — not a retyped literal. |
| Return | `add()` returns a `Dj_App_Result` carrying the resolved `id`. |
| Removal | By id **or** by the same params that added it. |
| Non-public plugins | **Auto-inline the file.** Log one line. No size limit. |
| Cache busting | `?v=<filemtime>`, automatic, only when the file resolves on disk. |
| URL escaping | Widen `Dj_App_HTML::escUrl()` to accept protocol-relative `//host`. |
| Error pages | Add a filter in `renderPage()` so registered assets reach error output. |
| Extensibility | Filters around **both** adding and rendering. |
| Tests | TDD in the working tree, **one commit** — tests first, fail, build until green, commit together. Never a red suite in history. |

> **On `register()` / `deregister()`:** these are wrappers, which the no-alias rule would
> normally reject. They are in deliberately — they remove the `getInstance()` line from every
> call site, so plugin code reads as one statement. The owner is *on the fence* about keeping
> both pairs (2026-08-21). If one goes, `add()` / `remove()` are the survivors.

---

## API

### Registering

```php
Dj_App_Assets::register([ 'plugin' => 'djebel-login', 'file' => '/assets/main.js', ]);
Dj_App_Assets::register([ 'style'  => '.a { color: red; }', ]);
Dj_App_Assets::register([ 'js'     => 'var cfg = {};', ]);
Dj_App_Assets::register([ 'url'    => 'https://cdn.example.com/x.css', ]);
```

**The key declares the source and the kind** — no `kind` or `placement` needed for common cases:

| Key | Source | Kind | Default placement |
|---|---|---|---|
| `file` | relative to the `plugin` / `theme` context | from extension | by kind |
| `url` | explicit, external | from extension | by kind |
| `style` | inline | CSS | head |
| `js` / `script` | inline | JS | footer |
| `content` / `buffer` / `data` | inline | sniffed from a leading `<script` / `<style` | by kind |

Alias groups are read with `Dj_App_Util::getField('js|script', $ctx)` — see *Reading the
context* below — so adding a spelling is one more token in a string, never another branch.

### `placement` is the flag

JS defaults to the footer, but plenty of scripts must run before the page paints — a feature
flag, a theme switch, an inline config an early template reads. Head placement is first-class:

```php
Dj_App_Assets::register([
    'plugin' => 'my-plugin',
    'file' => '/assets/early.js',
    'placement' => Dj_App_Assets::PLACEMENT_HEAD,
]);
```

Constants: `PLACEMENT_HEAD`, `PLACEMENT_FOOTER`, and `PLACEMENT_BODY_START` (the
`app.page.html.body.start` seam already exists and costs nothing to expose). One key carries
the choice — no boolean shorthand beside it, so there is exactly one way to say it.

### Extra tag attributes — `attrs` / `attribs`

Anything the tag itself needs, as a key→value map. A `true` value renders the attribute bare:

```php
Dj_App_Assets::register([
    'plugin' => 'my-plugin',
    'file' => '/assets/main.js',
    'attrs' => [ 'defer' => true, 'crossorigin' => 'anonymous', ],
]);
// <script src="…/main.js?v=1755…" defer crossorigin="anonymous"></script>
```

Values are escaped with `Dj_App_HTML::escAttr()`. This is how `defer` / `async` on scripts and
`media` on stylesheets are expressed — the `tag_html` filter exists for what a *site* wants to
impose on someone else's tag, not for how a plugin states its own requirements.

### Reading the context — every alias group is one `getField()` call

**Do not hand-roll the alias lookups.** `Dj_App_Util::getField()` (`util.php:1407`) already
does exactly this job, and using it is what keeps the alias table from becoming a pile of
`empty()` chains:

```php
$attrs    = Dj_App_Util::getField('attrs|attribs', $ctx, []);
$js       = Dj_App_Util::getField('js|script', $ctx);
$content  = Dj_App_Util::getField('content|buffer|data', $ctx);
$priority = Dj_App_Util::getField('priority', $ctx, Dj_App_Hooks::DEFAULT_PRIORITY);
```

What it gives us for free:

- **Multi-key** — `|`, `,` and `;` all separate alternatives; first hit wins, in the order
  written. The alias table in this PRD *is* the argument string.
- **Cast by the default's TYPE** — an `[]` default returns an array, an int default returns an
  int. `attrs` therefore needs no `is_array()` guard and no cast.
- **Dash/underscore interchangeable in both directions**, so `add_params` and `add-params` both
  resolve without listing either.
- **A fast path** for a plain single-token key with no separator, so the common case does not
  pay for the alias machinery.

Two rules when calling it: **never pass the callee's own default** (`getField($k, $ctx, '')`
is noise — `''` is already the default), and **do not re-cast the return** — the default's type
already decided it. Do **not** reach for the `PARTIAL_MATCH` flag here; a public API surface
should match its keys exactly.

### Resolution order

Each step is skipped when the caller passed the value explicitly:

1. **source + kind** — from the key used, per the table above.
2. **placement** — else CSS → head, JS → footer.
3. **url** — else built from `file` plus the `plugin` / `theme` context.
4. **id** — else `Dj_App_Util::generateHash()` of the source; drives dedupe.
5. **wrapping** — content already carrying its own `<script>` / `<style>` passes through
   untouched; bare content is wrapped.
6. **version** — `?v=filemtime` when the file resolves on disk; nothing for an explicit `url`.

### Ordering

Lower `priority` renders earlier; **stable within a priority** (registration order wins), so
two plugins never have to coordinate. The default is `Dj_App_Hooks::DEFAULT_PRIORITY`,
referenced as the constant so assets and hooks cannot drift apart on what "default" means.
This supersedes TODO #1's proposed `10` — one number for ordering across the framework beats
two subsystems each with a private idea of normal.

### `add()` returns the id

The id is what makes the queue editable, so a caller must be able to obtain one **without
having to invent it**:

```php
$res_obj = Dj_App_Assets::register([ 'plugin' => 'djebel-login', 'file' => '/assets/main.js', ]);
$asset_id = $res_obj->id;

Dj_App_Assets::deregister($asset_id);
```

This closes a loop TODO #1 left open: it specified auto-generated ids *and* `remove($id)`, but
no way to learn an auto-generated id — so removal only worked if you had passed your own.

### Queue editing

All return a `Dj_App_Result`.

| Method | Behavior |
|---|---|
| `remove($id)` | **Smart param — an id string OR a params array.** Not-queued is a no-op success, not an error: removing a maybe-present asset should not require checking first. |
| `deregister($id)` | Static wrapper over `remove()`. |
| `replace($id, $ctx)` | Swap content, **preserving queue position** relative to neighbours. |
| `removeAll()` | Clears the queue. Also what `tearDown()` uses, so tests need no back door. |

**Removal without an id** — name the asset the way you added it:

```php
Dj_App_Assets::deregister([ 'plugin' => 'djebel-login', 'file' => '/assets/main.js', ]);
```

This falls out of one shared `resolveId($ctx)` used by **both** add and remove: the id is
derived deterministically from the source, so removal by params re-derives the same handle
rather than scanning.

⚠️ **Caveat to implement deliberately:** when the caller passed a *custom* `id` at add time,
re-derivation will not match it. Removal by params must therefore also match on the resolved
source, not on the id alone — otherwise a custom id silently makes an asset unremovable by the
very params that created it.

The id-or-array smart param follows core precedent: `Dj_App_Util::die($content, $title|$args)`
and `Dj_App_HTML::renderPage($content, $title|$options)` both accept an array in place of a
scalar in exactly this way.

### Duplicates

Two rules, depending on whether the caller named the asset:

- **No explicit `id`** — dedupe by content hash. Two plugins registering byte-identical content
  emit **once**. Neither has to know about the other, which is the point.
- **Explicit `id`** — **last write wins.** Registering the same id again replaces the earlier
  entry, keeping its queue position. That is what makes an id worth passing: a site can
  override a plugin's asset by re-registering under the same handle, without needing to
  `remove()` first.

### Minified builds — `assets.use_min` (added 2026-08-24)

A plugin registers the file it wrote. When a build sits **beside** that file, the build is
what ships — `assets/main.js` → `assets/main.min.js`. The caller never names it and never
has to know whether one exists.

**Resolved AFTER the candidate scan, not during it.** Probing beside every candidate would
have doubled a lookup that mostly misses — a plugin file is searched across four dirs, so a
site with no builds would pay eight `is_file()` calls where it used to pay four. Resolving
the real file first and then checking one sibling costs **one** extra stat, and only when a
file was actually found.

Four things make it empty, checked cheapest-first, and all four are deducible from the name
alone — nothing is passed in beside it:

| Rejected | Why |
|---|---|
| No extension, or under four characters before the dot | Nothing to mark, and no room for the marker |
| Extension outside `SUPPORTED_MIN_EXTS` | Only js/css are ever built; probing for a minified font spends a syscall per request to learn that |
| The name already ends `.min` before its extension | Nothing asks for `main.min.min.js` |
| The site is not taking builds | Below |

`SUPPORTED_MIN_EXTS` is deliberately **not** `SUPPORTED_KINDS`, and is not spelled with the
`KIND_*` constants. They hold the same two values today by coincidence: the day fonts become
renderable they join the KINDS, and a min lookup reading that list would immediately start
stat'ing for `font.min.woff2` on every request.

The marker is read **at the position it would occupy** — the four characters before the
extension — never searched for anywhere in the string, so a directory named `.min/` cannot
make a source file look built.

The swap runs **before** the containment check, so a `.min` file that is a symlink out of the
plugin dir is refused exactly as its source would be. It also uses `is_file()`, not
`file_exists()`: a directory answers an existence check the same way a file does, and swapping
a good file for an unreadable directory is worse than not swapping at all.

**The environment sets the default; config overrides it; the filter gets the last word.**
`Dj_App_Env::isLive()` is the default — note it answers **true on staging**, so staging
behaves like production. A dev box therefore serves what was asked for, which keeps what runs
the same as what you are editing.

Only the env+config half is memoized per request. Neither can change between two assets, and
the environment scan behind `isLive()` measured **3,734 ns** — several times what every other
check in the path costs put together, so paying it per asset was the entire cost of the
feature. **The filter is never memoized**, so it stays a live seam: one registered after the
first asset resolved is honored just the same, and a site free to answer per asset keeps that
freedom.

### Site-declared assets — the `[assets]` config section (added 2026-08-25)

A shared library — jQuery, a picker, the stylesheet everything builds on — is not any one
screen's dependency, so making one screen own the registration is arbitrary: whichever screen
happened to need it first ends up carrying it for all the others, and every screen added later
has to know to ask. Declaring it in the site's own `app.ini` removes the question.

```ini
[assets]
jquery.file = /site/shared/jquery/jquery.min.js
select2_css.file = /site/shared/select2/select2.min.css
select2.file = /site/shared/select2/select2.min.js
select2.prereq = jquery
```

`Dj_App_Assets::loadConfiguredAssets()` reads the section from `installHooks()`, so these are
registered **before the first plugin runs**. The section key IS the asset id, and every key
under it is a param `add()` already takes — so this grows by whatever `add()` grows by and
needs no code of its own. `Dj_App_Options` expands dotted INI keys into nested arrays, which
is why `select2.prereq = jquery` arrives as a param rather than as a second entry.

Three consequences worth stating, because each is a decision rather than a side effect:

- **Registering first is what keeps them overridable.** Re-registering an id replaces that
  entry in place, so a plugin can still swap the site's jQuery for its own and everything that
  named it as a prerequisite follows the replacement. Config is the base layer, not the last
  word.
- **`prereq` is what orders them, not the line order.** Config is read top-to-bottom, so
  without it the render order would be whatever order someone typed the lines in. Verified by
  declaring select2 above jQuery and confirming jQuery still renders first.
- **A bad line costs one asset, not the rest.** A mistyped file name is the likely case; the
  entry is logged with its id (a config asset has no call site to be found by) and skipped.
  A scalar `key = value` in the section names no asset at all and is skipped silently, which
  leaves the section usable for a plain setting later.

**The cost:** anything declared here is on EVERY page, including ones that never use it — a
login screen now carries jQuery. That is the trade for never having to ask. If it needs to
become opt-in, the shape is one optional key whose absence means today's behavior
(`auto_load = 0` plus an `enqueue()` by id) — `auto_load` is already this framework's word for
exactly that, so nothing declared today would have to change.

### Failure split

- **Caller bug → throw `Dj_App_Validation_Exception`**: conflicting source keys (`file` *and*
  `style` in one call), an unusable id. Precedence must not become folklore. Matches how
  `Dj_App_Lib::loadLib()` treats a malformed lib id.
- **Expected outcome → error Result with a code**: a `file` that does not resolve on disk →
  `app.core.assets.file_not_found`. Checkable by callers that care, and a typo'd asset never
  takes the page down.

---

### Out of scope for v1

Carried forward from the superseded TODO entry so these stay *decided* rather than
rediscovered. Each has a cheap answer today, which is why none of them blocks v1:

| Deferred | Why it can wait |
|---|---|
| **Dependency graph** (`'deps' => ['jquery', …]`) | `priority` already orders the queue deterministically. A real graph earns its keep only once something actually needs topological sorting. |
| **`<link rel="preload">`** | Expressible now — `'attrs' => [ 'rel' => 'preload', ]`. |
| **SRI hashes** | The `tag_html` filter can stamp `integrity` without any core change. |
| **WP-style `localize_script` data passing** | A plugin registers a small inline `js` block at a lower `priority` than its file. Same result, no new concept. |

## Hook surface

The system is itself hookable, so a site can change what is queued and what is emitted without
editing any plugin. Naming uses the `.filter.` / `.action.` infix already used by
`app.core.plugin.login.filter.user`.

**Around adding:**

| Hook | Type | Purpose |
|---|---|---|
| `app.core.assets.filter.add_params` | filter | The `$params` **before** normalization — rewrite a URL to a CDN, force a placement, bump priority, swap in a minified build. |
| `app.core.assets.filter.item` | filter | The normalized item just before it enters the queue. **Returning empty vetoes it** — the one seam for "this site never loads that asset". |
| `app.core.assets.action.added` | action | After queueing; `$ctx` carries the item. Logging / auditing, changes nothing. |
| `app.core.assets.filter.use_min` | filter | Whether a minified build is preferred, after the environment and config have had their say. Runs for **every** asset — never memoized — so it stays a live seam. |

**Around rendering:**

| Hook | Type | Purpose |
|---|---|---|
| `app.core.assets.filter.queue` | filter | The whole queue for a placement, after sort, before rendering. Bulk drop / reorder, with visibility of everything else present. |
| `app.core.assets.filter.tag_html` | filter | **Each rendered tag**; `$ctx` is the item. Stamp `nonce`, add `integrity` / `crossorigin`, swap `defer` ↔ `async`. |
| `app.core.assets.filter.html` | filter | The assembled block for a placement, right before it is echoed. |

**This supersedes TODO #1's reserved `app.assets.nonce`.** That entry set aside a
special-purpose nonce hook; a per-tag `tag_html` filter does the same job generically, and the
next requirement (SRI, `crossorigin`) then needs no new hook at all. One seam instead of one
per attribute.

---

## `Dj_App_Util::getContentUrl($ctx = [])`

The URL half. One smart method, no trailing slash:

```php
Dj_App_Util::getContentUrl()                      // …/dj-content
Dj_App_Util::getContentUrl([ 'plugin' => 'aaa' ]) // …/dj-content/plugins/aaa
Dj_App_Util::getContentUrl([ 'theme' => 'bbb' ])  // …/dj-content/themes/bbb
```

Absent key = today's behavior, so it is purely additive. Not a new pattern either —
`Dj_App_Util::getContentDataDir($params)` (`util.php:379`) already takes exactly this
`['plugin' => …]` / `['theme' => …]` shape. `getContentDirUrl()` becomes a forwarder; TODO #3
already sanctions *"old names become thin deprecated forwarders"*.

---

## Verified ground — do not re-derive

### The injection seam already exists

`Dj_App_Util::autoInjectSysHookContent()` (`util.php:994-1025`) is registered on
`app.page.full_content` at **priority 125 — the last core listener** (`index.php:659`) and
captures three action hooks into the complete document:

| Hook | Injected |
|---|---|
| `app.page.html.head` | before `</head>` |
| `app.page.html.body.start` | after `<body` |
| `app.page.html.body.end` | before `</body>` |

So `Dj_App_Assets` **echoes on `app.page.html.head` and `app.page.html.body.end`** — no new
filter, no `str_ireplace` of its own. Two properties come free:

- **Dual-mode.** If a theme calls `doAction('app.page.html.head')` inline in `header.php`,
  `Dj_App_Hooks::hasRun()` makes core skip the auto-inject and the tags land exactly where the
  theme wanted. If it does not, core string-injects. One registration covers both theme layouts.
- **Correct timing.** Priority 125 runs after shortcode expansion (20) and therefore after
  `app.page.content.render` and template includes — so anything enqueued *while* content
  renders is already in the queue.

`app.page.full_content` (`themes.php:196`, `index.php:264`) is the only hook carrying the whole
`<html>…</html>` string; we consume it indirectly through the seam above.

### Most plugins have no URL — this is why auto-inline exists

Only `dj-content/plugins/*` and `dj-content/themes/*` are web-addressable.
`getNonPublicPluginsDir()` (`.ht_djebel/app/plugins`), `getSharedPluginsDir()` and the lib dir
are outside the doc root and physically unreachable by URL. On the KolataTi dashboard, **five
of six plugins live in the non-public dir** — only `djebel-login` is publicly served.

A plugin author must not have to know where their plugin was installed, so `add()` resolves the
file and picks the delivery: an external tag when reachable, inlined content when not, with one
log line recording why.

### The FS→URL primitive

`getContentDir()` and `getContentDirUrl()` are a true 1:1 root pair, so
`str_replace(getContentDir(), getContentDirUrl(), $abs_file)` yields the public URL — and a
path that does **not** start with `getContentDir()` is exactly the auto-inline signal.

⚠️ **Latent divergence worth fixing while here:** `getContentDirUrl()` (`util.php:415`) builds
from `getContentDirName()` (`'dj-content'`), **not** from `basename(getContentDir())`. If
`DJEBEL_APP_CONTENT_DIR` points the filesystem dir at a differently-named directory, the URL
and the disk path silently disagree — and the `str_replace` primitive above breaks with it.
`Dj_App_Request::contentUrlPrefix()` (`request.php:170`) already does it correctly and has zero
callers.

### Cache busting — mirror the existing pattern

`util.php:2044-2047`, inside `replaceMagicVars()`: `file_exists` → `filemtime()` →
`Dj_App_Request::addQueryParam('v', $version, $url)`. That is the framework's only
cache-busting code and it is theme-only today.

**Plugin `version:` headers cannot be used** — there is no loaded-plugin registry; the parsed
meta is discarded at `index.php:199`. `filemtime` is the only available input.

### Gotcha — magic vars will not resolve in our output

`Dj_App_Themes::autoCorrectAssetLinks` runs at priority **20**, long before our injection at
125, so any `__THEME_URL__` / `__CONTENT_URL__` token we emit passes through **unresolved**.
Resolve URLs ourselves; do not emit tokens.

### Reuse, do not re-roll

| Need | Use |
|---|---|
| reading aliased ctx keys | `Dj_App_Util::getField('a\|b', $ctx, $default)` — `util.php:1407` |
| content-hash id | `Dj_App_Util::generateHash($data, $length = 12)` — `util.php:140` |
| handle normalization | `Dj_App_String_Util::formatStringId()` — `string_util.php:410` |
| `?v=` append | `Dj_App_Request::addQueryParam()` — `request.php:1271` |
| slash trimming | `Dj_App_Util::removeSlash()` — `util.php:1230` |
| escaping | `Dj_App_HTML::escUrl()` / `escAttr()` — `html.php:866` / `:800` |

### Conventions to match

- **PHP 7.4 floor.** Zero typed properties, zero return types, no `match`, no arrow functions,
  no constructor promotion, no `public const` — verified absent across all of core.
- **Singleton** is a function-local `static $instance` inside `getInstance()`, never a class
  property.
- **Registration** follows the shortcode precedent (`index.php:110-117`):
  `cfg('app.core.assets.load', true)` → `require_once` → `getInstance()` → `installHooks()` →
  `doAction('app.core.assets.loaded')`. Place it **before** the headless return at
  `index.php:122` so CLI and headless callers can still enqueue.
- **Hook listeners** take `$ctx = []` (actions) or `($cur_val, $ctx = [])` (filters) — see
  `CLAUDE.md` 29a.

---

## Two core changes with blast radius beyond assets

Scope, review and test these **separately** from the assets queue.

1. **Widen `Dj_App_HTML::escUrl()`** (`html.php:866-895`) to accept protocol-relative `//host`.
   Additive: `/`, `http://`, `https://` behavior is unchanged, so existing callers keep
   working. It must still reject `javascript:`, `data:`, and the backslash variants (`\\host`,
   `/\host`) that some browsers normalize into a scheme-relative URL. Needs its own test cases.
2. **A filter in `renderPage()`** (`html.php:346`) feeding `head_content` / `footer_content`, so
   registered assets reach error-page output. `renderPage()` is a self-contained terminal
   renderer used by the fatal handler (`index.php:642`) and `Dj_App_Util::die()`
   (`util.php:697`); it never fires `app.page.full_content` or the head/body hooks. State the
   caveat plainly: a broken plugin asset can then also affect the page that reports the breakage.

---

## Tests

`tests/unit_tests/lib/Assets_Test.php`, class `Dj_App_Assets_Test`, PHPUnit 12, no bootstrap of
its own (`tests/bootstrap.php` loads the root `index.php` headless — so `assets.php` must be
wired into `index.php` to be testable at all).

⚠️ **`tearDown()` must call `removeAll()`.** The singleton is process-wide; without a reset the
suite becomes order-dependent. `Dj_App_Shortcode::setShortcodes()` is the existing precedent
for a test-reset seam.

`tests/unit_tests/data/theme/style.css` already exists and serves as the `filemtime` fixture.

**Cases:**

- kind inferred from extension; kind sniffed from content; `style` / `js` / `script` keys
- bare content wrapped; pre-wrapped content passed through untouched
- plugin + file → correct URL
- `?v=` present when the file exists, absent when it does not
- explicit `url` left untouched
- default placements (CSS head, JS footer); **JS forced to head via `placement`**
- priority ordering; stable sort within one priority; **default priority equals
  `Dj_App_Hooks::DEFAULT_PRIORITY`**
- **non-public plugin → inlined, not linked**
- conflicting source keys throw; missing file returns an error Result with its code
- **every alias resolves**: `attribs` behaves as `attrs`, `script` as `js`, `buffer`/`data` as
  `content` — and a dashed spelling resolves the same as an underscored one
- **`attrs` render on the tag**; a `true` value renders bare (`defer`), a string renders as
  `key="value"`, and values are escaped
- **duplicate handling both ways**: identical content with no id emits once; the same explicit
  id twice is last-write-wins **and keeps its queue position**
- **`add()` returns a Result whose `id` round-trips into `remove()`**
- `remove()` on an unqueued id is a no-op success
- **`deregister()` by params finds the same item as by id**
- **a custom id is still removable by the params that created it**
- `replace()` preserves position; `removeAll()` empties the queue
- dedupe by content hash; head vs footer routing
- each hook fires: `add_params` rewrites, `item` returning empty vetoes, `tag_html` stamps an
  attribute, `queue` / `html` see the assembled set
- `getContentUrl()` with no ctx / plugin / theme
- `escUrl()` accepts `//host` and still rejects `javascript:` / `data:` / `\\host`

---

## Suggested implementation order

1. `getContentUrl($ctx = [])` + `getContentDirUrl()` forwarder, with tests. Independent and
   useful on its own.
2. `escUrl()` widening, with tests. Independent; review as a security change.
3. `Dj_App_Assets` — tests first, then the class, then the `index.php` registration.
4. The `renderPage()` filter for error pages.
5. Migrate `djebel-login`'s submit-feedback script from an inline echo to a registered asset —
   the end-to-end proof, and the case that prompted all of this.

## Verification

- `cd tests && ./vendor/bin/phpunit` — full suite green. Baseline before this work:
  **899 tests / 2634 assertions**.
- `php -l` and `php ~/.claude/hooks/rule_lint.php` on every touched file.
- Live: the dashboard login page still renders its script and still logs in.
