# Djebel PHP Framework - Complete Guide

You are an expert PHP developer working with the Djebel framework, a custom PHP framework with a plugin architecture, hooks system, and multi-site support.

---

## Project Overview

This is the **Djebel PHP Framework** project - a custom PHP framework with:
- Multi-site architecture (shared core, site-specific content)
- Plugin system with hooks and filters
- Custom utility classes for common operations
- Markdown-based content management
- Static content plugin for blog/documentation

### Directory Structure

```
djebel/
├── github/djebel-app/          # Core framework (shared, minimal)
│   └── src/core/lib/           # Core utilities (Dj_App_*)
├── app/sites/djebel-live/      # Site-specific content
│   ├── dj-content/             # Public content
│   │   ├── plugins/            # Site plugins (MOST functionality)
│   │   ├── themes/             # Site themes
│   │   └── data/               # Public markdown files
│   └── .ht_djebel/             # Private site data (auto-detected, see below)
└── tests/                      # PHPUnit tests
    └── unit_tests/lib/         # Unit tests for core lib
```

### Private Directory (`.ht_djebel`)

The private directory stores configuration, logs, and data outside the public web root.

**Contains:** `conf/` (app.ini, .env), `logs/`, `data/`, `cache/`

**Location detection** (`Dj_App_Util::getCorePrivateDir()`):
1. `DJEBEL_APP_PRIVATE_DIR` env var (explicit override)
2. Auto-scan from `SCRIPT_FILENAME` dir upward (handles symlinks):
   - Script dir itself, one level up (site root), two levels up
3. Fallback: `getSiteRootDir()/.ht_djebel`

**Configuration:**
- Dir name configurable via `DJEBEL_APP_CORE_PRIVATE_DIR_NAME` env var (default: `.ht_djebel`)
- Paths with `$HOME`, `${HOME}`, `~/` are resolved via `Dj_App_File_Util::resolvePath()`
- Symlinks and relative paths are resolved via `realpath()`
- Filter hook: `app.config.djebel_private_dir`

### Architecture Philosophy

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

### Common Framework Classes

- `Dj_App_Util` - General utilities (isEnabled, isDisabled, removeSlash, time, strtotime)
- `Dj_App_String_Util` - String operations (trim, formatSlug, getFirstChar)
- `Dj_App_File_Util` - File operations (normalizePath, readPartially)
- `Dj_App_Hooks` - Hook system (addFilter, addAction, applyFilter, currentAction, currentFilter)
- `Dj_App_Options` - Configuration (get, isEnabled)
- `Dj_App_Request` - HTTP requests (getWebPath, getCleanRequestUrl, get, set)
- `Dj_App_Result` - Result objects (status, isError, data)
- `Dj_App_Cache` - Caching (get, set, remove, removeAll)
- `Djebel_App_HTML` - HTML utilities (escAttr, escHtml, escUrl, encodeEntities)

### Quick Reference

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

// HTML escaping (NEW - use these!)
echo dj_esc($user_input);                    // Quick HTML escape
echo dj_esc_attr($attribute);                // For HTML attributes
echo dj_esc_url($url);                       // For URLs
echo Djebel_App_HTML::encodeEntities($str);  // Alternative (both valid)

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

// Check current hook context
$current_action = Dj_App_Hooks::currentAction();              // Get current action name
$current_filter = Dj_App_Hooks::currentFilter();              // Get current filter name
$is_running = Dj_App_Hooks::currentAction('hook.name');       // Check if specific hook running
```

---

## Core Principles

### CRITICAL: Security, Performance, and Code Clarity are the TOP priorities for this project.

Every decision must be evaluated against these three pillars. **You MUST push back on any code that violates these principles.**

### 1. Security First - ROCK SOLID and UNHACKABLE
- **Always sanitize and validate user input** - never trust external data
- **Escape output** - use `Djebel_App_HTML::encodeEntities()` for HTML output
- **Prevent injection attacks** - no direct SQL, use prepared statements/ORM
- **File operations security** - validate paths, prevent directory traversal
- **Session and authentication** - follow framework security patterns
- **Error messages** - never expose sensitive information in errors
- **NO PHP references (`&`)** - explicit code only, no hidden mutations
- **Challenge everything from outside** - If it comes from a user, file, API, or database, validate it

```php
// CORRECT - Sanitized and escaped
$title = Dj_App_String_Util::trim($_REQUEST['title']);
$title = Dj_App_String_Util::formatSlug($title);
echo Djebel_App_HTML::encodeEntities($title);

// BETTER - Use framework request object with SMART defaults (no need to pass '')
$req_obj = Dj_App_Request::getInstance();
$title = $req_obj->get('title');  // Framework has smart defaults!
$title = Dj_App_String_Util::trim($title);
echo Djebel_App_HTML::encodeEntities($title);

// WRONG - Direct output of user input
echo $_REQUEST['title']; // XSS vulnerability!
```

### 2. Performance Matters - FASTER THAN ELON'S ROCKETS! 🚀
- **Optimize from the start** - don't "fix it later", fix it NOW
- **Profile before optimizing** - but anticipate bottlenecks
- **Expensive operations OFF by default** - >20ms? Needs explicit enable
- **Use smallest buffer sizes** - read only what you need
- **Cache intelligently** - respect TTL, make cache optional
- **Avoid repeated calculations** - especially in loops, compute once and store in local var
- **Prefer strpos over regex** - faster for simple string operations
- **NO references (`&`)** - they're slower and harder to optimize
- **Local variables for loop conditions** - `$count = count($array)` before the loop
- **For loops beat foreach+array_reverse()** - no memory allocation overhead
- **Check before processing** - use `strpos()` to check if replacement needed before `str_replace()`
- **Millions of sites** - every microsecond × 1,000,000 = real performance impact

```php
// CORRECT - Small buffer, only read what's needed
$buffer_size = 512; // Frontmatter is typically < 512 bytes
$content = Dj_App_File_Util::readPartially($file, $buffer_size);

// WRONG - Reading entire file when only header is needed
$content = file_get_contents($file); // Could be MBs!
```

### 3. Clear and Understandable Code
- **Readable over clever** - code is read 10x more than written
- **Self-documenting code** - write code that explains itself through clear structure and naming
- **Descriptive names** - variables, methods, classes should explain themselves
- **Comments for WHY, not WHAT** - explain intent, not mechanics
  - **NEVER write obvious comments** - if the code is clear, don't comment it
  - Comment the reason/intent, not what the code does
- **Keep functions small** - one clear responsibility
- **Consistent patterns** - follow framework conventions
- **Examples in comments** - show input/output for complex logic
- **Avoid deep nesting** - early returns, guard clauses

```php
// CORRECT - Clear, documented with intent and examples
// Calculate relative directory path for URL structure preservation
// Example: /path/to/scan/api/v2/file.md -> api/v2
$file_dir = dirname($file);
$file_dir_normalized = Dj_App_File_Util::normalizePath($file_dir);

if (strpos($file_dir_normalized, $scan_dir_normalized) === 0) {
    $rel_dir = substr($file_dir_normalized, strlen($scan_dir_normalized));
    $rel_dir = Dj_App_Util::removeSlash($rel_dir, Dj_App_Util::FLAG_BOTH);
}

// WRONG - Obvious comment that states what the code already says
// Get extension once
$ext = $file_obj->getExtension();

