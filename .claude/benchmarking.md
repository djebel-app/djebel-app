# Benchmarking Djebel — never trust the host CLI

For a framework targeting 1,000,000 sites, perf claims get made often. This is how to
make one that holds up. **A number you did not sanity-check is speculation with decimal
places** — see the VERIFY BEFORE YOU ASSERT rule; "X is faster than Y" is a claim like
any other.

## 1. Measure in a Linux container, not on the dev box

Two distortions stack on a macOS host, and they do not just shift the numbers — they
change the ORDERING, so a conclusion drawn on the host can be backwards.

**Xdebug is loaded in the host CLI.** It inflated results ~4x and skewed the *ratios*
between operations. `-d xdebug.mode=off` is NOT enough — the extension is still loaded.
`php -n` is the minimum, and it also drops opcache.

**macOS syscalls are far slower than Linux.** The same `file_exists()` on a MISSING file:

| | macOS host | Linux container |
|---|---|---|
| `file_exists()`, file missing | ~3,400 ns | ~830 ns |

The stat cache does NOT cache negatives, so a miss is a real syscall every call. On macOS
that syscall looked as expensive as a whole config lookup; on Linux it is ~6x cheaper.
Any "syscall vs pure-PHP work" conclusion can invert between the two platforms.

## 2. The command

Bench scripts follow the tool env-var convention (`DJEBEL_APP_TOOL_{TOOL}_{VARIABLE}`),
so the lib dir is passed in rather than hard-coded to a host layout:

```
docker run --rm -e DJEBEL_APP_TOOL_BENCH_LIB_DIR=/app/src/core/lib \
  -v <djebel-app>:/app:ro -v <scripts>:/bench:ro \
  php:8.3-cli php -d opcache.enable_cli=1 /bench/<script>.php 200000
```

## 3. Bootstrapping core libs standalone

The real `Dj_App_Config` lives inside the app's index.php, which would boot the whole
application — so a bench script declares a stub with just `cfg()`, `replaceSystemVars()`
and the `APP_BASE_DIR` const. Load order matters:

```
string_util → env → util → file_util → hooks → options
```

`util` declares `Dj_App_Exception`, which the hooks and file_util layers extend at load
time; loading them earlier fatals.

## 4. Report minimums, not medians — and sanity-check the sign

Spread was ~1,000 ns (~19%) even inside the container. Minimums are least contaminated
by scheduler noise. Rotate the order of the arms across reps so warm-up and CPU drift
cannot favour whichever runs first.

**The sign is your noise detector.** If an arm doing *strictly more work* measures FASTER
than one doing less, the noise floor exceeds the effect and NO number from that run means
anything — say so instead of reporting the delta. A real three-way run produced an
impossible NEGATIVE for "more work minus less work" at the median while the minimums
stayed correctly ordered and matched theory.

**Put the delta in proportion.** A wrapper costing ~27 ns on a ~5,360 ns operation
(~0.5%) is a readability decision, not a perf one. Say that plainly rather than implying
two forms are exactly equal.

## 5. Profile stages before optimizing

Find the hotspot, don't guess it. Timing each step of a single-key option lookup in
isolation is what showed the dot-split — not the hook dispatches — was the cost:

| stage | ns | share |
|---|---|---|
| dot-split (`explode` + `array_map(formatKey)` + `implode`) | 2,349 | 49% |
| 4x `applyFilter` | 807 | 17% |
| `String_Util::trim` | 166 | |
| separator `str_replace` | 115 | |
| data `isset()` chain | 26 | |
| **FULL `get()`** | **4,836** | |

Keep the un-optimized stages in the script as CONTROLS when comparing before/after: if
they stay flat across both runs, the machine was not simply faster during the second one.

## 6. Prove an optimization is behaviour-preserving

A benchmark says it got faster. It does not say it is still correct.

- **Diff the output.** Dump results for a battery of inputs (edge cases, whitespace,
  case, separators, missing keys, non-scalars, repeated calls, post-mutation reads) from
  the old and new builds and diff them. Byte-identical or it is not equivalent.
- **Mutation-test the tests.** Deliberately introduce the bug the tests are supposed to
  catch and confirm they FAIL. Tests that pass on both the correct and broken versions
  guard nothing. Build the mutant in a scratch copy, never in the repo.

Real example: memoizing the parsed key in `get()` is only safe because it caches the
PARSE and never a VALUE. A mutant that also cached the value was caught by two tests —
one on stale values after `setData()`, one because a value-cache early-return silently
stopped the option filters from firing at all.
