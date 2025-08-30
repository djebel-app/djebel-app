# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Testing
- Run all tests: `cd tests && ./vendor/bin/phpunit`
- Run specific test suite: `cd tests && ./vendor/bin/phpunit --testsuite unit`
- Test configuration: `tests/phpunit.xml`

### Dependencies
- Test dependencies are managed in `tests/composer.json`
- Install test dependencies: `cd tests && composer install`

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