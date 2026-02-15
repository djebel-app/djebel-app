<?php

use PHPUnit\Framework\TestCase;

class Options_Test extends TestCase {
    private $result;
    public function setUp() : void {
    }

    public function tearDown() : void {
    }

    public function testParseOptions() {
        $options_buff = <<<EOT
[site]
site_title = Sample Site
description = This is a sample site
front_page=blog

meta_title = Sample Site
meta_keywords = sample, site, blog
meta_description = This is a sample site

app_web_prefix = /dj-app/dj-content/

[themes]
theme = default

[plugins]
hello-world = 0

[sites]
site1.id = 1
site1.title = Orbisius
site1.url = https://orbisius.com
site1.description = 'Orbisius home page'

site2[id] = 2
site2[title] = Djebel
site2[url] = https://djebel.com
site2[description] = 'Djebel home page'
EOT;

        $options_obj = Dj_App_Options::getInstance();
        $cfg = $options_obj->parseBuffer($options_buff);

        $this->assertNotEmpty($cfg);
        $this->assertArrayHasKey('site', $cfg);
        $this->assertEquals($cfg['site']['site_title'], 'Sample Site');

        // test arrays
        $this->assertEquals($cfg['sites']['site1']['id'], 1);
        $this->assertEquals($cfg['sites']['site2']['id'], 2);
    }

    public function testParseLine()
    {
        $options_obj = Dj_App_Options::getInstance();

        // must return an empty array
        $this->assertEmpty($options_obj->parseLine(''));
        $this->assertEmpty($options_obj->parseLine('       '));
        $this->assertEmpty($options_obj->parseLine('; asfasfasfasf'));
        $this->assertEmpty($options_obj->parseLine('# asfasfasfasf'));
        $this->assertEmpty($options_obj->parseLine('// asfasfasfasf'));

        $options_obj->clear();
        $this->assertEquals('/dj-app/dj-content/', $options_obj->parseLine('app_web_prefix = /dj-app/dj-content/')['app_web_prefix']);
    }

    public function testDotNotationKeyAccess()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test data that includes the meta.default.title pattern
        $test_data = [
            'meta' => [
                'default' => [
                    'title' => 'Default Meta Title',
                    'description' => 'Default Meta Description',
                    'keywords' => 'default, meta, keywords'
                ],
                'page' => [
                    'title' => 'Page Meta Title'
                ]
            ],
            'site' => [
                'config' => [
                    'debug' => true
                ]
            ]
        ];

        $options_obj->setData($test_data);

        // Test the specific pattern mentioned: meta.default.title
        $meta_title = $options_obj->get('meta.default.title');
        $this->assertEquals('Default Meta Title', $meta_title);

        // Test other dot notation patterns
        $meta_description = $options_obj->get('meta.default.description');
        $this->assertEquals('Default Meta Description', $meta_description);

        $meta_keywords = $options_obj->get('meta.default.keywords');
        $this->assertEquals('default, meta, keywords', $meta_keywords);

        $page_title = $options_obj->get('meta.page.title');
        $this->assertEquals('Page Meta Title', $page_title);

        $site_debug = $options_obj->get('site.config.debug');
        $this->assertTrue($site_debug);

        // Test non-existent keys with defaults
        $non_existent = $options_obj->get('meta.default.nonexistent');
        $this->assertEmpty($non_existent);

        $non_existent_with_default = $options_obj->get('meta.default.nonexistent', 'fallback');
        $this->assertEquals('fallback', $non_existent_with_default);

        // Test partial path that doesn't exist
        $non_existent_section = $options_obj->get('nonexistent.section.key');
        $this->assertEmpty($non_existent_section);
    }

    public function testArrayKeysWithSlashes()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test INI format with slashes in array keys (URL patterns)
        $options_buff = <<<EOT