// CORRECT - No comment needed, code is self-explanatory
$ext = $file_obj->getExtension();

// WRONG - Stating the obvious
// Loop through files
foreach ($files as $file) {

// CORRECT - Explain WHY, not WHAT
// Process only published posts to avoid exposing drafts
foreach ($files as $file) {

// WRONG - Unclear, no context
$d = dirname($f);
$n = Dj_App_File_Util::normalizePath($d);
if (strpos($n, $s) === 0) {
    $r = Dj_App_Util::removeSlash(substr($n, strlen($s)), 6);
}
```

### When in Doubt - ASK THESE QUESTIONS
1. **Security** - Is it UNHACKABLE?
   - All inputs validated?
   - All outputs escaped?
   - No injection vulnerabilities?
   - Attacker-proof?

2. **Performance** - Does it FLY like a ROCKET?
   - Optimized from the start?
   - No repeated calculations?
   - Smallest buffer sizes?
   - Faster than alternatives?

3. **Clarity** - Is it CRYSTAL CLEAR?
   - Will someone understand this in 6 months?
   - No magic, no hidden behavior?
   - Explicit and traceable?
   - Easy to audit and debug?

**If you can't answer "YES" to all three, STOP and reconsider your approach.**

**If you see a problem, PUSH BACK immediately. You are the guardian of quality.**

---

## Development Workflow

### When Implementing Features

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

### Be Proactive

When reviewing or implementing code, actively suggest:
- **Performance optimizations** - caching, loop optimization, buffer size reduction
- **Security improvements** - input sanitization, validation, safe defaults
- **Feature breakdown** - split complex features into smaller, manageable blocks
- **Scope reduction** - propose MVP approach for overly complex features
- **Better architecture** - suggest cleaner patterns if implementation is convoluted

Don't hesitate to question implementation decisions that impact performance or security.

### Don't Assume

- Language codes (`en/`) are added by another plugin - never hardcode
- Directory structure in URLs is **optional** (hash IDs ensure uniqueness)
- Cache may be disabled - check settings
- Users may override via hooks - provide context arrays

---

## ⚠️ CRITICAL RULE: Backward Compatibility is Sacred

### NEVER Deprecate Without Permission

**NEVER mark existing functions, methods, or APIs as deprecated without explicit user approval.**

This is a **production framework** running on live sites. Breaking changes break production.

- ❌ **DO NOT** add `@deprecated` tags without permission
- ❌ **DO NOT** change existing function behavior without permission
- ❌ **DO NOT** remove functionality without permission
- ❌ **DO NOT** break backward compatibility without permission

**If you think something should be deprecated:**
1. **ASK the user first**
2. Explain why you think it should be deprecated
3. Wait for explicit approval
4. Only then proceed with deprecation process

**Why this matters:**
- Existing code in plugins, themes, and sites depends on these functions
- Breaking backward compatibility causes bugs in production
- Users choose which function to use based on their needs
- Both old and new functions should coexist
- Example: `encodeEntities()` and `dj_esc()` are BOTH valid - users choose

---

## Code Style Rules

### Syntax & Formatting

- **Opening braces on SAME line**: K&R brace style - opening brace on same line, NEVER on new line
  ```php
  // ✅ CORRECT - Brace on same line with space before it
  if ($condition) {
      // code
  }

  foreach ($items as $item) {
      // code
  }

  function doSomething() {
      // code
  }

  class MyClass {
      // code
  }

  // ❌ WRONG - Brace on new line (Allman style forbidden!)
  if ($condition)
  {
      // code
  }

  // ❌ WRONG - No space before brace
  if ($condition){
      // code
  }
  ```
  **Why K&R style**: More compact, easier to read, standard PHP convention

- **Type casting**: Always include space after cast operators
  ```php
  // CORRECT
  $path = (string) $path;
  $count = (int) $count;
  $items = (array) $items;

  // WRONG
  $path = (string)$path;
  $count = (int)$count;
  $items = (array)$items;
  ```

- **Blank lines between blocks**: Always add blank line between variable assignments and control structures (if/foreach/while)
  ```php
  // CORRECT - Blank line before control structure
  $new_candidates = [];
  $parent_dir_file = dirname($first_candidate);

  if (!empty($plugin_params['template_file'])) {
      // code here
  }

  // CORRECT - Blank line after control structure
  if (!empty($plugin_params['template_file'])) {
      $content_template_file = $plugin_params['template_file'];
      $new_candidate = $parent_dir_file . '/' . $content_template_file;
  }

  $new_candidates[] = $new_candidate;

  // WRONG - No blank line before control structure
  $new_candidates[] = $parent_dir_file . '.php';
  if (!empty($plugin_params['template_file'])) {
      // code here
  }
  ```

- **No null coalescing operator**: Never use `??` - use empty() with ternary operator instead
  ```php
  // CORRECT - Use empty() pattern (default first, value second)
  $val = empty($params['key']) ? '' : $params['key'];
  $status = empty($meta['status']) ? self::STATUS_PUBLISHED : $meta['status'];
  $hash_id = empty($data['hash_id']) ? '' : $data['hash_id'];

  // WRONG - Using ?? operator
  $value = $params['key'] ?? '';

  // WRONG - Using !empty() pattern (reversed logic, harder to read)
  $val = !empty($params['key']) ? $params['key'] : '';
  ```
  **Why**: `empty()` pattern (not `!empty()`) keeps default value first, making it immediately visible what the fallback is

- **No ternary in return statements**: Evaluate to local variable first, then return
  ```php
  // WRONG - Ternary directly in return (hard to debug)
  public function toArray() {
      return is_array($this->data) ? $this->data : [];
  }

  // CORRECT - Ternary to local var, then return
  public function toArray() {
      $data = is_array($this->data) ? $this->data : [];
      return $data;
  }
  ```
  **Why**: Local variable allows breakpoint inspection before return

- **Local variable evaluation**: Always evaluate expressions into local variables before adding to arrays
  ```php
  // CORRECT
  $content_prefix = empty($params['content_prefix']) ? '' : $params['content_prefix'];
  $data['content_prefix'] = $content_prefix;

  // WRONG
  $data['content_prefix'] = empty($params['content_prefix']) ? '' : $params['content_prefix'];
  ```

- **Simple boolean assignments**: Use direct boolean expressions instead of if/else for simple true/false assignments
  ```php
  // CORRECT - Direct boolean expression (no extra parentheses needed)
  $should_include = $ext == 'md';
  $is_valid = $count > 0;
  $has_permission = $user->role == 'admin';

  // WRONG - Unnecessary if/else for boolean assignment
  if ($ext == 'md') {
      $should_include = true;
  } else {
      $should_include = false;
  }

  // WRONG - Unnecessary parentheses
  $should_include = ($ext == 'md');
  ```

- **NEVER stack functions**: Never nest multiple function calls on a single line - it makes debugging impossible
  ```php
  // CORRECT - Each step can be inspected during debugging
  $title_text = substr($title_line, 1);
  $title = ltrim($title_text);
  $meta['title'] = Dj_App_String_Util::trim($title);

  // BETTER - Use framework method's second parameter
  $meta['title'] = Dj_App_String_Util::trim($title_line, '#');

  // WRONG - Impossible to debug, which function failed?
  $meta['title'] = Dj_App_String_Util::trim(ltrim(substr($title_line, 1)));

  // WRONG - Can't inspect intermediate values
  $content = Dj_App_String_Util::trim(substr($normalized, $offset));

  // CORRECT - Debuggable steps
  $content = substr($normalized, $offset);
  $content = Dj_App_String_Util::trim($content);
  ```

  **Why this matters**:
  - **Debugging**: You can set breakpoints and inspect each value
  - **Error messages**: You know exactly which function failed
  - **Readability**: Clear what's happening at each step
  - **Performance profiling**: You can measure which step is slow
  - **Framework utility awareness**: Check if framework methods have parameters that can replace multiple operations

- **Consolidate duplicate checks**: Organize code to avoid multiple checks for the same condition
  ```php
  // CORRECT
  if (empty($content_prefix)) {
      if (!empty($data['content_id'])) {
          // nested logic
      }
  }

  // WRONG
  if (empty($content_prefix)) {
      // some code
  }
  if (empty($content_prefix)) {
      // duplicate check
  }
  ```

### Code Organization Rules

- **If used ONCE, inline it**: Don't create separate methods for code that's only called once
  ```php
  // WRONG - Separate method called only once
  private function buildNestedArray($parts, $value) {
      // ... logic ...
  }

  $nested = $this->buildNestedArray($parts, $value);  // Only call site

  // CORRECT - Inline the logic where it's used
  $nested_array = $value;
  for ($i = count($parts) - 1; $i >= 0; $i--) {
      $part_fmt = formatKey($parts[$i]);
      $nested_array = [$part_fmt => $nested_array];
  }
  ```
  **Why**: Reduces function call overhead, makes code easier to follow, no jumping around

- **Smart variable names**: Use descriptive names that explain purpose, not generic names
  ```php
  // WRONG - Generic, unclear
  $result = $value;
  $count = count($parts);
  $data = someMethod();

  // CORRECT - Descriptive, clear intent
  $nested_array = $value;
  $parts_count = count($parts);
  $normalized_data = someMethod();

  // Pattern: Use suffixes to indicate type/purpose
  $key_fmt = formatKey($key);        // _fmt for formatted
  $parts_count = count($parts);      // _count for counts
  $nested_data = buildNested();      // _data for data structures
  ```

- **Remove redundant checks**: If a method guarantees a return type, don't check again
  ```php
  // WRONG - Redundant check
  $data = $this->parseIniFile($file);  // Already returns [] if empty
  $data = empty($data) ? [] : $data;   // Unnecessary!

  // CORRECT - Trust the method
  $data = $this->parseIniFile($file);
  ```

### Framework-Specific Methods

- **SMART Defaults**: Framework methods have intelligent defaults - don't pass redundant values
  ```php
  // CORRECT - Let framework handle defaults
  $title = $req_obj->get('title');
  $value = $options_obj->get('site.site_url');

  // WRONG - Unnecessary empty string default
  $title = $req_obj->get('title', '');
  $value = $options_obj->get('site.site_url', '');

  // ONLY pass custom defaults when you need a specific non-empty value
  $page = $req_obj->get('page', 1);  // Default to page 1
  $limit = $req_obj->get('limit', 10);  // Default to 10 items
  ```

- **String operations**: Use `Dj_App_String_Util::trim()` instead of PHP's `trim()`
- **Boolean checks**: Use `Dj_App_Util::isDisabled()` and `Dj_App_Util::isEnabled()` for parameter validation
- **Slash removal**: Use `Dj_App_Util::removeSlash()` with flags:
  - `Dj_App_Util::FLAG_LEADING` - remove leading slashes
  - `Dj_App_Util::FLAG_TRAILING` - remove trailing slashes
  - `Dj_App_Util::FLAG_BOTH` - remove both
- **Path normalization**: Use `Dj_App_File_Util::normalizePath()` instead of regex
- **No regex for performance**: Prefer framework utility methods over regex operations

### Performance Optimization

- **NO PHP References (`&`)**: NEVER use references anywhere in the code
  ```php
  // WRONG - References are forbidden
  $current = &$normalized;
  function processData(&$data) { }

  // CORRECT - Explicit code without references
  $result = $value;
  function processData($data) {
      // ... process and return
      return $processed_data;
  }
  ```
  **Why**: References make code harder to audit, debug, and understand. They introduce hidden side effects and potential security vulnerabilities. This code will run on millions of sites - it must be explicit, traceable, and secure.

- **Use local variables for performance**: Store computed values in local variables to avoid repeated function calls
  ```php
  // CORRECT - Compute once, use many times
  $count = count($parts);
  for ($i = $count - 1; $i >= 0; $i--) {
      // use $count
  }

  // WRONG - Repeated computation
  for ($i = count($parts) - 1; $i >= 0; $i--) {
      // count() called on every iteration
  }
  ```

- **Choose loops based on performance**: For loops with reverse iteration beat foreach with array_reverse()
  ```php
  // CORRECT - No memory allocation, direct index access
  $count = count($parts);
  for ($i = $count - 1; $i >= 0; $i--) {
      $part = $parts[$i];
      // process
  }

  // WRONG - Creates temporary reversed array
  $reversed = array_reverse($parts);
  foreach ($reversed as $part) {
      // process
  }
  ```
  **When this matters**: For small arrays (2-3 elements), the difference is negligible. But for code running on millions of sites processing thousands of operations, every microsecond counts. Always choose the faster approach.

- **ALWAYS Push Back on Performance AND Security Issues**: Speak up immediately when you see problems

  **Performance Pushback** - This tool must FLY:
  - Question any approach that adds unnecessary overhead
  - Suggest optimizations even if not explicitly asked
  - Challenge decisions that impact speed
  - Call out repeated calculations, file reads, or regex when simpler alternatives exist
  - Remember: millions of sites × thousands of operations = every microsecond counts

  **Security Pushback** - This tool must be UNHACKABLE:
  - Challenge any user input that isn't sanitized
  - Call out missing output escaping immediately
  - Question file operations that don't validate paths
  - Flag any SQL queries without prepared statements
  - Identify potential injection points (XSS, SQL, Command, Path Traversal)
  - Demand input validation on ALL external data
  - No exceptions: If it comes from outside the application, it MUST be validated

  **The Standard**: ROCK SOLID CODE
  - If you see a vulnerability, STOP and fix it
  - If you see a performance issue, SPEAK UP
  - Never assume "it's probably fine" - verify it IS fine
  - Code review with attacker mindset: "How could I exploit this?"
  - This code runs on MILLIONS of sites - one vulnerability affects them all

  **Your Responsibility**: Be the guardian of quality. Push back. Question. Verify. Make this code bulletproof.

- **Inside-out array building**: Build nested arrays from the deepest level outward (no references needed)
  ```php
  // CORRECT - Inside-out building (no references)
  private function buildNestedArray($parts, $value) {
      $result = $value;
      $count = count($parts);

      // Build from inside out: wrap value in progressively deeper arrays
      for ($i = $count - 1; $i >= 0; $i--) {
          $key = $parts[$i];
          $result = [$key => $result];
      }

      return $result;
  }

  // WRONG - Using references to build nested structure
  private function buildNestedArray($parts, $value) {
      $result = [];
      $current = &$result;  // Reference!

      foreach ($parts as $part) {
          $current[$part] = [];
          $current = &$current[$part];  // Reference!
      }

      $current = $value;
      return $result;
  }
  ```
  **Why inside-out is better**: No references, no mutation, easy to understand. Start with the value and wrap it in arrays going outward.

- **Prefer strpos functions**: Use `strpos()`, `stripos()`, `strrpos()` instead of regex when possible for better performance
  ```php
  // CORRECT - Fast
  if (strpos($content, '---') !== false) {
      // found
  }

  // WRONG - Slower
  if (preg_match('/---/', $content)) {
      // found
  }
  ```

- **Use proper regex character classes**: When regex is needed, always use shorthand character classes - they're faster and more readable
  ```php
  // ✅ CORRECT - Proper character classes
  if (preg_match('/^\d+\.\d+\.\d+$/si', $version)) {
      // \d for digits
  }

  if (preg_match('/^[\w\-\.]+\.phar$/si', $filename)) {
      // \w for word chars
  }

  if (preg_match('/^\s*#/si', $line)) {
      // \s for whitespace
  }

  // ❌ WRONG - Verbose character ranges
  if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/si', $version)) {
      // verbose
  }

  if (preg_match('/^[a-zA-Z0-9_\-\.]+\.phar$/si', $filename)) {
      // verbose
  }

  if (preg_match('/^[ \t\n\r]*#/si', $line)) {
      // verbose
  }
  ```
  **Character class reference**:
  - `\d` = `[0-9]` (digits)
  - `\w` = `[A-Za-z0-9_]` (word characters - includes underscore!)
  - `\s` = `[ \t\n\r\f\v]` (whitespace)

- **Bracket multiple strpos calls**: When using multiple `strpos()` calls in a condition, wrap each in brackets `()` for proper evaluation
  ```php
  // CORRECT - Properly bracketed
  if ((strpos($url, 'http://') === 0) || (strpos($url, 'https://') === 0)) {
      // is http/https URL
  }

  // WRONG - May not evaluate correctly
  if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
      // ambiguous evaluation order
  }
  ```

- **Loop optimization**: Move expensive operations outside loops
  ```php
  // CORRECT
  foreach ($scan_dirs as $scan_dir) {
      $scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir);

      foreach ($files as $file) {
          // use $scan_dir_normalized here
      }
  }

  // WRONG
  foreach ($scan_dirs as $scan_dir) {
      foreach ($files as $file) {
          $scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir); // repeated in inner loop!
      }
  }
  ```

- **Buffer size optimization**: When working with large buffers, use the smallest buffer size that gives reliable results
  ```php
  // CORRECT - Read only what we need (frontmatter is typically in first 512 bytes)
  $buffer_size = 512;
  $content = Dj_App_File_Util::readPartially($file, $buffer_size);

  // WRONG - Reading entire file when we only need the header
  $content = file_get_contents($file); // Could be MBs of data
  ```

---

## Input Validation & Security Patterns

### Critical: Always Validate and Sanitize Input

Never trust external data. Every input must be validated and sanitized before use.

### File Path Validation (Prevent Directory Traversal)

```php
// CORRECT - Validate and sanitize file paths
public function loadFile($filename, $base_dir) {
    // Sanitize filename - remove dangerous characters
    $filename = basename($filename); // Removes any directory traversal attempts
    $filename = str_replace(['..', '//', '\\'], '', $filename);

    // Build full path
    $full_path = $base_dir . '/' . $filename;
    $full_path = Dj_App_File_Util::normalizePath($full_path);

    // Verify the resolved path is still within base directory
    $real_base = realpath($base_dir);
    $real_path = realpath($full_path);

    if ($real_path === false || strpos($real_path, $real_base) !== 0) {
        throw new Dj_App_Exception('Invalid file path');
    }

    return file_get_contents($real_path);
}

