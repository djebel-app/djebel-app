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

        // Property chaining: accessing nonexistent key in existing section returns empty Options object
        $missing_property = $options_obj->theme->nonexistent_key;
        $this->assertInstanceOf(Dj_App_Options::class, $missing_property, 'Missing property should return empty Options object');
        $this->assertEquals('', (string)$missing_property, 'Empty Options should convert to empty string');

        // Accessing totally nonexistent section returns empty Options object
        $nonexistent_section = $options_obj->nonexistent_section;
        $this->assertInstanceOf(Dj_App_Options::class, $nonexistent_section, 'Nonexistent section should return empty Options object');
        $this->assertEquals('', (string)$nonexistent_section, 'Empty Options should convert to empty string');

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
        // Verify property chaining returns proper scalars
        $theme_value = $options_obj->theme->theme;
        $theme_id_value = $options_obj->theme->theme_id;

        $this->assertInstanceOf(Dj_App_Options::class, $theme_value, 'theme->theme should return empty Options object');
        $this->assertEquals('', (string)$theme_value, 'Empty Options should convert to empty string');

        $this->assertIsString($theme_id_value, 'theme->theme_id should return string');
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
}