[plugins]
djebel-static-content.url_contains[/docs/latest] = "template_file=docs/latest.php"
djebel-static-content.url_contains[/blog] = "template_file=blog.php"
djebel-static-content.url_contains[/api/v2] = "template_file=api.php&version=2"
djebel-static-content.url_contains[/en/about] = "template_file=about.php&lang=en"
djebel-static-content.url_contains[/en/docs/latest/intro/introduction-xyz789abc123] = "template_file=intro.php"
EOT;

        $cfg = $options_obj->parseBuffer($options_buff);

        // Test parsed structure
        $this->assertNotEmpty($cfg);
        $this->assertArrayHasKey('plugins', $cfg);
        $this->assertArrayHasKey('djebel_static_content', $cfg['plugins']);
        $this->assertArrayHasKey('url_contains', $cfg['plugins']['djebel_static_content']);

        $url_contains = $cfg['plugins']['djebel_static_content']['url_contains'];

        // Test that array keys preserved slashes
        $this->assertArrayHasKey('/docs/latest', $url_contains);
        $this->assertArrayHasKey('/blog', $url_contains);
        $this->assertArrayHasKey('/api/v2', $url_contains);
        $this->assertArrayHasKey('/en/about', $url_contains);
        $this->assertArrayHasKey('/en/docs/latest/intro/introduction-xyz789abc123', $url_contains);

        // Test values
        $this->assertEquals('template_file=docs/latest.php', $url_contains['/docs/latest']);
        $this->assertEquals('template_file=blog.php', $url_contains['/blog']);
        $this->assertEquals('template_file=api.php&version=2', $url_contains['/api/v2']);
        $this->assertEquals('template_file=about.php&lang=en', $url_contains['/en/about']);
        $this->assertEquals('template_file=intro.php', $url_contains['/en/docs/latest/intro/introduction-xyz789abc123']);

        // Test get() method with full path
        $options_obj->setData($cfg);
        $url_contains_via_get = $options_obj->get('plugins.djebel-static-content.url_contains', []);

        $this->assertIsArray($url_contains_via_get);
        $this->assertCount(5, $url_contains_via_get);
        $this->assertArrayHasKey('/docs/latest', $url_contains_via_get);
        $this->assertArrayHasKey('/blog', $url_contains_via_get);
        $this->assertArrayHasKey('/en/docs/latest/intro/introduction-xyz789abc123', $url_contains_via_get);

        // Test parsing query string values
        $parsed_data = [];
        parse_str($url_contains_via_get['/docs/latest'], $parsed_data);
        $this->assertEquals('docs/latest.php', $parsed_data['template_file']);

        parse_str($url_contains_via_get['/api/v2'], $parsed_data);
        $this->assertEquals('api.php', $parsed_data['template_file']);
        $this->assertEquals('2', $parsed_data['version']);
    }

    public function testPropertyChainingVsDotNotation()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        $test_data = [
            'theme' => [
                'theme' => 'my-theme',
                'theme_id' => 'theme-123'
            ],
            'site' => [
                'theme_id' => 'site-theme-456',
                'theme' => 'site-theme'
            ]
        ];

        $options_obj->setData($test_data);

        // Property chaining works when key EXISTS
        $this->assertEquals('my-theme', $options_obj->theme->theme);
        $this->assertEquals('site-theme', $options_obj->site->theme);

        // get() method works when key EXISTS
        $this->assertEquals('my-theme', $options_obj->get('theme.theme'));
        $this->assertEquals('site-theme', $options_obj->get('site.theme'));

        // Property chaining: accessing nonexistent key returns empty string (works with empty()!)
        $missing_property = $options_obj->theme->nonexistent_key;
        $this->assertEmpty($missing_property, 'Missing property should be empty');

        // Accessing totally nonexistent section returns empty string
        $nonexistent_section = $options_obj->nonexistent_section;
        $this->assertEmpty($nonexistent_section, 'Nonexistent section should be empty');

        // get() method also returns empty string for missing keys
        $missing_get = $options_obj->get('nonexistent.key');
        $this->assertEmpty($missing_get);

        // Property chaining works for conditional checks
        $current_theme = 'default';

        if (!empty($options_obj->theme->theme)) {
            $current_theme = $options_obj->theme->theme;
        }

        $this->assertEquals('my-theme', $current_theme);

        // Test fallback chain - use get() for reliable string assignments
        $options_obj->clear();
        $options_obj->setData(['site' => ['theme' => 'fallback-theme']]);

        $current_theme = $options_obj->get('theme.theme');
        if (empty($current_theme)) {
            $current_theme = $options_obj->get('theme.theme_id');
        }
        if (empty($current_theme)) {
            $current_theme = $options_obj->get('site.theme_id');
        }
        if (empty($current_theme)) {
            $current_theme = $options_obj->get('site.theme');
        }
        if (empty($current_theme)) {
            $current_theme = 'default';
        }

        $this->assertEquals('fallback-theme', $current_theme);
    }

    public function testFullAppIniParsing()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $this->assertFileExists($app_ini_file);

        $app_ini = file_get_contents($app_ini_file);
        $cfg = $options_obj->parseBuffer($app_ini);

        // Test site section
        $this->assertArrayHasKey('site', $cfg);
        $this->assertEquals('Djebel', $cfg['site']['site_title']);
        $this->assertEquals('Fast, Efficient and plugin based app inspired by WordPress', $cfg['site']['description']);
        $this->assertEquals('/en', $cfg['site']['start']);
        $this->assertEquals('1', $cfg['site']['theme_load_main_file']);
        $this->assertEquals('0', $cfg['site']['theme_load_functions']);

        // Test cfg section
        $this->assertArrayHasKey('cfg', $cfg);
        $this->assertEquals('__SITE_CONTENT_DIR_URL__/djebel/files/rel/latest.zip', $cfg['cfg']['djebel_download_url']);

        // Test theme section
        $this->assertArrayHasKey('theme', $cfg);
        $this->assertEquals('djebel', $cfg['theme']['theme_id']);

        // Test meta section with dot notation
        $this->assertArrayHasKey('meta', $cfg);
        $this->assertArrayHasKey('default', $cfg['meta']);
        $this->assertEquals('Djebel', $cfg['meta']['default']['title']);
        $this->assertEquals('Fast, Efficient and plugin based app inspired by WordPress', $cfg['meta']['default']['description']);
        $this->assertEquals('djebel app, wordpress,wp,djebel,open source,gpl', $cfg['meta']['default']['keywords']);

        // Test meta home section
        $this->assertArrayHasKey('home', $cfg['meta']);
        $this->assertEquals('Djebel Fast Web App/Framework Home', $cfg['meta']['home']['title']);
        $this->assertEquals('home, welcome', $cfg['meta']['home']['keywords']);
        $this->assertEquals('This is a very cool site.', $cfg['meta']['home']['description']);

        // Test meta about section
        $this->assertArrayHasKey('about', $cfg['meta']);
        $this->assertEquals('About Djebel', $cfg['meta']['about']['title']);
        $this->assertEquals('About Djebel\'s author', $cfg['meta']['about']['description']);
        $this->assertEquals('about djebel, about, us, djebel author, djebel creator', $cfg['meta']['about']['keywords']);

        // Test app section
        $this->assertArrayHasKey('app', $cfg);
        $this->assertEquals('dev', $cfg['app']['env']);

        // Test page_nav section with dot notation
        $this->assertArrayHasKey('page_nav', $cfg);
        $this->assertArrayHasKey('home', $cfg['page_nav']);
        $this->assertEquals('Home', $cfg['page_nav']['home']['title']);
        $this->assertEquals('/', $cfg['page_nav']['home']['url']);

        $this->assertArrayHasKey('downloads', $cfg['page_nav']);
        $this->assertEquals('Downloads', $cfg['page_nav']['downloads']['title']);
        $this->assertEquals('/downloads', $cfg['page_nav']['downloads']['url']);

        $this->assertArrayHasKey('faq', $cfg['page_nav']);
        $this->assertEquals('FAQ', $cfg['page_nav']['faq']['title']);
        $this->assertEquals('/faq', $cfg['page_nav']['faq']['url']);
        $this->assertEquals('support', $cfg['page_nav']['faq']['parent']);

        // Test plugins with nested dot notation
        $this->assertArrayHasKey('plugins', $cfg);
        $this->assertArrayHasKey('djebel_static_content', $cfg['plugins']);
        $this->assertEquals('0', $cfg['plugins']['djebel_static_content']['cache']);
        $this->assertEquals('0', $cfg['plugins']['djebel_static_content']['show_date']);
        $this->assertEquals('0', $cfg['plugins']['djebel_static_content']['show_author']);
        $this->assertEquals('0', $cfg['plugins']['djebel_static_content']['show_category']);
        $this->assertEquals('1', $cfg['plugins']['djebel_static_content']['show_summary']);
        $this->assertEquals('0', $cfg['plugins']['djebel_static_content']['show_tags']);

        // Test static content with URL patterns (slashes preserved)
        $this->assertArrayHasKey('url_contains', $cfg['plugins']['djebel_static_content']);
        $url_contains = $cfg['plugins']['djebel_static_content']['url_contains'];
        $this->assertArrayHasKey('/docs/latest', $url_contains);
        $this->assertArrayHasKey('/blog', $url_contains);
        $this->assertEquals('template_file=docs/latest.php', $url_contains['/docs/latest']);
        $this->assertEquals('template_file=blog.php', $url_contains['/blog']);

        // Test djebel-faq plugin
        $this->assertArrayHasKey('djebel_faq', $cfg['plugins']);
        $this->assertEquals('0', $cfg['plugins']['djebel_faq']['cache']);
        $this->assertEquals('/faq', $cfg['plugins']['djebel_faq']['load_if_url']);

        // Test property chaining access
        $options_obj->setData($cfg);
        $this->assertEquals('Djebel', $options_obj->site->site_title);
        $this->assertEquals('djebel', $options_obj->theme->theme_id);
        $this->assertEquals('0', $options_obj->plugins->djebel_static_content->cache);

        // Test get() method with dot notation
        $this->assertEquals('Djebel', $options_obj->get('meta.default.title'));
        $this->assertEquals('Home', $options_obj->get('page_nav.home.title'));
        $this->assertEquals('/faq', $options_obj->get('plugins.djebel-faq.load_if_url'));
    }

    public function testGetCurrentThemeScenario()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Load actual app.ini test data
        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $this->assertFileExists($app_ini_file);

        $app_ini = file_get_contents($app_ini_file);
        $cfg = $options_obj->parseBuffer($app_ini);
        $options_obj->setData($cfg);

        // Test scenario 1: app.ini has only theme_id (not theme)
        // Verify property chaining returns proper values
        $theme_value = $options_obj->theme->theme;
        $theme_id_value = $options_obj->theme->theme_id;

        $this->assertEmpty($theme_value, 'theme->theme should be empty when not set');

        $this->assertNotEmpty($theme_id_value, 'theme->theme_id should not be empty');
        $this->assertEquals('djebel', $theme_id_value, 'theme->theme_id should return "djebel"');

        // Simulate getCurrentTheme() logic using property chaining
        $current_theme = 'default';

        if (!empty($options_obj->theme->theme)) {
            $current_theme = $options_obj->theme->theme;
        } elseif (!empty($options_obj->theme->theme_id)) {
            $current_theme = $options_obj->theme->theme_id;
        } elseif (!empty($options_obj->site->theme_id)) {
            $current_theme = $options_obj->site->theme_id;
        } elseif (!empty($options_obj->site->theme)) {
            $current_theme = $options_obj->site->theme;
        }

        $this->assertEquals('djebel', $current_theme, 'Should fall through to theme_id');

        // Test scenario 2: theme.theme exists and has priority
        $options_obj->clear();
        $test_cfg = $cfg;
        $test_cfg['theme']['theme'] = 'custom-theme';
        $options_obj->setData($test_cfg);

        $current_theme = 'default';

        if (!empty($options_obj->theme->theme)) {
            $current_theme = $options_obj->theme->theme;
        } elseif (!empty($options_obj->theme->theme_id)) {
            $current_theme = $options_obj->theme->theme_id;
        }

        $this->assertEquals('custom-theme', $current_theme, 'Should use theme.theme when it exists');

        // Test scenario 3: Neither theme section keys exist, fall back to site
        $options_obj->clear();
        $test_cfg2 = [
            'site' => [
                'theme' => 'site-theme'
            ]
        ];
        $options_obj->setData($test_cfg2);

        $current_theme = 'default';

        if (!empty($options_obj->theme->theme)) {
            $current_theme = $options_obj->theme->theme;
        } elseif (!empty($options_obj->theme->theme_id)) {
            $current_theme = $options_obj->theme->theme_id;
        } elseif (!empty($options_obj->site->theme_id)) {
            $current_theme = $options_obj->site->theme_id;
        } elseif (!empty($options_obj->site->theme)) {
            $current_theme = $options_obj->site->theme;
        }

        $this->assertEquals('site-theme', $current_theme, 'Should fall through to site.theme');

        // Verify get() method also works - reload original config
        $options_obj->setData($cfg);
        $this->assertEquals('djebel', $options_obj->get('theme.theme_id'), 'get() method should also work');
        $this->assertEmpty($options_obj->get('theme.theme'), 'get() should return empty for non-existent key');
    }

    public function testEmptyCheckWithPropertyAccess()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        $test_data = [
            'site' => [
                'site_title' => 'Test Site',
                'theme' => 'default',
            ],
        ];

        $options_obj->setData($test_data);

        // Test that property access returns empty string for non-existent keys
        $site_url = $options_obj->site->site_url;
        $this->assertEmpty($site_url, 'Non-existent key should be empty');

        // Test that value exists
        $site_title = $options_obj->site->site_title;
        $this->assertNotEmpty($site_title, 'Existing value should not be empty');
        $this->assertEquals('Test Site', $site_title);

        // Test direct property access with empty() check (no cast needed!)
        if (!empty($options_obj->site->site_url)) {
            $this->fail('Should not enter this block when site_url does not exist');
        }

        // Now test when value exists
        $options_obj->clear();
        $test_data_with_url = [
            'site' => [
                'site_url' => 'https://example.com',
            ],
        ];
        $options_obj->setData($test_data_with_url);

        // Direct property access works with empty() now!
        $site_url = $options_obj->site->site_url;
        $this->assertNotEmpty($site_url, 'site_url should not be empty when it exists');
        $this->assertEquals('https://example.com', $site_url);
    }

    public function testBothAccessPatternsReturnSameValues()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Load actual app.ini
        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $this->assertFileExists($app_ini_file);

        $app_ini = file_get_contents($app_ini_file);
        $cfg = $options_obj->parseBuffer($app_ini);
        $options_obj->setData($cfg);

        // Test: Both approaches return identical values for existing keys

        // Site section
        $this->assertEquals(
            $options_obj->get('site.site_title'),
            $options_obj->site->site_title,
            'get() and chaining should return same value for site.site_title'
        );
        $this->assertEquals('Djebel', $options_obj->site->site_title);

        // Theme section
        $this->assertEquals(
            $options_obj->get('theme.theme_id'),
            $options_obj->theme->theme_id,
            'get() and chaining should return same value for theme.theme_id'
        );
        $this->assertEquals('djebel', $options_obj->theme->theme_id);

        // Meta nested (3 levels deep)
        $this->assertEquals(
            $options_obj->get('meta.default.title'),
            $options_obj->meta->default->title,
            'get() and chaining should return same value for meta.default.title'
        );
        $this->assertEquals('Djebel', $options_obj->meta->default->title);

        // Plugins nested (3 levels deep)
        $this->assertEquals(
            $options_obj->get('plugins.djebel-static-content.cache'),
            $options_obj->plugins->djebel_static_content->cache,
            'get() and chaining should return same value for plugins.djebel-static-content.cache'
        );
        $this->assertEquals('0', $options_obj->plugins->djebel_static_content->cache);

        // Test: Both approaches return empty for non-existent keys

        // Non-existent key via get()
        $get_result = $options_obj->get('site.non_existent_key');
        $this->assertEmpty($get_result, 'get() should return empty for non-existent key');

        // Non-existent key via chaining
        $chain_result = $options_obj->site->non_existent_key;
        $this->assertEmpty($chain_result, 'Chaining should return empty for non-existent key');

        // Both should be identical
        $this->assertEquals($get_result, $chain_result, 'Both approaches should return same empty value');

        // Non-existent section via get()
        $get_no_section = $options_obj->get('non_existent_section.key');
        $this->assertEmpty($get_no_section, 'get() should return empty for non-existent section');

        // Non-existent section via chaining
        $chain_no_section = $options_obj->non_existent_section->key;
        $this->assertEmpty($chain_no_section, 'Chaining should return empty for non-existent section');

        // Both should be identical
        $this->assertEquals($get_no_section, $chain_no_section, 'Both approaches should return same empty value for non-existent section');
    }

    public function testThreeWaysToAccessPluginsSection()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Load actual app.ini with real plugins data
        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $this->assertFileExists($app_ini_file);

        $app_ini = file_get_contents($app_ini_file);
        $cfg = $options_obj->parseBuffer($app_ini);
        $options_obj->setData($cfg);

        // Three ways to access the plugins section
        $plugins_via_property = $options_obj->plugins;
        $plugins_via_get = $options_obj->get('plugins');
        $plugins_via_get_section = $options_obj->getSection('plugins');

        // All three should return Options objects (or arrays)
        $this->assertInstanceOf(Dj_App_Options::class, $plugins_via_property, 'Property access should return Options object');

        // get() returns the underlying array
        $this->assertIsArray($plugins_via_get, 'get() should return array for section');

        $this->assertInstanceOf(Dj_App_Options::class, $plugins_via_get_section, 'getSection() should return Options object');

        // Convert to arrays for comparison
        $plugins_property_array = $plugins_via_property->toArray();
        $plugins_get_array = $plugins_via_get;
        $plugins_section_array = $plugins_via_get_section->toArray();

        // All three should have the same keys
        $this->assertArrayHasKey('djebel_static_content', $plugins_property_array);
        $this->assertArrayHasKey('djebel_static_content', $plugins_get_array);
        $this->assertArrayHasKey('djebel_static_content', $plugins_section_array);

        $this->assertArrayHasKey('djebel_faq', $plugins_property_array);
        $this->assertArrayHasKey('djebel_faq', $plugins_get_array);
        $this->assertArrayHasKey('djebel_faq', $plugins_section_array);

        // Test accessing nested values through each approach

        // Via property chaining
        $cache_via_property = $options_obj->plugins->djebel_static_content->cache;
        $this->assertEquals('0', $cache_via_property);

        // Via get() with dot notation
        $cache_via_get = $options_obj->get('plugins.djebel-static-content.cache');
        $this->assertEquals('0', $cache_via_get);

        // Via getSection() then property access
        $static_content_section = $options_obj->getSection('plugins.djebel-static-content');
        $cache_via_section = $static_content_section->cache;
        $this->assertEquals('0', $cache_via_section);

        // All three should return identical values
        $this->assertEquals($cache_via_property, $cache_via_get, 'Property and get() should return same value');
        $this->assertEquals($cache_via_get, $cache_via_section, 'get() and getSection() should return same value');

        // Test another plugin value
        $faq_cache_property = $options_obj->plugins->djebel_faq->cache;
        $faq_cache_get = $options_obj->get('plugins.djebel-faq.cache');
        $faq_section = $options_obj->getSection('plugins.djebel-faq');
        $faq_cache_section = $faq_section->cache;

        $this->assertEquals('0', $faq_cache_property);
        $this->assertEquals('0', $faq_cache_get);
        $this->assertEquals('0', $faq_cache_section);
        $this->assertEquals($faq_cache_property, $faq_cache_get);
        $this->assertEquals($faq_cache_get, $faq_cache_section);
    }

    public function testIsEnabledAndIsDisabledMethods()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test data with various enabled/disabled values
        $test_data = [
            'features' => [
                'cache' => '1',              // enabled
                'debug' => '0',              // disabled
                'logging' => 'yes',          // enabled
                'maintenance' => 'no',       // disabled
                'api_enabled' => 'true',     // enabled
                'api_disabled' => 'false',   // disabled
                'notifications' => 'on',     // enabled
                'tracking' => 'off',         // disabled
                'empty_string' => '',        // NOT disabled (no explicit value)
            ],
        ];

        $options_obj->setData($test_data);

        // Test isEnabled() for enabled values
        $this->assertTrue($options_obj->isEnabled('features.cache'), 'cache=1 should be enabled');
        $this->assertTrue($options_obj->isEnabled('features.logging'), 'logging=yes should be enabled');
        $this->assertTrue($options_obj->isEnabled('features.api_enabled'), 'api_enabled=true should be enabled');
        $this->assertTrue($options_obj->isEnabled('features.notifications'), 'notifications=on should be enabled');

        // Test isEnabled() for disabled values (should return false)
        $this->assertFalse($options_obj->isEnabled('features.debug'), 'debug=0 should NOT be enabled');
        $this->assertFalse($options_obj->isEnabled('features.maintenance'), 'maintenance=no should NOT be enabled');
        $this->assertFalse($options_obj->isEnabled('features.api_disabled'), 'api_disabled=false should NOT be enabled');
        $this->assertFalse($options_obj->isEnabled('features.tracking'), 'tracking=off should NOT be enabled');

        // Test isDisabled() for disabled values
        $this->assertTrue($options_obj->isDisabled('features.debug'), 'debug=0 should be disabled');
        $this->assertTrue($options_obj->isDisabled('features.maintenance'), 'maintenance=no should be disabled');
        $this->assertTrue($options_obj->isDisabled('features.api_disabled'), 'api_disabled=false should be disabled');
        $this->assertTrue($options_obj->isDisabled('features.tracking'), 'tracking=off should be disabled');

        // Test isDisabled() for enabled values (should return false)
        $this->assertFalse($options_obj->isDisabled('features.cache'), 'cache=1 should NOT be disabled');
        $this->assertFalse($options_obj->isDisabled('features.logging'), 'logging=yes should NOT be disabled');
        $this->assertFalse($options_obj->isDisabled('features.api_enabled'), 'api_enabled=true should NOT be disabled');
        $this->assertFalse($options_obj->isDisabled('features.notifications'), 'notifications=on should NOT be disabled');

        // CRITICAL TEST: Empty string should NOT be considered disabled
        $this->assertFalse($options_obj->isEnabled('features.empty_string'), 'empty string should NOT be enabled');
        $this->assertFalse($options_obj->isDisabled('features.empty_string'), 'empty string should NOT be disabled - needs explicit value');

        // Test non-existent keys (should use defaults)
        $this->assertFalse($options_obj->isEnabled('features.non_existent'), 'non-existent key should default to disabled');
        $this->assertFalse($options_obj->isDisabled('features.non_existent'), 'non-existent key should NOT be disabled - no explicit value');

        // Test with property chaining
        $this->assertTrue($options_obj->features->cache == '1');
        $this->assertTrue($options_obj->isEnabled('features.cache'));
    }

    public function testKeyNormalization()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Load app.ini which has keys with dashes: "djebel-static-content"
        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $app_ini = file_get_contents($app_ini_file);
        $cfg = $options_obj->parseBuffer($app_ini);
        $options_obj->setData($cfg);

        // Test: Keys with dashes in INI become underscores in arrays
        $this->assertArrayHasKey('djebel_static_content', $cfg['plugins'], 'INI key "djebel-static-content" normalizes to "djebel_static_content"');
        $this->assertArrayHasKey('djebel_faq', $cfg['plugins'], 'INI key "djebel-faq" normalizes to "djebel_faq"');

        // Test: get() accepts BOTH dashes and underscores (normalizes internally)
        $cache_with_dash = $options_obj->get('plugins.djebel-static-content.cache');
        $cache_with_underscore = $options_obj->get('plugins.djebel_static_content.cache');

        $this->assertEquals('0', $cache_with_dash, 'get() works with dashes');
        $this->assertEquals('0', $cache_with_underscore, 'get() works with underscores');
        $this->assertEquals($cache_with_dash, $cache_with_underscore, 'Both formats return same value');

        // Test: Property access REQUIRES underscores (PHP limitation)
        $cache_property = $options_obj->plugins->djebel_static_content->cache;
        $this->assertEquals('0', $cache_property, 'Property access requires underscores');

        // Test: All three approaches return identical values
        $this->assertEquals($cache_with_dash, $cache_property, 'get() with dashes === property access with underscores');

        // Test: getSection() also normalizes - accepts both formats
        $section_with_dash = $options_obj->getSection('plugins.djebel-static-content');
        $section_with_underscore = $options_obj->getSection('plugins.djebel_static_content');

        $this->assertEquals('0', $section_with_dash->cache, 'getSection() works with dashes');
        $this->assertEquals('0', $section_with_underscore->cache, 'getSection() works with underscores');
        $this->assertEquals($section_with_dash->cache, $section_with_underscore->cache, 'Both return same data');
    }

    public function testParseIniFileMethod()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test with actual app.ini file
        $app_ini_file = dirname(__DIR__) . '/data/app.ini';
        $this->assertFileExists($app_ini_file);

        // Use parseIniFile() directly
        $data = $options_obj->parseIniFile($app_ini_file);

        // Verify basic structure
        $this->assertIsArray($data);
        $this->assertArrayHasKey('site', $data);
        $this->assertArrayHasKey('plugins', $data);
        $this->assertArrayHasKey('theme', $data);

        // Verify values are parsed correctly
        $this->assertEquals('Djebel', $data['site']['site_title']);
        $this->assertEquals('djebel', $data['theme']['theme_id']);

        // Verify key normalization (dashes → underscores)
        $this->assertArrayHasKey('djebel_static_content', $data['plugins'], 'parseIniFile() should normalize "djebel-static-content" to "djebel_static_content"');
        $this->assertArrayHasKey('djebel_faq', $data['plugins'], 'parseIniFile() should normalize "djebel-faq" to "djebel_faq"');

        // Verify values remain raw strings (INI_SCANNER_RAW)
        $this->assertIsString($data['plugins']['djebel_static_content']['cache']);
        $this->assertEquals('0', $data['plugins']['djebel_static_content']['cache'], 'Values should be raw strings, not converted to int');

        // Test with non-existent file
        $empty_data = $options_obj->parseIniFile('/nonexistent/file.ini');
        $this->assertIsArray($empty_data);
        $this->assertEmpty($empty_data, 'Non-existent file should return empty array');
    }

    public function testGetSection()
    {
        $options_buff = <<<EOT
[plugins]
djebel-mailer.smtp_host = smtp.gmail.com
djebel-mailer.smtp_port = 587
djebel-mailer.smtp_auth = 1
djebel-mailer.smtp_username = test@example.com
djebel-mailer.smtp_password = secret
djebel-mailer.from_email = noreply@example.com
djebel-mailer.from_name = My App

djebel-seo.meta_title = SEO Title
djebel-seo.meta_description = SEO Description

[site]
site_title = Test Site
theme = default
EOT;

        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();
        $cfg = $options_obj->parseBuffer($options_buff);
        $options_obj->setData($cfg);

        // Test getting a section - returns Options object
        $mailer_section = $options_obj->getSection('plugins.djebel-mailer');

        $this->assertInstanceOf(Dj_App_Options::class, $mailer_section);
        $this->assertNotEmpty($mailer_section);

        // Test array access (ArrayAccess interface)
        $this->assertArrayHasKey('smtp_host', $mailer_section);
        $this->assertEquals('smtp.gmail.com', $mailer_section['smtp_host']);
        $this->assertArrayHasKey('smtp_port', $mailer_section);
        $this->assertEquals('587', $mailer_section['smtp_port']);
        $this->assertArrayHasKey('from_email', $mailer_section);
        $this->assertEquals('noreply@example.com', $mailer_section['from_email']);

        // Test object property access
        $this->assertEquals('smtp.gmail.com', $mailer_section->smtp_host);
        $this->assertEquals('My App', $mailer_section->from_name);

        // Test toArray() method
        $mailer_array = $mailer_section->toArray();
        $this->assertIsArray($mailer_array);
        $this->assertArrayHasKey('smtp_host', $mailer_array);
        $this->assertEquals('smtp.gmail.com', $mailer_array['smtp_host']);

        // Test another section
        $seo_section = $options_obj->getSection('plugins.djebel-seo');

        $this->assertInstanceOf(Dj_App_Options::class, $seo_section);
        $this->assertArrayHasKey('meta_title', $seo_section);
        $this->assertEquals('SEO Title', $seo_section['meta_title']);
        $this->assertEquals('SEO Title', $seo_section->meta_title);

        // Test getting parent section
        $plugins_section = $options_obj->getSection('plugins');

        $this->assertInstanceOf(Dj_App_Options::class, $plugins_section);
        $this->assertArrayHasKey('djebel_mailer', $plugins_section);
        $this->assertArrayHasKey('djebel_seo', $plugins_section);

        // Test non-existent section - returns empty Options object
        $empty_section = $options_obj->getSection('non.existent.section');
        $this->assertEmpty($empty_section, 'Non-existent section should be empty');

        // Test empty section key
        $empty_key = $options_obj->getSection('');
        $this->assertEmpty($empty_key, 'Empty section key should be empty');

        // Test single level section
        $site_section = $options_obj->getSection('site');

        $this->assertInstanceOf(Dj_App_Options::class, $site_section);
        $this->assertArrayHasKey('site_title', $site_section);
        $this->assertEquals('Test Site', $site_section['site_title']);
        $this->assertEquals('Test Site', $site_section->site_title);
    }

    public function testRedirectPluginFormat()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test redirect plugin INI format with pipe separator and spaces
        $options_buff = <<<EOT