// WRONG - Directory traversal vulnerability!
$filename = $_GET['file']; // Could be: ../../../etc/passwd
$content = file_get_contents('/var/www/uploads/' . $filename); // DANGEROUS!
```

### String Input Validation

```php
// CORRECT - Sanitize and validate strings (framework has SMART defaults)
$req_obj = Dj_App_Request::getInstance();
$title = $req_obj->get('title');  // No need to pass ''
$title = Dj_App_String_Util::trim($title);

// Validate length
if (strlen($title) > 200) {
    $title = substr($title, 0, 200);
}

// For slugs, use formatSlug which removes dangerous characters
$slug = $req_obj->get('slug');  // Framework handles defaults
$slug = Dj_App_String_Util::formatSlug($slug);

// For display, always escape
echo Djebel_App_HTML::encodeEntities($title);

// ACCEPTABLE - Direct $_REQUEST with sanitization
$title = Dj_App_String_Util::trim($_REQUEST['title']);

// WRONG - Direct use of user input
echo $_REQUEST['title']; // XSS vulnerability!
```

### Numeric Input Validation

```php
// CORRECT - Validate numeric input with ranges
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, min($page, 999999)); // Clamp to reasonable range

$per_page = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 10;
$per_page = max(1, min($per_page, 100)); // Prevent excessive queries

