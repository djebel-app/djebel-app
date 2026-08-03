# Running tests

One command runs everything — the framework and any addon (plugin, theme or lib):

```bash
php tools/testing/run.php [addon-dir ...] [options]
```

It prepares the framework headless, loads the addon, finds its test dir, and hands the
rest to PHPUnit. **An addon ships test files only** — no bootstrap, no plumbing, nothing
to keep in sync.

## The dir argument

Optional, and forgiving:

| You type | What happens |
|---|---|
| *nothing* | the current directory — run it from inside an addon and it just works |
| `.` | same thing, explicitly |
| `../../plugins/djebel-markdown` | relative, resolved against the current directory |
| `~/projects/djebel-markdown` | `~/`, `$HOME` and `${HOME}` all expand |
| `/abs/path/djebel-markdown` | absolute |
| the djebel-app dir | runs the **framework's own** suite |

Everyday use is therefore:

```bash
cd path/to/plugins/djebel-markdown
php ~/path/to/djebel-app/tools/testing/run.php
```

## Options

| Option | Purpose |
|---|---|
| `--entry=NAME` | Entry file inside the addon dir. Default: `plugin.php`, then `lib.php` |
| `--filter=NAME` | Only run tests matching NAME (passed to PHPUnit) |
| `--help`, `-h` | Usage |

`--entry` covers an addon whose classes do not live in the usual entry file — a CLI tool,
for instance:

```bash
php tools/testing/run.php ../some-plugin --entry=tools/stats.php
```

**A suite that needs a sibling addon loaded** lists both dirs, in load order:

```bash
php tools/testing/run.php ../djebel-markdown ../djebel-static-content
```

## Where tests go

`tests/unit_tests/` is probed first, then `tests/`. Both work; the nested one is probed
first because a `tests/` dir may also hold `vendor/` or a bootstrap, which PHPUnit would
otherwise scan.

Name the file after the class under test — `Djebel_App_Plugin_Markdown_Test.php` for
`Djebel_App_Plugin_Markdown`. Test files load nothing themselves; the framework and the
addon are already loaded when they run.

## Exit codes

| Code | Meaning |
|---|---|
| 0 | PHPUnit passed |
| PHPUnit's own | tests failed |
| 2 | usage error — bad dir, no entry file, no tests dir |
| 3 | PHPUnit is not installed (`cd tests && composer install`) |

## "Am I under test?" — never a per-addon env var

An addon must **not** invent its own testing flag (`DJEBEL_APP_PLUGIN_<NAME>_TESTING` and
friends). The framework already answers it, with nothing to set:

```php
if (Dj_App_Env::isInRunningUnitTests()) {
    return; // e.g. a CLI tool: define the classes, skip the main flow
}
```

It checks `PHP_SAPI`, the two PHPUnit constants, then the argv fallback — true under any
runner, in any repo, with no env var and no bootstrap cooperation. A per-addon flag has to
be set by a per-addon bootstrap, which is exactly the duplication this setup removes.

If an addon needs a *value* rather than a yes/no, pass it as an ordinary env var on the
same command line; the run inherits it:

```bash
MY_FIXTURE_DIR=/tmp/fixtures php tools/testing/run.php ../some-plugin
```

## Running PHPUnit directly

The tool is a convenience over PHPUnit, not a replacement. The equivalent long form:

```bash
cd tests
DJEBEL_TEST_FILES=/abs/path/djebel-markdown/plugin.php \
  ./vendor/bin/phpunit --bootstrap ../tools/testing/bootstrap.php --no-configuration \
  /abs/path/djebel-markdown/tests/
```

`DJEBEL_TEST_FILES` is how a framework-owned bootstrap learns which addon to load:
PHPUnit owns `argv`, so a bootstrap cannot take arguments of its own and the environment
is the only channel. Absolute file paths, `:` separated. Leave it unset and only the
framework loads — which is what the framework's own suite needs.

The framework's own suite also still runs the plain way:

```bash
cd tests
./vendor/bin/phpunit                    # whole unit suite (phpunit.xml)
./vendor/bin/phpunit --filter Truncate unit_tests/lib/String_Util_Test.php
```

## Headless mode

`Dj_App_Config::cfg('a.b.c')` resolves an env var, trying `a.b.c`, then `A_B_C`, then
`DJEBEL_A_B_C`. So the run/serve gate is settable from the environment with no code change:

| Env var | Effect |
|---|---|
| `DJEBEL_APP_CORE_RUN=0` | load every class, serve nothing |
| `DJEBEL_APP_CORE_HEADLESS=1` | same gate, read as `app.core.headless` |
| `DJEBEL_APP_CORE_LOAD_LIBS=1` | eager-load the libs (needed by the lib suite) |

The framework test bootstrap already sets the first and the third, and `tools/testing/bootstrap.php`
requires it — so addon suites are headless without doing anything.

Any new toggle follows the same convention: read it with
`Dj_App_Config::cfg('app.core.my_flag')` and it is settable as `DJEBEL_APP_CORE_MY_FLAG`
with no parsing code.
