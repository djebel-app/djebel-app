# String util — todo

`src/core/lib/string_util.php`.

- [ ] [P150] `Dj_App_String_Util::formatStringId()` keeps a `[`: its pattern opens the character
  class twice (`'/[^[a-z\d' …`, `string_util.php:475`), so `[` counts as an allowed character.
  `A[b]c` comes back as `a[b_c` where `a_b_c` is expected, and `formatSlug()`, which goes through
  the same line, returns `a[b-c`. Found 2026-11-17 (run on PHP 8.4, with no flags and with
  `KEEP_CASE`). The fix is to drop the second `[`; ids and slugs that hold a `[` today would
  change, so check the callers first.