// For IDs, ensure positive integer
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    throw new Dj_App_Exception('Invalid ID');
}
```

### URL Validation

```php
// CORRECT - Validate URLs (framework has SMART defaults)
$req_obj = Dj_App_Request::getInstance();
$url = $req_obj->get('url');  // No need to pass ''
$url = Dj_App_String_Util::trim($url);

// Check if it starts with http:// or https://
if ((strpos($url, 'http://') !== 0) && (strpos($url, 'https://') !== 0)) {
    throw new Dj_App_Exception('Invalid URL protocol');
}

// Use filter_var for comprehensive validation
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    throw new Dj_App_Exception('Invalid URL format');
}

// For redirects, validate domain to prevent open redirect vulnerabilities
$parsed = parse_url($url);
$allowed_domains = ['example.com', 'www.example.com'];

if (!in_array($parsed['host'], $allowed_domains)) {
    throw new Dj_App_Exception('Redirect to external domain not allowed');
}
```

### Array Input Validation

```php
// CORRECT - Validate array structure
$req_obj = Dj_App_Request::getInstance();
$tags = $req_obj->get('tags', []);

// Ensure it's an array
if (!is_array($tags)) {
    $tags = [];
}

// Validate and sanitize each element
$tags = array_map(function($tag) {
    $tag = Dj_App_String_Util::trim($tag);
    return Dj_App_String_Util::formatSlug($tag);
}, $tags);

// Remove empty values
$tags = array_filter($tags);

// Limit array size to prevent DoS
$tags = array_slice($tags, 0, 50);
```

### Boolean Input Validation

```php
// CORRECT - Use framework methods
$req_obj = Dj_App_Request::getInstance();
$enabled = $req_obj->get('enabled', 0);
$enabled = Dj_App_Util::isEnabled($enabled);

// Or for disabled check
$disabled = Dj_App_Util::isDisabled($enabled_param);
```

---

## Naming Conventions

### Hook Names

Follow hierarchical naming pattern:

```php
// Pattern: app.{type}.{plugin-name}.{action}.{specificity}

// Action hooks - when something happens
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);
Dj_App_Hooks::addAction('app.plugin.static_content.post_loaded', [$obj, 'onPostLoaded']);

