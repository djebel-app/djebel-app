# Testing

How to RUN the suites lives in `tests/readme_tests.md` — it ships with the repo and is
written for whoever is holding the keyboard. This file is the set of rules for WRITING
tests and test infrastructure here.

## One runner, for the framework and every addon

```bash
php tools/test.php [addon-dir ...] [options]
```

The dir is optional and defaults to the current directory, so running it from inside an
addon needs no argument. Absolute, relative, `~/` and `$HOME` all work. Point it at the
djebel-app dir and it runs the framework's own suite.

## An addon ships test files ONLY

Never add a `tests/bootstrap.php` to a plugin, theme or lib. `tests/addon_bootstrap.php`
does the setup once — framework headless, then the addon — and the runner wires it up.

This is not a style preference. Before it existed there were **four byte-identical
40-line bootstraps** copy-pasted across libs, each differing only in its final `require`,
plus two plugin suites (45 tests) that could not be run at all because their bootstrap had
gone missing. A per-addon bootstrap drifts and then rots.

Test files load nothing themselves. The framework and the addon are already loaded when
they run.

## Never invent a per-addon "am I testing" env var

```php
// ✅ CORRECT — nothing to set, true under any runner, in any repo
if (Dj_App_Env::isInRunningUnitTests()) {
    return; // e.g. a CLI tool: define the classes, skip the main flow
}

// ❌ WRONG — needs a per-addon bootstrap to set it, which is the duplication above
$is_testing = getenv('DJEBEL_APP_PLUGIN_MY_THING_TESTING');
```

`Dj_App_Env::isInRunningUnitTests()` checks `PHP_SAPI`, the two PHPUnit constants, then
the argv fallback. Note the constants are OR'd, not AND'd — no install defines both, so
`&&` would make the branch dead and leave detection resting on argv alone.

## Where tests go, and what they are called

- `tests/unit_tests/` is probed first, then `tests/`. Both work.
- **One test file per class, named after the class** — `Djebel_App_Plugin_Markdown_Test.php`
  for `Djebel_App_Plugin_Markdown`. Never name a file after a feature, a bug, or a method.
  New methods on the same class get new test methods in the SAME file.
- A method name may not weld two jobs with `And` — the shared lint rejects it.

## Use semantic assertions

Choose the assertion that states the intent; the failure message is only as good as the
assertion that produced it.

| Use | Instead of |
|---|---|
| `assertEmpty($v)` | `assertEquals('', $v)`, `assertEquals([], $v)` |
| `assertNotEmpty($v)` | `assertNotEquals('', $v)` |
| `assertTrue($v)` / `assertFalse($v)` | `assertEquals(true, $v)` |
| `assertNull($v)` | `assertEquals(null, $v)` |
| `assertCount(5, $arr)` | `assertEquals(5, count($arr))` |
| `assertContains($x, $arr)` | `assertTrue(in_array($x, $arr))` |
| `assertArrayHasKey($k, $arr)` | `assertTrue(isset($arr[$k]))` |
| `assertStringContainsString($n, $h)` | `assertTrue(strpos($h, $n) !== false)` |

**Exception — when the falsy TYPE is the point, say so.** `file_put_contents()` returns a
byte count that is legitimately `0`, so a fixture write is checked with
`assertNotFalse(...)`, never `assertNotEmpty(...)`.

## Assert your fixture setup

A test's own `file_put_contents` / `mkdir` / write is a mutating call like any other. An
unchecked fixture write that fails makes the test blow up somewhere far away, and the
failure points at the assertion under test instead of the setup.

```php
$write_res = file_put_contents($file, $content);
$this->assertNotFalse($write_res, 'Failed to write the markdown fixture');
```

## Restore any global state you touch

`backupGlobals` is off. A test that writes `$_SERVER` / `$_ENV` / `putenv` leaks into
every later test in the process. Bracket it: capture inside the `try` as the first
statement, restore in `finally`. Restore the saved value — never `unset()` a key the
bootstrap primed, or you break whatever runs next, order-dependently.

## Multi-byte cases belong in the suite

Anything that cuts, trims or measures a string gets a Cyrillic or emoji case. A byte-level
cut through a multi-byte character produces invalid UTF-8, which MySQL rejects outright
(error 1366) and `json_encode()` fails on — and no ASCII test will ever catch it.

```php
$this->assertNotFalse(mb_check_encoding($result, 'UTF-8'));
```

## Coverage follows the code, in the same change

A new function gets tests in the change that adds it. A modified function gets its
existing tests re-read and cases ADDED — coverage grows, it does not shift. A function
copied in from elsewhere brings its tests with it; the other repo's suite does not run
here.
