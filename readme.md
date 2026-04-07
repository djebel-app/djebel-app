# Djebel: The Fast, Plugin-Based Web Application Framework

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue.svg)](https://php.net)
[![Performance](https://img.shields.io/badge/performance-25ms%20rule-green.svg)](https://github.com/djebel-app/djebel-app)

Djebel is a minimalistic, super-fast web application framework that takes the best ideas from WordPress — like hooks, filters, themes, and plugins — and reimagines them for modern web development.

Unlike traditional frameworks that assume what you need, Djebel assumes nothing. It's completely plugin-based, letting YOU decide what your application becomes.

Think of Djebel as the Swiss Army knife of web development: compact, powerful, and adaptable to any project.

## 🚀 Who is Djebel For?

Djebel is perfect for developers and agencies who need to build:

- **Landing Pages** — Fast-loading, conversion-focused pages
- **Mini Web Applications** — Lightweight tools and utilities  
- **Dashboards** — Clean, data-driven interfaces
- **Rapid Prototypes** — Quick proof-of-concept applications
- **Influencer Mini Sites** — Personal brand sites with bio links, content showcase, and social integration
- **AI-Generated Websites** — Perfect foundation for programmatically created sites
- **Custom Web Solutions** — When you need full control without bloat

If you've ever felt frustrated by heavy frameworks that force you into their way of doing things, Djebel is your answer.

## 💡 Business-First Philosophy: Solve Problems, Not Impress Developers

Djebel isn't about chasing the latest programming trends or impressing other developers. 
It's about **solving real business problems, fast**.

### Why This Matters:
- **Clients don't care about your technology stack** — They want their problems solved
- **Simple code is maintainable code** — Less complexity means fewer bugs
- **Speed to market beats perfect architecture** — Get solutions deployed quickly
- **Easy plugin creation** — No need to learn 100 different patterns and conventions

## 💻 Development Philosophy: 10x Performance Engineering

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

## 🔌 Plugin Development Made Simple

Djebel's plugin system is designed to be approachable and powerful. Creating a plugin is as simple as writing a PHP function with a proper header.

**No complex interfaces to implement, no dependency injection to configure, no abstract factories to understand. Just write functions that solve problems.**

### Learn from Real Examples

Check out the official plugin repository at [@djebel-app-plugins](https://github.com/djebel-app-plugins) to see real-world plugin implementations:

- **[djebel-creator-links](https://github.com/djebel-app-plugins/djebel-creator-links)** - Social media links plugin
- **[djebel-seo](https://github.com/djebel-app-plugins/djebel-seo)** - SEO functionality plugin  
- **[djebel-lang](https://github.com/djebel-app-plugins/djebel-lang)** - Language management plugin

These plugins demonstrate best practices and show you exactly how to build your own Djebel plugins.

## 🏗️ Core Philosophy: Everything is a Plugin

Djebel's revolutionary approach means **everything** is optional. Want a contact form? Install a plugin. Need user authentication? There's a plugin for that. Want a rich text editor? You decide which one fits your project.

This isn't just modularity — it's **intentional minimalism**. Your application only loads what it actually uses, resulting in lightning-fast performance.

## 📦 The Three Types of Plugins

Djebel organizes plugins into three distinct categories, each serving a specific purpose:

### 1. System Plugins (Load First)
These are the foundation layer — plugins that other plugins depend on. Think core utilities, security frameworks, and essential services.

**Example Use Case:** A database connection plugin that other plugins rely on.

### 2. Shared Plugins
Perfect for **agencies, hosting companies, and multi-app developers**, these plugins run across multiple sites or applications from a single installation. When Djebel is installed in a shared environment, you can configure a set of plugins that **always load automatically** to set up common functionality across all your apps.

**Real-World Examples:** 

*Hosting company deployment:*
- Security monitoring plugin
- Performance optimization plugin  
- Backup management plugin
- Custom branding plugin

*Multi-app developer setup:*
- Authentication plugin (shared login across apps)
- API client plugin (common third-party integrations)
- Logging plugin (centralized error tracking)
- Theme framework plugin (consistent UI components)

All sites/apps automatically inherit these shared plugins while maintaining their own individual plugins. One installation, managed centrally, deployed everywhere.

### 3. Regular Plugins  
Your standard feature plugins — contact forms, galleries, user management, etc. These make up the bulk of your application's functionality.

## 🎣 Hooks and Filters: WordPress-Inspired Extensibility

If you've worked with WordPress, you'll feel right at home. Djebel uses the same hooks and filters concept, but streamlined for modern development.

### Action Hooks
Register hooks to run when specific events occur in your application.

### Filter Hooks  
Modify content and data as it flows through your application.

### Multiple Hooks with Priorities
Control the order in which your hooks execute using priority values.

### Deferred Actions — Background Work After the Response
Defer slow work (push notifications, email, analytics, cleanup) so it runs **after** the HTTP response has been flushed to the client. The user sees the page immediately; the background work continues without blocking them.

```php
// Inside a plugin or theme
Dj_App_Hooks::addDeferredAction('app/messages/insert', [$pushService, 'sendNotif'], 50);
Dj_App_Hooks::addDeferredAction('app/messages/insert', [$emailService, 'send'],     30);

// Anywhere in the request lifecycle, fire the trigger normally:
Dj_App_Hooks::doAction('app/messages/insert', [
    'chat_id' => 5,
    'sender'  => 'alice',
    'message' => 'hi there',
]);
// Both deferred callbacks are SKIPPED here — params are captured for later.
```

Bootstrap (`index.php`) handles the shutdown phase in this order:

```php
} finally {
    $req_obj->outputContent();              // 1. Echo content into PHP's output buffer
    $req_obj->finishRequest();              // 2. Flush + Connection: close + fastcgi_finish_request
    Dj_App_Hooks::doAction('app/shutdown'); // 3. Fire any registered shutdown listeners
    Dj_App_Hooks::runDeferredActions();     // 4. Drain captured deferred queue in background
}
```

After step 2, the browser already sees the page and disconnects. PHP keeps running for steps 3 and 4, so all deferred work happens **invisible to the user**.

`runDeferredActions()` is the single source of truth for the drain — it iterates the captured queue and replays each `(hook, params)` via `doAction(..., type=DEFERRED)`, which reads from `$deferred_actions` and runs all deferred callbacks for that hook in priority order with the originally-captured params. Loop prevention is structural: DEFERRED-mode dispatch reads a different registry than NORMAL mode, so the inline skip-and-capture branch never re-fires.

**Removing a deferred action:**
```php
Dj_App_Hooks::removeDeferredAction('app/messages/insert', [$pushService, 'sendNotif'], 50);
```

`removeDeferredAction` clears both `$actions` and `$deferred_actions` in a single pass — after this call the callback won't run sync OR deferred.

**Notes:**
- Mix sync and deferred callbacks at the same hook freely — sync ones run inline, deferred ones run after the response.
- Multi-fire of the same hook is supported: each fire's params are captured separately and replayed on shutdown.
- Deferral applies to actions only — filters are synchronous because the return value is needed immediately.
- No bootstrap edits are required from plugins/themes — `addDeferredAction()` is the only API surface they need to know about.

## 📝 Shortcodes: Dynamic Content Anywhere

Djebel's shortcode system lets you inject dynamic content **anywhere on your page** - in templates, content areas, headers, footers, sidebars, or any HTML file. No need to write PHP in your templates.

### Built-in Shortcodes
Djebel comes with essential shortcodes like `[djebel_date_year]`, `[djebel_page_nav]`, `[djebel_page_content]`, `[djebel_page_footer]`, and more.

### Custom Shortcodes
Register your own shortcodes to inject dynamic content anywhere in your templates.

## ⚡ Performance: Built for Speed

### The 25ms Rule
Djebel follows a strict performance principle: **If any feature takes more than 25ms to execute, it must be explicitly enabled and is OFF by default.**

This means:
- Core framework loads lightning-fast out of the box
- Heavy features require conscious developer choice
- No surprise performance hits from "convenient" defaults
- Your application stays fast unless you specifically choose otherwise

### Plugin Load Tracking
Djebel tracks how long each plugin takes to load, helping you identify performance bottlenecks and ensuring your application stays fast.

## 🚀 Quick Installation

Djebel is designed for rapid deployment with multiple installation options:

### 1. PHAR (Recommended)
1. **Download** the `djebel-app.phar` file
2. **Include** it in your `index.php`:
   ```php
   <?php require_once 'djebel-app.phar'; ?>
   ```
3. **Configure** your environment
4. **Start building** with plugins

### 2. Git Clone
```bash
git clone https://github.com/djebel-app/djebel-app.git
cd djebel-app
```

### 3. ZIP Download
1. **Download** the latest release ZIP from GitHub
2. **Extract** to your project directory
3. **Include** the framework in your `index.php`

That's it! No complex setup, no lengthy configuration files.

## 🌐 Community and Ecosystem

### Official Plugin Repository
Check out our growing collection of plugins at [@djebel-app-plugins](https://github.com/djebel-app-plugins):

- **[djebel-creator-links](https://github.com/djebel-app-plugins/djebel-creator-links)** - Simple plugin to display social media links
- **[djebel-seo](https://github.com/djebel-app-plugins/djebel-seo)** - SEO Plugin
- **[djebel-lang](https://github.com/djebel-app-plugins/djebel-lang)** - Language plugin

### Official Theme Repository
Find themes and UI components at [@djebel-app-themes](https://github.com/djebel-app-themes)

## 🔧 Configuration: INI File Based

Djebel uses a clean, readable app.ini configuration system:

### Sample app.ini Configuration
```ini
[site]
site_title = My Awesome App
description = Fast and flexible web application
front_page = home
meta_title = My Awesome App
meta_keywords = fast, web, app
meta_description = Built with Djebel framework

[themes]
theme = default

[page_nav]
home.title = Home
home.url = /

about.title = About
about.url = /about

contact.title = Contact
contact.url = /contact

[plugins]

hello-world.active = 1

; temporarily deactivate a plugin
djebel-creator-links.active = 0

; conditionally load
djebel-faq.load_if_url = /faq
```

### Accessing Configuration in Code
Use the `Dj_App_Options` class to access configuration values throughout your application with simple, intuitive methods.

## 🆚 Why Choose Djebel Over Other Frameworks?

### vs. WordPress
- **Faster** — No legacy bloat
- **Cleaner** — Modern PHP practices  
- **Focused** — Built for web apps, not just blogs

### vs. Laravel/Symfony
- **Lighter** — Minimal footprint
- **Simpler** — Less learning curve
- **Flexible** — No forced architectural patterns

### vs. Custom Solutions
- **Proven** — Battle-tested hooks system
- **Extensible** — Rich plugin ecosystem
- **Maintainable** — Clear separation of concerns

## 📚 Real-World Examples

Djebel is perfect for building:
- **Landing Pages** with dynamic content and forms
- **Dashboard Applications** with user authentication and data visualization
- **Multi-tenant SaaS Platforms** with shared plugins across multiple sites
- **Rapid Prototypes** that can be deployed quickly

Check out the official plugin repository for real-world implementations and examples.

## 🧪 Running Tests

Djebel includes a comprehensive PHPUnit test suite to ensure code quality and performance.

### Running the Test Suite

```bash
cd tests
./vendor/bin/phpunit
```

The test suite includes 266+ tests covering core functionality, string utilities, hooks system, and more.

### Test Configuration

Tests are configured via `tests/phpunit.xml` and use PHPUnit 12.0.8. Djebel is compatible with PHP 7.4+.

## 🤝 Getting Involved

Ready to build something amazing with Djebel?

### Start Small
Try Djebel for your next landing page or small web app. Experience the speed and simplicity firsthand.

### Build Plugins
The plugin system is designed to be approachable. If you can write a function, you can write a plugin.

### Join the Community
Share your experiences, contribute plugins, and help shape Djebel's future.

## 👨‍💻 Author & Creator

**Djebel Framework** is created, developed, and maintained by **[Orbisius](https://orbisius.com)**.

Orbisius specializes in creating powerful, user-friendly web applications and WordPress solutions. With years of experience in web development, we understand the pain points developers face with bloated frameworks and created Djebel to solve these problems.

## 💰 Main Sponsor

This project is proudly sponsored by **[Orbisius](https://orbisius.com)** - the creator and main sponsor of the Djebel framework.

Orbisius is a leading provider of WordPress plugins, web applications, and development services. Their support makes Djebel's continued development and maintenance possible.

## 📄 License

This project is licensed under the GNU General Public License v3.0 - see the [license.txt](license.txt) file for details.

## 🎯 Conclusion: Fast, Flexible, Future-Ready

Djebel represents a new approach to web application development — one that respects your intelligence as a developer and trusts you to make the right choices for your project.

Whether you're building a simple landing page or a complex dashboard, Djebel gives you:
- **Speed** — Minimal core, maximum performance
- **Control** — You decide what gets loaded  
- **Familiarity** — WordPress-inspired APIs you already know
- **Flexibility** — Support for any database, template engine, or deployment method

Stop fighting your framework. Start building with Djebel.

---

**Visit the Djebel project repository, download the framework, and build something extraordinary. Your next web application doesn't need to be slow, bloated, or restrictive.**

**With Djebel, it can be fast, focused, and exactly what you need.**