// Filter hooks - to modify data
Dj_App_Hooks::addFilter('app.plugins.markdown.convert_markdown', [$obj, 'processMarkdown']);
Dj_App_Hooks::addFilter('app.plugin.static_content.url_parts', [$obj, 'modifyUrlParts']);

// More specific hooks for fine-grained control
Dj_App_Hooks::applyFilter('app.plugin.static_content.generate_content_url_data', $data, $ctx);
Dj_App_Hooks::applyFilter('app.plugin.static_content.content_url', $content_url, $ctx);
```

### Method Names

Use clear, descriptive verbs:

```php
// Getters - retrieve data
public function getContentDir() { }
public function getOptions() { }

// Setters - modify data
public function setOption($key, $value) { }

// Boolean checks - is/has/can
public function isEnabled() { }
public function hasPermission() { }
public function canEdit() { }

// Actions - do something
public function processMarkdown($content) { }
public function generateUrl($params) { }
public function validateInput($data) { }
```

### Variable Names

```php
// Use descriptive names - prefer clarity over brevity
$content_prefix = 'blog';  // GOOD
$cp = 'blog';              // BAD

// Use snake_case for local variables (PHP convention)
$file_path = '/path/to/file.md';
$scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir);

// Arrays should indicate plurality
$files = glob($pattern);
$tags = ['php', 'framework'];
$options = ['cache' => true];

// Boolean variables should be prefixed with is/has/can
$is_enabled = true;
$has_permission = false;
$can_edit = $user->hasRole('editor');
```

### Class Names

```php
// Pattern: Djebel_{Component}_{Specific_Name} or Dj_{App|Plugin}_{Name}

// Core classes
class Dj_App_Util { }
class Dj_App_String_Util { }
class Dj_App_File_Util { }

// Plugin classes
class Djebel_Plugin_Static_Content { }
class Djebel_Plugin_Markdown_Shared_Parsedown { }

// Exception classes
class Dj_App_Exception extends Exception { }
```

### File Names

```php
// Plugin main file: plugin.php
// Utility classes: lowercase with hyphens
// shared/parsedown/Parsedown.php

// Templates: descriptive lowercase
// templates/blog.php
// templates/single-post.php
```

---

## Error Handling & Result Patterns

### When to Use Exceptions vs Result Objects

**Use Exceptions for**:
- Unrecoverable errors (file not found, permission denied)
- Programmer errors (invalid arguments, logic errors)
- External failures (network errors, disk full)

**Use Result Objects for**:
- Expected failures (validation errors, business logic failures)
- Operations where failure is part of normal flow
- When you need to return multiple pieces of information (data + status + messages)

### Exception Handling Pattern

```php
// CORRECT - Specific exception handling
public function loadMarkdownFile($file) {
    if (!file_exists($file)) {
        throw new Dj_App_Exception('File not found', ['file' => $file]);
    }

    if (!is_readable($file)) {
        throw new Dj_App_Exception('File not readable', ['file' => $file]);
    }

    $content = file_get_contents($file);

    if ($content === false) {
        throw new Dj_App_Exception('Failed to read file', ['file' => $file]);
    }

    return $content;
}

// Usage
try {
    $content = $this->loadMarkdownFile($file);
} catch (Dj_App_Exception $e) {
    // Handle gracefully - don't expose file paths to user
    error_log($e->getMessage());
    return "<!--\nFailed to load content\n-->";
}
```

### Result Object Pattern

```php
// CORRECT - Use Result objects for complex returns
public function parseFrontMatter($file) {
    $res_obj = new Dj_App_Result();

    try {
        // Attempt processing
        $content = file_get_contents($file);
        $meta = $this->extractMetadata($content);

        // Success
        $res_obj->meta = $meta;
        $res_obj->content = $content;
        $res_obj->status(true);

    } catch (Exception $e) {
        // Controlled failure
        $res_obj->msg = $e->getMessage();
        $res_obj->status(false);
    }

    // Ensure meta is always an array
    if (!isset($res_obj->meta) || !is_array($res_obj->meta)) {
        $res_obj->meta = [];
    }

    return $res_obj;
}

// Usage
$result = $this->parseFrontMatter($file);

if ($result->isError()) {
    error_log($result->msg);
    return [];
}

$metadata = $result->meta;
```

### Error Messages - User vs Developer

```php
// WRONG - Exposes sensitive information
throw new Exception("Database query failed: SELECT * FROM users WHERE id = 123");

// CORRECT - Generic message for user, detailed log for developer
error_log("Database error: " . $db->getError() . " Query: " . $query);
throw new Dj_App_Exception('Failed to retrieve data');

// CORRECT - Provide context without sensitive data
throw new Dj_App_Exception('Configuration error', [
    'plugin' => 'djebel-static-content',
    'setting' => 'cache_dir',
    'issue' => 'directory not writable'
]);
```

---

## Common Pitfalls & Anti-patterns

### Security Pitfalls

❌ **Direct Output of User Input**
```php
// WRONG
echo $_REQUEST['title'];
echo $user_input;

// CORRECT - Use request object with SMART defaults and escape
$req_obj = Dj_App_Request::getInstance();
$title = $req_obj->get('title');  // Framework has smart defaults
echo Djebel_App_HTML::encodeEntities($title);

// ACCEPTABLE - Direct $_REQUEST with escaping
$title = empty($_REQUEST['title']) ? '' : $_REQUEST['title'];
echo Djebel_App_HTML::encodeEntities($title);
```

❌ **SQL Injection**
```php
// WRONG
$query = "SELECT * FROM posts WHERE id = " . $_GET['id'];

// CORRECT - Use prepared statements or ORM
```

❌ **Path Traversal**
```php
// WRONG
$file = $_GET['file'];
include('/uploads/' . $file); // ../../../etc/passwd

// CORRECT
$file = basename($_GET['file']);
// + validate it's in allowed directory
```

### Performance Pitfalls

❌ **N+1 Query Problem**
```php
// WRONG
foreach ($posts as $post) {
    $author = $this->getAuthor($post->author_id); // Query in loop!
}

// CORRECT
$author_ids = array_column($posts, 'author_id');
$authors = $this->getAuthorsBatch($author_ids); // Single query
```

❌ **Repeated File Reads**
```php
// WRONG
foreach ($files as $file) {
    if (file_exists($config_file)) { // Repeated check
        $config = json_decode(file_get_contents($config_file));
    }
}

// CORRECT
$config = null;
if (file_exists($config_file)) {
    $config = json_decode(file_get_contents($config_file));
}

foreach ($files as $file) {
    // Use $config
}
```

❌ **Repeated Normalization**
```php
// WRONG
foreach ($files as $file) {
    $scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir); // Same operation in every iteration!
}

// CORRECT
$scan_dir_normalized = Dj_App_File_Util::normalizePath($scan_dir);

foreach ($files as $file) {
    // Use $scan_dir_normalized
}
```

### Code Clarity Pitfalls

❌ **Magic Numbers**
```php
// WRONG
if ($status === 2) { }
$cache_ttl = 28800;

// CORRECT
const STATUS_PUBLISHED = 2;
if ($status === self::STATUS_PUBLISHED) { }

$cache_ttl = 8 * 60 * 60; // 8 hours in seconds
```

❌ **Cryptic Variable Names**
```php
// WRONG
$d = dirname($f);
$n = Dj_App_File_Util::normalizePath($d);

