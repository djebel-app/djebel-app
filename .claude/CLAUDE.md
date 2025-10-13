# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## 10x PHP Engineering Philosophy

Djebel is developed by **10x PHP engineers** who live and breathe:

- **Performance optimization** — Every line of code is scrutinized for speed
- **Algorithm efficiency** — No wasteful operations, no redundant checks
- **Deep PHP knowledge** — Understanding language internals and behavior
- **Hyper-efficient code** — Simple, clean, auditable implementations

### Our Standards:
- **Zero tolerance for waste** — Removing redundant regex characters (`\w` already includes `_`!)
- **No magic, no references** — Clean, explicit code that can't be hacked
- **Professional patterns** — Always check function returns (like `preg_match`)
- **Speed-first decisions** — Explicit depth handling beats recursive calls
- **Security through simplicity** — Easy-to-audit code prevents vulnerabilities

**Result:** Djebel's core is optimized at the CPU instruction level. When you're targeting 1,000,000 sites, every microsecond counts.

**As Claude Code working on this project, I embody these principles in every line of code I write or review.**

## Development Commands

### Testing
- Run all tests: `cd tests && ./vendor/bin/phpunit`
- Run specific test suite: `cd tests && ./vendor/bin/phpunit --testsuite unit`
- Test configuration: `tests/phpunit.xml`

### Dependencies
- Test dependencies are managed in `tests/composer.json`
- Install test dependencies: `cd tests && composer install`

## Coding Standards (10x PHP Developer Rules)

Djebel is developed with **hyper-efficient 10x PHP engineering standards**. Every line is optimized for performance, readability, and security.

### Performance & Optimization Rules

1. **Know your regex**: `\w` already includes underscore `[A-Za-z0-9_]` - NEVER add `_` redundantly
   - ✅ CORRECT: `/[^\w\[\]\.\-]/si`
   - ❌ WRONG: `/[^\w\[\]\.\-_]/si` (redundant underscore!)

2. **No recursion for performance-critical code**: Explicit depth handling beats recursive calls
   - For a framework targeting 1,000,000 sites, function call overhead matters
   - Use explicit if/elseif chains for known depths (2-4 levels)

3. **Inline operations when faster**: Only normalize slashes when key contains them
   ```php
   if (strpos($key, '/') !== false) {
       $key = str_replace('/', '__SLASH__', $key);
   }
   ```

### Variable Naming Standards

4. **Use descriptive variable names from examples**:
   - ✅ CORRECT: `$matches` for preg_match results
   - ❌ WRONG: `$m`, `$result`, or other shortcuts
   - When shown an example, use EXACTLY that variable name

5. **Consistent naming**: Follow existing codebase patterns religiously

### Code Quality Rules

6. **NEVER use `isset()`** - use `empty()` instead per Djebel standards

7. **ALWAYS use curly braces `{}`** - even for single-line if statements

8. **NO nested function calls** and **NO complex ternary operators**:
   - ❌ WRONG: `formatKey(substr($key, 0, $pos))`
   - ❌ WRONG: `foreach (explode('.', $key) as $part)`
   - ❌ WRONG: `$keys[] = strpos($x, '__SLASH__') !== false ? str_replace('__SLASH__', '/', $x) : formatKey($x);`
   - ✅ CORRECT: Use local variables and clear if/else:
   ```php
   // Function calls
   $main_key = substr($key, 0, $pos);
   $main_key = Dj_App_String_Util::formatKey($main_key);

   // Loops
   $parts = explode('.', $matches[1]);

   foreach ($parts as $part) {
       $keys[] = Dj_App_String_Util::formatKey($part);
   }

   // Conditionals
   $key2 = $matches[2];

   if (strpos($key2, '__SLASH__') !== false) {
       $key2 = str_replace('__SLASH__', '/', $key2);
   } else {
       $key2 = Dj_App_String_Util::formatKey($key2);
   }

   $keys[] = $key2;
   ```

9. **Proper spacing**: Add blank line after variable assignments before if blocks
   ```php
   $bracket_pos = strpos($key, '[');

   if ($bracket_pos !== false) {
   ```

10. **Blank line before return statements**: When a block has complex logic, add blank line before return for clarity
    ```php
    if (preg_match('/pattern/', $key, $matches)) {
        $main_key = $matches[1];
        $main_key = Dj_App_String_Util::formatKey($main_key);

        if (!empty($section)) {
            $data[$section][$main_key] = [];
            $data[$section][$main_key][] = $val;
        } else {
            $data[$main_key] = [];
            $data[$main_key][] = $val;
        }

        return $data;  // Blank line above for visual clarity
    }
    ```

### Professional Patterns

11. **ALWAYS check function return values**:
    ```php
    if (preg_match('/pattern/', $key, $matches)) {
        // Use $matches here
    }
    ```

12. **NO references (`&`) anywhere**:
    - Not in function parameters
    - Not in variable assignments
    - Explicit code is secure code - easy to audit and impossible to hack

13. **Support whitespace and quotes in user input**:
    - Use `[\s\'\"]*` in regex patterns for brackets
    - Example: `/\[[\s\'\"]*(\w+)[\s\'\"]*\]/`

14. **Comment complex logic BEFORE the code**:
    - Format: `// [Action]: [explanation]`
    - Explain WHAT the code does and WHY
    - Example:
    ```php
    // Handle array notation with auto-increment: var[] = value
    if (preg_match('/^([\w\-]+)\[\s*\]$/si', $key, $matches)) {
    ```