[plugins]
djebel-redirect.url_match[/old-page] = "/new-page"
djebel-redirect.url_match[/legacy] = "/modern|code=302"
djebel-redirect.url_match[/temp] = "/other|code=302&log=1"
djebel-redirect.url_match[/spaced] = "  /trimmed  "
djebel-redirect.url_starts_with[/docs/v1/] = "/docs/latest/"
djebel-redirect.url_contains[/old-section/] = "/new-section/"
djebel-redirect.url_regex[#^/blog/(\d+)#] = "/posts/\$1|code=301"
EOT;

        $cfg = $options_obj->parseBuffer($options_buff);
        $options_obj->setData($cfg);

        // Test basic structure
        $this->assertArrayHasKey('plugins', $cfg);
        $this->assertArrayHasKey('djebel_redirect', $cfg['plugins']);

        // Test url_match rules
        $url_match = $cfg['plugins']['djebel_redirect']['url_match'];
        $this->assertArrayHasKey('/old-page', $url_match);
        $this->assertArrayHasKey('/legacy', $url_match);
        $this->assertArrayHasKey('/temp', $url_match);
        $this->assertArrayHasKey('/spaced', $url_match);

        // Test simple redirect (no pipe)
        $this->assertEquals('/new-page', $url_match['/old-page']);

        // Test redirect with code (pipe format)
        $this->assertEquals('/modern|code=302', $url_match['/legacy']);

        // Test redirect with multiple params
        $this->assertEquals('/other|code=302&log=1', $url_match['/temp']);

        // Test spaces around value (parser trims automatically)
        $this->assertEquals('/trimmed', $url_match['/spaced']);

        // Test get() method access
        $url_match_via_get = $options_obj->get('plugins.djebel-redirect.url_match', []);
        $this->assertIsArray($url_match_via_get);
        $this->assertArrayHasKey('/old-page', $url_match_via_get);
        $this->assertArrayHasKey('/legacy', $url_match_via_get);

        // Test parsing pipe format (like redirect plugin does)
        $target = $url_match_via_get['/legacy'];
        $has_pipe = strpos($target, '|') !== false;
        $this->assertTrue($has_pipe, 'Should detect pipe in value');

        $parts = explode('|', $target, 2);
        $this->assertEquals('/modern', $parts[0]);
        $this->assertEquals('code=302', $parts[1]);

        // Parse params
        parse_str($parts[1], $params);
        $this->assertEquals('302', $params['code']);

        // Test url_starts_with
        $url_starts = $options_obj->get('plugins.djebel-redirect.url_starts_with', []);
        $this->assertArrayHasKey('/docs/v1/', $url_starts);
        $this->assertEquals('/docs/latest/', $url_starts['/docs/v1/']);

        // Test url_regex - note: complex regex patterns may be mangled by INI parser
        // For complex regex, consider storing pattern in a different format
        $url_regex = $options_obj->get('plugins.djebel-redirect.url_regex', []);
        $this->assertNotEmpty($url_regex, 'url_regex should have entries');

        // Verify regex pattern was preserved
        $this->assertArrayHasKey('#^/blog/(\d+)#', $url_regex);
        $this->assertEquals('/posts/$1|code=301', $url_regex['#^/blog/(\d+)#']);
    }

    public function testRegexPatternEscaping()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Test various regex patterns with special chars: . * ? ( ) + ^ $ / \ #
        $options_buff = <<<'EOT'
