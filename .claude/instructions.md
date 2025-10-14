# Djebel Project Instructions

## Project Overview

This is the **Djebel PHP Framework** project - a custom PHP framework with:
- Multi-site architecture (shared core, site-specific content)
- Plugin system with hooks and filters
- Custom utility classes for common operations
- Markdown-based content management
- Static content plugin for blog/documentation

## ⚠️ CRITICAL PROJECT PRIORITIES

**Security, Performance, and Code Clarity are REALLY important for this project.**

Every code change MUST be evaluated against these three pillars:

1. **Security First** - Sanitize inputs, escape outputs, validate paths, prevent injections
2. **Performance Matters** - Optimize from start, >20ms features OFF by default, smallest buffers
3. **Clear & Understandable Code** - Readable over clever, descriptive names, explain WHY not WHAT

If you can't answer "yes" to "Is it secure?", "Is it fast?", and "Is it clear?" - reconsider your approach.

## Architecture Philosophy

**Almost Everything is a Plugin**

Djebel follows a minimal-core, maximum-plugin architecture:

- **Core**: Only essential infrastructure (hooks, utilities, request handling, cache)
- **Plugins**: Everything else (markdown, blog, language, SEO, forms, etc.)

**Key Decision**: Core vs Plugin?

Ask these questions:
1. **Does every site need this?** → If no, make it a plugin
2. **Can it be disabled without breaking core?** → If yes, make it a plugin
3. **Is it feature-specific?** → If yes, make it a plugin
4. **Would removing this make the framework lighter?** → If yes, consider moving to plugin

**Brainstorm and Iterate**: These decisions aren't permanent. We regularly:
- Move core → plugin if rarely used
- Move plugin → core if universally needed
- Split large plugins into focused ones
- Merge small related plugins

Don't be afraid to refactor. The architecture should adapt to actual usage, not assumptions.

## Directory Structure

```
djebel/
├── github/djebel-app/          # Core framework (shared, minimal)
│   └── src/core/lib/           # Core utilities (Dj_App_*)
├── app/sites/djebel-live/      # Site-specific content
│   ├── dj-content/             # Public content
│   │   ├── plugins/            # Site plugins (MOST functionality)
│   │   ├── themes/             # Site themes
│   │   └── data/               # Public markdown files
│   └── .ht_djebel/             # Private site data
└── .claude/
    └── prompts/
        └── djebel-coding-guide.md  # Coding standards
```

## Essential Reading

**ALWAYS** reference the coding guide before making changes:
**`.claude/prompts/djebel-coding-guide.md`**

Key points to remember:
1. **No `??` operator** - use `isset()` with ternary
2. **Framework methods** - use `Dj_App_*` utilities, not PHP builtins
3. **Space before braces** - `if ($x) {` not `if ($x){`
4. **Local vars first** - evaluate expressions before adding to arrays
5. **Performance** - move expensive operations outside loops
6. **Prefer strpos** - use `strpos()` over regex when possible
7. **Bracket multiple strpos** - wrap each `strpos()` in `()` for proper evaluation
8. **Optional features** - use `isEnabled()` checks, document clearly
9. **Hooks everywhere** - provide filter hooks for extensibility
10. **Write tests** - for new and updated methods, covering common cases and edge cases

## Common Framework Classes

- `Dj_App_Util` - General utilities (isEnabled, isDisabled, removeSlash, time, strtotime)
- `Dj_App_String_Util` - String operations (trim, formatSlug, getFirstChar)
- `Dj_App_File_Util` - File operations (normalizePath, readPartially)
- `Dj_App_Hooks` - Hook system (addFilter, addAction, applyFilter)
- `Dj_App_Options` - Configuration (get, isEnabled)
- `Dj_App_Request` - HTTP requests (getWebPath, getCleanRequestUrl, get, set)
- `Dj_App_Result` - Result objects (status, isError, data)
- `Dj_App_Cache` - Caching (get, set, remove, removeAll)

## Workflow

When implementing features:

1. **Consider architecture first** - Should this be in core or a plugin? (Almost always plugin)
2. **Read existing code** - understand patterns before changing
3. **Follow copy-extend-filter** - copy params, extend, filter before use
4. **Add filter hooks** - before returning values
5. **Optimize loops** - normalize/calculate outside loops
6. **Document with examples** - show input/output in comments
7. **Test cache behavior** - respect TTL and per-collection settings
8. **Write tests** - for new methods AND when updating existing methods (add missing test cases)
9. **Be proactive** - suggest optimizations and security improvements, even if it means reducing scope or breaking features into smaller blocks
10. **Brainstorm refactoring** - question whether code should move between core/plugin based on usage

## Testing

- Use PHP CLI for quick tests
- Clear cache when testing content changes
- Check both listing and single content views
- Verify URLs are generated correctly

### Writing Tests

When adding or updating methods, write tests covering:
- **Common use cases** - the scenarios that will run most frequently
- **Various scenarios** - different input combinations
- **Edge cases** - empty values, special characters, boundary conditions
- **Error conditions** - invalid inputs and error handling
- **Missing cases** - review existing tests when updating methods and add missing coverage

See `.claude/prompts/djebel-coding-guide.md` for detailed testing examples.

## Don't Assume

- Language codes (`en/`) are added by another plugin - never hardcode
- Directory structure in URLs is **optional** (hash IDs ensure uniqueness)
- Cache may be disabled - check settings
- Users may override via hooks - provide context arrays

## Be Proactive

When reviewing or implementing code, actively suggest:
- **Performance optimizations** - caching, loop optimization, buffer size reduction
- **Security improvements** - input sanitization, validation, safe defaults
- **Feature breakdown** - split complex features into smaller, manageable blocks
- **Scope reduction** - propose MVP approach for overly complex features
- **Better architecture** - suggest cleaner patterns if implementation is convoluted

Don't hesitate to question implementation decisions that impact performance or security.

## Quick Reference

```php
// Boolean checks
$enabled = Dj_App_Util::isEnabled($param);
$disabled = Dj_App_Util::isDisabled($param);

// Slash removal
$clean = Dj_App_Util::removeSlash($path, Dj_App_Util::FLAG_BOTH);

// String trimming
$trimmed = Dj_App_String_Util::trim($str);

// Path normalization
$normalized = Dj_App_File_Util::normalizePath($path);

// String search (prefer over regex)
if (strpos($content, '---') !== false) {
    // found
}

// Multiple strpos (bracket each one)
if ((strpos($url, 'http://') === 0) || (strpos($url, 'https://') === 0)) {
    // is http/https URL
}

// Hooks
$value = Dj_App_Hooks::applyFilter('hook.name', $value, $ctx);
```

Remember: The coding guide has comprehensive examples - **read it first!**