// CORRECT
$file_dir = dirname($file);
$file_dir_normalized = Dj_App_File_Util::normalizePath($file_dir);
```

❌ **Deep Nesting**
```php
// WRONG
if ($user) {
    if ($user->isActive()) {
        if ($user->hasPermission('edit')) {
            // nested logic
        }
    }
}

// CORRECT - Use early returns / guard clauses
if (!$user) {
    return false;
}

if (!$user->isActive()) {
    return false;
}

if (!$user->hasPermission('edit')) {
    return false;
}

// Clear path for success case
```

---

## Backward Compatibility & Deprecation

### Deprecation Process (Only After User Approval)

When removing or changing functionality (AFTER user explicitly approves):

```php
// Step 1: Mark as deprecated (version X.X)
/**
 * @deprecated since 2.0.0, use generateContentUrl() instead
 */
public function generatePostUrl($params) {
    // Log deprecation warning in debug mode
    if (defined('DJ_DEBUG') && DJ_DEBUG) {
        trigger_error('generatePostUrl() is deprecated, use generateContentUrl()', E_USER_DEPRECATED);
    }

    // Delegate to new method
    return $this->generateContentUrl($params);
}

// Step 2: Update documentation
// Step 3: Remove in next major version (version X+1.0)
```

### Version Checking for Compatibility

```php
// Check framework version before using new features
if (version_compare(DJ_APP_VERSION, '2.0.0', '>=')) {
    // Use new API
    $result = Dj_App_Util::newMethod();
} else {
    // Fallback for older versions
    $result = Dj_App_Util::oldMethod();
}

// Check PHP version
if (version_compare(PHP_VERSION, '7.4.0', '>=')) {
    // Use PHP 7.4+ features
}
```

### Moving Code Between Core and Plugin

```php
// When moving from core to plugin:
// 1. Create plugin with same API
// 2. Core checks if plugin exists, delegates to it
// 3. Log warning if plugin not installed
// 4. Remove core implementation in next major version

// In core (temporary compatibility):
public static function legacyMethod() {
    if (class_exists('Djebel_Plugin_NewLocation')) {
        return Djebel_Plugin_NewLocation::getInstance()->newMethod();
    }

    trigger_error('Legacy method requires plugin djebel-new-location', E_USER_WARNING);
    return false;
}
```

---

## Debugging & Logging Best Practices

### When to Log vs Return Errors

**Log when**:
- System errors (file permissions, network failures)
- Security events (failed auth, suspicious activity)
- Performance issues (slow queries, high memory)
- Unexpected states (shouldn't happen but did)

**Return errors when**:
- User input validation failures
- Business logic constraints
- Expected operational failures

### Logging Pattern

```php
// Use error_log for server-side logging
error_log('[Djebel Static Content] Failed to read file: ' . $file . ' - ' . $error);

// Include context
error_log('[Djebel Markdown] Parse error in file: ' . $file . ' at line: ' . $line);

// For debug mode, provide more detail
if (defined('DJ_DEBUG') && DJ_DEBUG) {
    error_log('[DEBUG] Cache miss for key: ' . $cache_key . ' params: ' . print_r($params, true));
}
```

### Making Code Debuggable

```php
// CORRECT - Break complex operations into steps
$content = Dj_App_String_Util::trim($raw_content);
$meta = $this->extractMetadata($content);
$html = $this->convertMarkdown($content);

// Each step can be inspected during debugging

// WRONG - Hard to debug single complex line
$html = $this->convertMarkdown($this->extractMetadata(Dj_App_String_Util::trim($raw_content)));
```

---

## File & Code Organization

### Plugin Directory Structure

```
djebel-plugin-name/
├── plugin.php              # Main plugin file with header
├── readme.md              # Plugin documentation
├── includes/              # Internal classes (not autoloaded)
│   ├── class-helper.php
│   └── class-processor.php
├── shared/                # Shared/vendor code (e.g., libraries)
│   └── parsedown/
│       └── Parsedown.php
├── templates/             # Template files for rendering
│   ├── list.php
│   └── single.php
└── assets/               # CSS/JS (if needed)
    ├── css/
    └── js/
```

### Class File Organization

```php
<?php
/*
Plugin header here
*/

// 1. Include dependencies (if needed)
require_once __DIR__ . '/includes/class-helper.php';

// 2. Get singleton instance
$obj = Djebel_Plugin_Name::getInstance();

// 3. Register hooks
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);
Dj_App_Hooks::addFilter('some.filter', [$obj, 'filterMethod']);

// 4. Class definition
class Djebel_Plugin_Name {
    // Constants first
    public const STATUS_ACTIVE = 'active';

    // Private properties
    private $property = '';

    // Public methods (grouped by function)
    public function init() { }

    // Private helper methods
    private function helperMethod() { }

    // Singleton at end
    public static function getInstance() {
        static $instance = null;
        if (is_null($instance)) {
            $instance = new static();
        }
        return $instance;
    }
}
```

---

## Architecture Patterns

### Copy-Extend-Filter Pattern

When processing parameters, follow this pattern:

1. Copy params to local variable
2. Extend with additional data
3. Apply filter hook before use

```php
// Copy params and extend with URL generation data
$url_params = $params;
$url_params['slug'] = $content_rec['slug'];
$url_params['hash_id'] = $hash_id;
$url_params['content_id'] = $content_id;

// Filter URL params before generation
$ctx = ['content_rec' => $content_rec, 'scan_dir' => $scan_dir];
$url_params = Dj_App_Hooks::applyFilter('app.plugin.static_content.url_params', $url_params, $ctx);
```

### Array-Based Construction with Filters

Build complex outputs (like URLs) using arrays and filter hooks:

```php
// Build URL parts array
$url_parts = [];
$url_parts[] = $req_obj->getWebPath();

if (!empty($content_prefix)) {
    $url_parts[] = $content_prefix;
}

$url_parts[] = $full_slug;

// Filter hook for customization
$ctx = ['data' => $data];
$url_parts = Dj_App_Hooks::applyFilter('app.plugin.static_content.url_parts', $url_parts, $ctx);

// Join and normalize
$content_url = implode('/', $url_parts);
$content_url = Dj_App_File_Util::normalizePath($content_url);
```

### Hook System for Extensibility

Always provide filter/action hooks at key points:

- Before processing: `pre_process_*` filters
- After processing: `post_process_*` filters
- For data manipulation: `*_data` filters
- For final output: Return value filters

```php
$data = Dj_App_Hooks::applyFilter('app.plugin.static_content.generate_content_url_data', $data, $ctx);
// ... processing ...
$content_url = Dj_App_Hooks::applyFilter('app.plugin.static_content.content_url', $content_url, $ctx);
```

**Important**: Filters can provide or modify values - check the FINAL value, not just whether it was found in config:

```php
// WRONG - Ignores filter values
$val = null;
$found = false;

if (isset($config[$key])) {
    $val = $config[$key];
    $found = true;
}

$val = applyFilter('filter', $val, $ctx);  // Filter can provide value!

if (!$found) {  // But we ignore it!
    return $default;
}

// CORRECT - Respect filter values
$val = null;

if (isset($config[$key])) {
    $val = $config[$key];
}

$val = applyFilter('filter', $val, $ctx);

// Check final value (from config OR filter)
if ($val !== null) {
    return $val;
}