15. **NO side effects in getter methods**: NEVER load data or modify state in `__get()`
    - ❌ WRONG: Calling `load()` inside `__get()` method
    - ❌ WRONG: Calling `setData()` inside `__get()` method
    - ❌ WRONG: Creating new objects with `new static()` inside `__get()`
    - ❌ WRONG: Type checking with `is_array()`, `is_object()`, etc. inside `__get()`
    - ✅ CORRECT: `__get()` only reads and returns existing data
    - ✅ CORRECT: Simple return: `return $data[$name]` or `return ''`
    - Getters should be pure functions without state modifications, type checks, or object creation

### Options Class Pattern

**Use get() method with dot notation** - NOT property chaining!

Options is a **singleton** - don't create new instances unnecessarily. The `get()` method supports dot notation for nested keys:

```php
// ✅ CORRECT: Use get() method with dot notation
$site_url = $options_obj->get('site.site_url');
$meta_title = $options_obj->get('meta.default.title');

// ❌ WRONG: Property chaining creates warnings when keys don't exist
$site_url = $options_obj->site->site_url;  // WARNING if 'site' doesn't exist!
```

The `__get()` method is SIMPLE - just return the value:

```php
public function __get($name) {
    $data = $this->data;

    if (!empty($data) && isset($data[$name])) {
        return $data[$name];  // Return the value directly
    }

    return '';  // Return empty string if not found
}
```

Combined with `__toString()` for string contexts:
```php
public function __toString() {
    if (empty($this->data)) {
        return '';
    }

    if (is_scalar($this->data)) {
        return (string) $this->data;
    }

    return '';
}
```

**Why this is correct**:
- ✅ NO type checking - just return the value
- ✅ NO object creation - respects singleton pattern
- ✅ NO side effects - pure getter
- ✅ Use `get()` method for nested access - it's designed for dot notation!
- ✅ Clean, simple, ZERO hacks

### Security Through Simplicity

16. **Clean, auditable code**: No magic, no hidden behavior
    - Every array access should be visible and traceable
    - Explicit depth handling over dynamic loops
    - Simple code prevents security vulnerabilities

17. **Zero tolerance for waste**: Every character in code must have a purpose
    - Remove redundant checks
    - Eliminate duplicate branches
    - Optimize regex patterns

### Target: 1,000,000 Sites

When code runs on 1,000,000 sites:
- Every microsecond matters
- Every redundant operation costs real money
- Clean code prevents security incidents
- Simple code is maintainable code

**Remember**: You're a 10x PHP developer. Act like one. Know your language. Optimize ruthlessly. Write clean, fast, secure code.

## High-Level Architecture

This is **Djebel**, a PHP-based CMS framework (v0.0.1) with a plugin-based architecture.

### Core Components

**Bootstrap System** (`index.php`):
- Main entry point that configures the application environment
- Loads configuration from `.env` files with environment-specific overrides
- Implements singleton pattern for core services
- Sets up global exception and error handlers

**Configuration System** (`Dj_App_Config`):
- Environment variable management with fallback support
- Supports nested configuration keys (e.g., `app.sys.app_base_dir`)
- Auto-formats keys to uppercase with `DJEBEL_` prefix
- System variable replacement (e.g., `{home}` expansion)

**Hook System** (`src/core/lib/hooks.php`):
- WordPress-inspired actions and filters system
- Supports priority-based hook execution
- Tracks executed hooks for debugging
- Core integration points: `app.core.init`, `app.page.content.render`, `app.core.theme.theme_loaded`

**Plugin Architecture** (`src/core/lib/plugins.php`):
- Multi-tier plugin loading: system → shared → regular → core admin
- Plugin directories: `/plugins`, `/system_plugins`, `/shared_plugins`, admin `/plugins`
- Conditional plugin loading based on URL patterns
- Safe loading with `include_once` to prevent crashes

**Theme System** (`src/core/lib/themes.php`):
- Dynamic theme loading and switching
- Hook integration for theme lifecycle events
- Fallback rendering when themes are disabled

### Key Patterns

- **Singleton Pattern**: Core services (`Dj_App_Bootstrap`, `Dj_App_Request`, etc.)
- **Hook-Driven**: Extensive use of actions/filters for extensibility
- **Environment-Aware**: Support for development/production configurations
- **Safe Loading**: Uses `include_once` for plugins/themes to prevent fatal errors
- **Hierarchical Configuration**: Environment → constants → config files

### Directory Structure

```
src/core/lib/          # Core library files
├── hooks.php         # Actions/filters system
├── plugins.php       # Plugin management
├── themes.php        # Theme system
├── util.php          # Utility functions
├── page.php          # Page handling
├── options.php       # Options management
└── ...

tests/                # PHPUnit test suite
├── unit_tests/       # Unit tests
├── phpunit.xml       # Test configuration
└── composer.json     # Test dependencies
```

### Configuration Management

The system uses a cascading configuration approach:
1. Environment variables (with `DJEBEL_` prefix)
2. PHP constants
3. Default values
4. Hook filters for dynamic modification

Key configuration points:
- `app.debug`: Debug mode toggle
- `app.core.load_admin`: Admin area loading
- `app.core.plugins.load_plugins`: Plugin system toggle
- `app.core.theme.load_theme`: Theme system toggle