[redirect]
; Dot and star
url_regex[#^/files/.*\.pdf$#] = "/downloads/$0"
; Non-greedy quantifier
url_regex[#^/api/(.*?)/list#] = "/v2/api/$1/items"
; Question mark optional
url_regex[#^/pages?/(\d+)#] = "/page/$1"
; Plus quantifier
url_regex[#^/tags/(\w+)#] = "/category/$1"
; Complex pattern
url_regex[#^/archive/(\d{4})/(\d{2})/?$#] = "/blog/$1-$2"
EOT;

        $cfg = $options_obj->parseBuffer($options_buff);
        $options_obj->setData($cfg);

        $url_regex = $options_obj->get('redirect.url_regex', []);

        // Test dot and star preserved
        $this->assertArrayHasKey('#^/files/.*\.pdf$#', $url_regex);
        $this->assertEquals('/downloads/$0', $url_regex['#^/files/.*\.pdf$#']);

        // Test non-greedy (.*?) preserved
        $this->assertArrayHasKey('#^/api/(.*?)/list#', $url_regex);
        $this->assertEquals('/v2/api/$1/items', $url_regex['#^/api/(.*?)/list#']);

        // Test question mark preserved
        $this->assertArrayHasKey('#^/pages?/(\d+)#', $url_regex);
        $this->assertEquals('/page/$1', $url_regex['#^/pages?/(\d+)#']);

        // Test plus quantifier preserved
        $this->assertArrayHasKey('#^/tags/(\w+)#', $url_regex);
        $this->assertEquals('/category/$1', $url_regex['#^/tags/(\w+)#']);

        // Test complex pattern with multiple special chars
        $this->assertArrayHasKey('#^/archive/(\d{4})/(\d{2})/?$#', $url_regex);
        $this->assertEquals('/blog/$1-$2', $url_regex['#^/archive/(\d{4})/(\d{2})/?$#']);
    }

    public function testCommaSeparatedFallbackKeys()
    {
        $ini_content = <<<EOT
[theme]
theme_id = my-theme

[site]
theme_id = site-theme
title = Test Site

[meta]
default.title = Default Meta Title
EOT;

        $options_obj = Dj_App_Options::getInstance();
        $parsed_data = $options_obj->parseBuffer($ini_content);
        $options_obj->setData($parsed_data);

        // Test single key first to ensure basic get() works
        $single_result = $options_obj->get('theme.theme_id');
        $this->assertEquals('my-theme', $single_result, 'Single key get should work');

        // Test fallback: first key exists
        $result = $options_obj->get('theme.theme_id, site.theme_id', 'default');
        $this->assertEquals('my-theme', $result, 'Should return first key value when it exists');

        // Test fallback: first key empty, second exists
        $result = $options_obj->get('theme.nonexistent, site.theme_id', 'default');
        $this->assertEquals('site-theme', $result, 'Should fallback to second key when first is empty');

        // Test fallback: all keys empty, use default
        $result = $options_obj->get('theme.missing1,theme.missing2,site.missing3', 'fallback-value');
        $this->assertEquals('fallback-value', $result, 'Should return default when all keys are empty');

        // Test fallback with whitespace (should trim)
        $result = $options_obj->get('theme.theme_id, site.theme_id , meta.nonexistent', 'default');
        $this->assertEquals('my-theme', $result, 'Should handle whitespace in comma-separated keys');

        // Test realistic theme detection scenario
        $result = $options_obj->get('theme.theme,theme.theme_id,site.theme_id,site.theme', 'default');
        $this->assertEquals('my-theme', $result, 'Should find theme_id when theme is not set');

        // Test nested key in fallback chain
        $result = $options_obj->get('theme.missing,meta.default.title', 'default');
        $this->assertEquals('Default Meta Title', $result, 'Should work with nested keys in fallback');

        // Test single key (no comma) still works
        $result = $options_obj->get('site.title', 'default');
        $this->assertEquals('Test Site', $result, 'Single key without comma should still work');

        // Test empty default
        $result = $options_obj->get('missing1,missing2,missing3');
        $this->assertEquals('', $result, 'Should return empty string when no default provided');
    }

    public function testEvaluateEnvConditionNoEqualsChecksIsEnabled()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Enabled values should match
        putenv('DJ_TEST_DEV_ENV=1');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEquals('0', $result, 'DEV_ENV=1 should match (enabled)');
        putenv('DJ_TEST_DEV_ENV');

        putenv('DJ_TEST_DEV_ENV=true');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEquals('0', $result, 'DEV_ENV=true should match (enabled)');
        putenv('DJ_TEST_DEV_ENV');

        putenv('DJ_TEST_DEV_ENV=yes');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEquals('0', $result, 'DEV_ENV=yes should match (enabled)');
        putenv('DJ_TEST_DEV_ENV');

        // Disabled values should NOT match
        putenv('DJ_TEST_DEV_ENV=0');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEmpty($result, 'DEV_ENV=0 should not match (disabled)');
        putenv('DJ_TEST_DEV_ENV');

        putenv('DJ_TEST_DEV_ENV=false');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEmpty($result, 'DEV_ENV=false should not match (disabled)');
        putenv('DJ_TEST_DEV_ENV');

        putenv('DJ_TEST_DEV_ENV=no');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEmpty($result, 'DEV_ENV=no should not match (disabled)');
        putenv('DJ_TEST_DEV_ENV');

        // Unset env var should NOT match
        putenv('DJ_TEST_DEV_ENV');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV:0');
        $this->assertEmpty($result, 'Unset env var should not match');
    }

    public function testEvaluateEnvConditionExactMatch()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_APP_ENV=dev');

        // Exact match: APP_ENV=dev → returns "0"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=dev:0');
        $this->assertEquals('0', $result, 'Should return result on exact match');

        // No match: APP_ENV=live → returns ""
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=live:0');
        $this->assertEmpty($result, 'Should return empty on no match');

        putenv('DJ_TEST_APP_ENV');
    }

    public function testEvaluateEnvConditionStartsWith()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_APP_ENV=development');

        // Starts with: dev* matches "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=dev*:0');
        $this->assertEquals('0', $result, 'dev* should match development');

        // Starts with: prod* does NOT match "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=prod*:0');
        $this->assertEmpty($result, 'prod* should not match development');

        putenv('DJ_TEST_APP_ENV');
    }

    public function testEvaluateEnvConditionEndsWith()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_APP_ENV=development');

        // Ends with: *ment matches "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=*ment:0');
        $this->assertEquals('0', $result, '*ment should match development');

        // Ends with: *tion does NOT match "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=*tion:0');
        $this->assertEmpty($result, '*tion should not match development');

        putenv('DJ_TEST_APP_ENV');
    }

    public function testEvaluateEnvConditionContains()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_APP_ENV=development');

        // Contains: *vel* matches "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=*vel*:0');
        $this->assertEquals('0', $result, '*vel* should match development');

        // Contains: *stag* does NOT match "development"
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=*stag*:0');
        $this->assertEmpty($result, '*stag* should not match development');

        putenv('DJ_TEST_APP_ENV');
    }

    public function testEvaluateEnvConditionMalformed()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_DEV_ENV=1');

        // Malformed: missing result part
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV');
        $this->assertEmpty($result, 'Malformed directive (no result) should return empty');

        // Empty condition
        $result = $options_obj->evaluateEnvCondition('@if_env::somevalue');
        $this->assertEmpty($result, 'Empty condition should return empty');

        putenv('DJ_TEST_DEV_ENV');
    }

    public function testEvaluateEnvConditionNegativeMatch()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // != exact: APP_ENV!=dev when APP_ENV=live → should match
        putenv('DJ_TEST_APP_ENV=live');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev:1');
        $this->assertEquals('1', $result, 'APP_ENV=live != dev should match');
        putenv('DJ_TEST_APP_ENV');

        // != exact: APP_ENV!=dev when APP_ENV=dev → should NOT match
        putenv('DJ_TEST_APP_ENV=dev');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev:1');
        $this->assertEmpty($result, 'APP_ENV=dev != dev should not match');
        putenv('DJ_TEST_APP_ENV');

        // != with wildcard: APP_ENV!=dev* when APP_ENV=production → should match
        putenv('DJ_TEST_APP_ENV=production');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev*:1');
        $this->assertEquals('1', $result, 'APP_ENV=production != dev* should match');
        putenv('DJ_TEST_APP_ENV');

        // != with wildcard: APP_ENV!=dev* when APP_ENV=development → should NOT match
        putenv('DJ_TEST_APP_ENV=development');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev*:1');
        $this->assertEmpty($result, 'APP_ENV=development != dev* should not match');
        putenv('DJ_TEST_APP_ENV');

        // != with pipe: APP_ENV!=dev|staging when APP_ENV=production → should match
        putenv('DJ_TEST_APP_ENV=production');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev|staging:1');
        $this->assertEquals('1', $result, 'APP_ENV=production != dev|staging should match');
        putenv('DJ_TEST_APP_ENV');

        // != with pipe: APP_ENV!=dev|staging when APP_ENV=staging → should NOT match
        putenv('DJ_TEST_APP_ENV=staging');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev|staging:1');
        $this->assertEmpty($result, 'APP_ENV=staging != dev|staging should not match');
        putenv('DJ_TEST_APP_ENV');

        // != when env var is unset → should match (unset != anything)
        putenv('DJ_TEST_APP_ENV');
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV!=dev:1');
        $this->assertEquals('1', $result, 'Unset env var != dev should match');
    }

    public function testEvaluateEnvConditionResultWithColons()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_DEV_ENV=1');

        // Result contains colons (URL): should preserve everything after second colon
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_DEV_ENV=1:http://localhost:8080');
        $this->assertEquals('http://localhost:8080', $result, 'Result with colons should be preserved');

        putenv('DJ_TEST_DEV_ENV');
    }

    public function testEvaluateEnvConditionEnvVarNotSetWithEquals()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Make sure env var is NOT set
        putenv('DJ_TEST_MISSING_VAR');

        // When env var doesn't exist, = check should return empty
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_MISSING_VAR=dev:0');
        $this->assertEmpty($result, 'Should return empty when env var does not exist');
    }

    public function testProcessConditionalValues()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_DEV_ENV=1');
        putenv('DJ_TEST_APP_ENV=dev');

        // Simulate flat 2-level array from parse_ini_file()
        $data = [
            'theme' => [
                'theme_id' => 'djebel',
                'load_theme' => '@if_env:DJ_TEST_DEV_ENV=1:0',
            ],
            'site' => [
                'site_title' => 'My Site',
                'debug' => '@if_env:DJ_TEST_APP_ENV=dev:1',
            ],
        ];

        $result = $options_obj->processConditionalValues($data);

        // theme_id should remain unchanged (not a directive)
        $this->assertEquals('djebel', $result['theme']['theme_id']);

        // load_theme should resolve to "0" (DJ_TEST_DEV_ENV=1 matches)
        $this->assertEquals('0', $result['theme']['load_theme']);

        // site_title should remain unchanged
        $this->assertEquals('My Site', $result['site']['site_title']);

        // debug should resolve to "1" (DJ_TEST_APP_ENV=dev matches)
        $this->assertEquals('1', $result['site']['debug']);

        putenv('DJ_TEST_DEV_ENV');
        putenv('DJ_TEST_APP_ENV');
    }

    public function testProcessConditionalValuesNoMatch()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        // Make sure env vars are NOT set
        putenv('DJ_TEST_DEV_ENV');

        $data = [
            'theme' => [
                'theme_id' => 'djebel',
                'load_theme' => '@if_env:DJ_TEST_DEV_ENV=1:0',
            ],
        ];

        $result = $options_obj->processConditionalValues($data);

        // load_theme should resolve to "" (DJ_TEST_DEV_ENV not set)
        $this->assertEmpty($result['theme']['load_theme']);

        // theme_id unchanged
        $this->assertEquals('djebel', $result['theme']['theme_id']);
    }

    public function testMatchEnvValueExact()
    {
        $options_obj = Dj_App_Options::getInstance();

        $this->assertTrue($options_obj->matchEnvValue('dev', 'dev'));
        $this->assertFalse($options_obj->matchEnvValue('development', 'dev'));
        $this->assertFalse($options_obj->matchEnvValue('live', 'dev'));
    }

    public function testMatchEnvValueStartsWith()
    {
        $options_obj = Dj_App_Options::getInstance();

        $this->assertTrue($options_obj->matchEnvValue('development', 'dev*'));
        $this->assertTrue($options_obj->matchEnvValue('dev', 'dev*'));
        $this->assertFalse($options_obj->matchEnvValue('production', 'dev*'));
    }

    public function testMatchEnvValueEndsWith()
    {
        $options_obj = Dj_App_Options::getInstance();

        $this->assertTrue($options_obj->matchEnvValue('development', '*ment'));
        $this->assertTrue($options_obj->matchEnvValue('deployment', '*ment'));
        $this->assertFalse($options_obj->matchEnvValue('developer', '*ment'));
    }

    public function testMatchEnvValueContains()
    {
        $options_obj = Dj_App_Options::getInstance();

        $this->assertTrue($options_obj->matchEnvValue('development', '*vel*'));
        $this->assertTrue($options_obj->matchEnvValue('staging', '*stag*'));
        $this->assertFalse($options_obj->matchEnvValue('production', '*stag*'));
    }

    public function testMatchEnvValuePipeOR()
    {
        $options_obj = Dj_App_Options::getInstance();

        // Pipe = OR: matches if any alternative matches
        $this->assertTrue($options_obj->matchEnvValue('dev', 'dev|staging'));
        $this->assertTrue($options_obj->matchEnvValue('staging', 'dev|staging'));
        $this->assertFalse($options_obj->matchEnvValue('production', 'dev|staging'));

        // Pipe with wildcards
        $this->assertTrue($options_obj->matchEnvValue('development', 'dev*|stag*'));
        $this->assertTrue($options_obj->matchEnvValue('staging', 'dev*|stag*'));
        $this->assertFalse($options_obj->matchEnvValue('production', 'dev*|stag*'));

        // Pipe with spaces around values
        $this->assertTrue($options_obj->matchEnvValue('dev', 'dev | staging'));
        $this->assertTrue($options_obj->matchEnvValue('staging', 'dev | staging'));
        $this->assertTrue($options_obj->matchEnvValue('dev', ' dev|staging '));
        $this->assertFalse($options_obj->matchEnvValue('production', 'dev | staging'));

        // Pipe with truthy values: 1|true|yes|on
        $this->assertTrue($options_obj->matchEnvValue('1', '1|true|yes|on'));
        $this->assertTrue($options_obj->matchEnvValue('true', '1|true|yes|on'));
        $this->assertTrue($options_obj->matchEnvValue('yes', '1|true|yes|on'));
        $this->assertTrue($options_obj->matchEnvValue('on', '1|true|yes|on'));
        $this->assertFalse($options_obj->matchEnvValue('0', '1|true|yes|on'));
        $this->assertFalse($options_obj->matchEnvValue('false', '1|true|yes|on'));
    }

    public function testEvaluateEnvConditionWithPipe()
    {
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();

        putenv('DJ_TEST_APP_ENV=staging');

        // Pipe OR in full directive
        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=dev|staging:0');
        $this->assertEquals('0', $result, 'Pipe should match staging');

        putenv('DJ_TEST_APP_ENV=production');

        $result = $options_obj->evaluateEnvCondition('@if_env:DJ_TEST_APP_ENV=dev|staging:0');
        $this->assertEmpty($result, 'Pipe should not match production');

        putenv('DJ_TEST_APP_ENV');
    }
}