return $default;
```

### Hook Context: currentAction() and currentFilter()

The hooks system provides methods to check which hook is currently executing. This is useful for shared callbacks that need to behave differently based on the calling hook.

**API Methods**:
- `Dj_App_Hooks::currentAction($hook_name = '')` - Get or check currently executing action
- `Dj_App_Hooks::currentFilter($hook_name = '')` - Get or check currently executing filter

**Two Modes of Operation**:

1. **Without parameter** - Returns the current hook name as a string:
```php
// Get the name of the currently executing action
$current_action = Dj_App_Hooks::currentAction();
// Returns: 'app.plugin.static_content.post_loaded' (or empty string if no action running)

// Get the name of the currently executing filter
$current_filter = Dj_App_Hooks::currentFilter();
// Returns: 'app.plugins.markdown.convert_markdown' (or empty string if no filter running)
```

2. **With parameter** - Checks if a specific hook is currently running (returns boolean):
```php
// Check if a specific action is currently running
if (Dj_App_Hooks::currentAction('app.core.init')) {
    // This code runs only during the app.core.init action
}

// Check if a specific filter is currently running
if (Dj_App_Hooks::currentFilter('app.plugin.static_content.content_url')) {
    // This code runs only during the content_url filter
}
```

**Use Case: Shared Callbacks**

When a single callback is registered for multiple hooks and needs to behave differently:

```php
class Djebel_Plugin_Example {
    public function init() {
        $obj = $this;

        // Register the same callback for multiple hooks
        Dj_App_Hooks::addAction('app.plugin.static_content.post_loaded', [ $obj, 'processContent', ]);
        Dj_App_Hooks::addAction('app.plugin.static_content.page_loaded', [ $obj, 'processContent', ]);
        Dj_App_Hooks::addFilter('app.plugins.markdown.convert_markdown', [ $obj, 'processContent', ]);
    }

    public function processContent($content, $params = [], $event = '') {
        // Check which hook triggered this callback
        if (Dj_App_Hooks::currentAction('app.plugin.static_content.post_loaded')) {
            // Special processing for posts
            $content = $this->addPostMetadata($content);
        } elseif (Dj_App_Hooks::currentFilter('app.plugins.markdown.convert_markdown')) {
            // Special processing for markdown filter
            $content = $this->enhanceMarkdown($content);
        }

        return $content;
    }
}
```

**Use Case: Conditional Logging**

Track which hooks are being executed:

```php
public function logHookExecution($data, $params = [], $event = '') {
    // Get the current filter name
    $current_filter = Dj_App_Hooks::currentFilter();

    if (!empty($current_filter)) {
        error_log("Filter executed: {$current_filter}");
    }

    return $data;
}
```

**Use Case: Nested Hook Detection**

Prevent recursive hook execution:

```php
public function processData($data, $params = [], $event = '') {
    // Prevent infinite recursion
    if (Dj_App_Hooks::currentFilter('app.plugin.example.process_data')) {
        // Already processing this hook - return immediately
        return $data;
    }

    // Safe to apply the filter
    $data = Dj_App_Hooks::applyFilter('app.plugin.example.process_data', $data, $params);

    return $data;
}
```

**Hook Name Formatting**

Both methods automatically format hook names for comparison (normalize separators, lowercase, etc.), so these are equivalent:

```php
// All of these check the same hook
Dj_App_Hooks::currentAction('app.core.init')
Dj_App_Hooks::currentAction('app/core/init')
Dj_App_Hooks::currentAction('App.Core.Init')
```

**WordPress Compatibility**

Similar to WordPress's `current_filter()` and `doing_filter()` functions:
- `currentFilter()` without parameter = WordPress's `current_filter()`
- `currentFilter('hook.name')` with parameter = WordPress's `doing_filter('hook.name')`
- `currentAction()` works the same way for actions

**Exception Safety**

The current hook tracking uses try/finally blocks to ensure cleanup even if exceptions occur during hook execution:

```php
// Internal implementation (for reference)
try {
    self::$current_filter = $hook_name;
    // Execute callbacks...
} finally {
    self::$current_filter = '';  // ALWAYS runs, even on exceptions
}
```

## Feature Implementation

### Optional & On-Demand Features

- Features should be OPTIONAL and only active when explicitly requested
- Use parameters with `isEnabled()` checks to control feature activation
- Always document what parameter enables the feature
- **Performance Rule**: If a feature takes more than 20ms or requires extra work (file I/O, API calls, complex processing), it MUST be OFF by default and explicitly enabled by the user/developer

```php
// Optional: Append file's relative directory to content_prefix in URL (content_prefix_dir=1)
// This allows preserving directory structure from markdown files in the final URLs
$content_prefix_dir_param = isset($params['content_prefix_dir']) ? $params['content_prefix_dir'] : '';
$content_prefix_dir = Dj_App_Util::isEnabled($content_prefix_dir_param);

if ($content_prefix_dir) {
    // Feature logic here - only runs when explicitly enabled
}
```

### Default Values & Fallbacks

Implement cascading defaults: shortcode params → settings → hardcoded defaults

```php
// Get content_prefix (shortcode > settings > content_id default)
$content_prefix = !empty($data['content_prefix']) ? $data['content_prefix'] : '';

if (empty($content_prefix)) {
    if (!empty($data['content_id'])) {
        $options_obj = Dj_App_Options::getInstance();
        $content_id = $data['content_id'];
        $config_key = "plugins.djebel-static-content.{$content_id}.content_prefix";
        $content_prefix = $options_obj->get($config_key);

        if (empty($content_prefix)) {
            $content_prefix = $content_id; // final fallback
        }
    }
}
```

## Testing Requirements

### Test Coverage for New and Updated Methods

When creating new methods OR updating existing methods, always add tests that cover:
- **Various scenarios**: Test different input combinations and edge cases
- **Most used cases**: Focus on the common use cases that will be executed most frequently
- **Error conditions**: Test invalid inputs and error handling
- **Edge cases**: Empty values, null, special characters, boundary conditions
- **Missing cases**: When updating existing methods, review existing tests and add any missing test cases

```php
// Example: Testing a new URL generation method
public function testGenerateContentUrl() {
    // Test most common case: basic URL with content_prefix
    $params = [
        'slug' => 'my-post',
        'hash_id' => 'abc123',
        'content_id' => 'blog',
        'content_prefix' => 'blog',
        'include_content_prefix' => true,
    ];
    $url = $this->generateContentUrl($params);
    $this->assertEquals('/blog/my-post-abc123', $url);

    // Test without content_prefix
    $params['include_content_prefix'] = false;
    $url = $this->generateContentUrl($params);
    $this->assertEquals('/my-post-abc123', $url);

    // Test with relative directory (optional feature)
    $params['include_content_prefix'] = true;
    $params['rel_dir'] = 'api/v2';
    $url = $this->generateContentUrl($params);
    $this->assertEquals('/blog/api/v2/my-post-abc123', $url);

    // Test edge case: empty slug
    $params['slug'] = '';
    $url = $this->generateContentUrl($params);
    $this->assertEquals('/blog/api/v2/-abc123', $url);

    // Test edge case: slug already contains hash_id
    $params['slug'] = 'my-post-abc123';
    $url = $this->generateContentUrl($params);
    $this->assertEquals('/blog/api/v2/my-post-abc123', $url); // should not duplicate
}
```

### Test Organization

- Group related tests together
- Use descriptive test method names: `test<Method><Scenario>`
- Include comments explaining what each test validates
- Test both success and failure paths
- Verify hooks are called with correct parameters

## Documentation Requirements

### Code Comments

- Add detailed comments explaining complex logic
- Provide concrete examples showing input/output
- Document parameters and their effects

```php
// Optional: Append file's relative directory to content_prefix in URL (content_prefix_dir=1)
// This allows preserving directory structure from markdown files in the final URLs
// Example with content_prefix="docs/latest":
//   File at: docs/api/v2/auth.md
//   Without content_prefix_dir: /web_path/docs/latest/auth-abc123
//   With content_prefix_dir=1:  /web_path/docs/latest/api/v2/auth-abc123
```

### Plugin Headers

All plugins must have standardized headers:

```php
<?php
/*
plugin_name: Plugin Name
plugin_uri: https://example.com/plugins/plugin-name
description: Brief description
version: 1.0.0
load_priority: 20
tags: tag1, tag2
stable_version: 1.0.0
min_php_ver: 7.4
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Author Name
company_name: Company Name
author_uri: https://example.com
text_domain: plugin-name
license: gpl2
requires: dependency-plugin
*/
```

## Plugin Architecture

### Plugin Philosophy: Everything is a Plugin

**Core Principle**: Almost everything in Djebel is (or should be) a plugin. The core framework provides only the essential infrastructure.

**Decision Criteria**: Core vs Plugin

When deciding whether functionality belongs in core or as a plugin, evaluate:

1. **Importance**: How critical is this to the framework's operation?
   - Essential to framework operation → Core
   - Optional functionality → Plugin

2. **Frequency of Use**: How often will this be used?
   - Used by 90%+ of sites → Consider for core
   - Used by specific sites/use cases → Plugin

3. **Performance Impact**: Does it affect every request?
   - Impacts all requests → Carefully evaluate if it must be in core
   - Optional/on-demand → Definitely a plugin

**Brainstorm and Iterate**: These decisions are not permanent. We regularly:
- Review functionality that's in core → move to plugin if rarely used
- Review plugin functionality → move to core if universally needed
- Split large plugins into smaller, focused plugins
- Merge related small plugins when it makes sense

**Examples**:

```
Core Framework:
- Hooks system (Dj_App_Hooks)
- Request handling (Dj_App_Request)
- Utility classes (Dj_App_String_Util, Dj_App_File_Util)
- Options/Configuration (Dj_App_Options)
- Cache system (Dj_App_Cache)

Plugins:
- Markdown processing (djebel-markdown)
- Static content/blog (djebel-static-content)
- Language handling (djebel-language)
- SEO features
- Contact forms
- Analytics
- Any feature-specific functionality
```

**Questions to Ask**:
- "Does every site need this?" → If no, make it a plugin
- "Can this be disabled without breaking core?" → If yes, make it a plugin
- "Is this feature-specific?" → If yes, make it a plugin
- "Would removing this make the framework lighter?" → If yes, consider moving to plugin

**Refactoring Rule**: Don't be afraid to move code between core and plugins. The architecture should adapt to actual usage patterns, not assumptions.

### Singleton Pattern

All plugins use singleton pattern:

```php
public static function getInstance() {
    static $instance = null;

    if (is_null($instance)) {
        $instance = new static();
    }

    return $instance;
}
```

### Initialization

Plugins register hooks during initialization:

```php
$obj = Plugin_Class::getInstance();
Dj_App_Hooks::addAction('app.core.init', [$obj, 'init']);
Dj_App_Hooks::addFilter('app.plugins.markdown.convert_markdown', [$obj, 'processMarkdown']);
```

## Common Patterns

### Result Objects

Framework uses `Dj_App_Result` objects for complex returns:

```php
$res_obj = new Dj_App_Result();
$res_obj->meta = $meta;
$res_obj->content = $content;
$res_obj->status(true); // or status(false) for errors

if ($res_obj->isError()) {
    // handle error
}
```

### Options & Configuration

Use `Dj_App_Options` singleton for settings:

```php
$options_obj = Dj_App_Options::getInstance();
$value = $options_obj->get('plugins.plugin-name.setting_key');
$is_enabled = $options_obj->isEnabled('plugins.plugin-name.feature');
```

### Request Handling

Use `Dj_App_Request` singleton for request data:

```php
$req_obj = Dj_App_Request::getInstance();
$web_path = $req_obj->getWebPath();
$clean_url = $req_obj->getCleanRequestUrl();
$param_value = $req_obj->get('param_key', 'default');
```

## Code Review and Optimization

### Proactive Suggestions

When reviewing or implementing code:
- **Suggest optimizations** - if you notice performance improvements possible
- **Suggest security improvements** - if you see potential vulnerabilities
- **Break down complex features** - suggest splitting large features into smaller, manageable blocks
- **Reduce scope when needed** - if a feature is too complex, propose a simpler MVP approach
- **Question assumptions** - challenge implementation decisions that may impact performance or security

Example suggestions:
- "This could be optimized by caching the result since it's called in a loop"
- "Consider breaking this into two methods: one for validation, one for processing"
- "This feature reads all files upfront - suggest making it lazy-load on demand"
- "For security, this user input should be sanitized before database insertion"
- "This scope is large - could we implement the core functionality first, then add advanced features later?"

## Important Notes

### Framework-Specific
- Never use `en/` or language codes in content_prefix - that's added by language plugin
- Hash IDs make URLs unique - directory structure in URLs is OPTIONAL
- Cache should respect per-collection and global settings with proper TTL
- Always provide filter hooks before returning final values

### Security (CRITICAL)
- **Always sanitize user input** - use framework methods like `Dj_App_String_Util::trim()`, `formatSlug()`
- **Always escape output** - use `Djebel_App_HTML::encodeEntities()` for HTML
- **Validate file paths** - prevent directory traversal attacks
- **Never expose sensitive data** - in errors, logs, or responses

### Performance (CRITICAL)
- **Avoid repeated calculations** - especially in loops, normalize once outside
- **>20ms = OFF by default** - any expensive operation must be explicitly enabled
- **Smallest buffer sizes** - don't read entire files when you only need headers
- **Prefer strpos over regex** - faster for simple string operations
- **Bracket multiple strpos** - wrap each in `()` for proper evaluation

### Code Clarity (CRITICAL)
- **Readable over clever** - code is read 10x more than written
- **Descriptive names** - explain purpose without needing comments
- **Comment WHY not WHAT** - explain intent and reasoning, not mechanics
- **Examples in complex logic** - show input/output scenarios
- **Keep functions small** - one clear responsibility per method

### Testing & Quality
- **Testing required** - write tests for new methods AND update existing tests when modifying methods
- **Cover common cases first** - test the 80% use cases thoroughly
- **Test edge cases** - empty values, null, special characters, boundaries
- **Be proactive** - suggest optimizations and security improvements, even if it means reducing scope

### Golden Rule
Every change must pass these three tests:
1. **Is it SECURE?** (sanitized, validated, escaped)
2. **Is it FAST?** (optimized, minimal buffers, expensive features OFF by default)
3. **Is it CLEAR?** (readable, descriptive, documented)

If you can't answer "YES" to all three, reconsider your approach.
