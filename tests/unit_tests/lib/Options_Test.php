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

    public function testNestedAccess()
    {
        // Simple test with direct data
        $options_obj = Dj_App_Options::getInstance();
        $options_obj->clear();
        
        $test_data = [
            'site' => [
                'site_title' => 'Sample Site',
                'description' => 'This is a sample site',
                'theme' => [
                    'name' => 'default',
                    'version' => '1.0.0',
                    'settings' => [
                        'color_scheme' => 'dark',
                        'font_size' => '14px'
                    ]
                ]
            ]
        ];
        
        $options_obj->setData($test_data);

        // Test object-style nested access
        $site = $options_obj->site;
        $this->assertInstanceOf('Dj_App_Options', $site);
        $this->assertEquals('Sample Site', $site->site_title);
        $this->assertEquals('This is a sample site', $site->description);

        // Test deeper nested access
        $theme = $site->theme;
        $this->assertInstanceOf('Dj_App_Options', $theme);
        $this->assertEquals('default', $theme->name);
        $this->assertEquals('1.0.0', $theme->version);

        // Test even deeper nested access
        $settings = $theme->settings;
        $this->assertInstanceOf('Dj_App_Options', $settings);
        $this->assertEquals('dark', $settings->color_scheme);
        $this->assertEquals('14px', $settings->font_size);

        // Test array-style access
        $this->assertEquals('Sample Site', $site['site_title']);
        $this->assertEquals('This is a sample site', $site['description']);
        
        // Test nested array access
        $theme_array = $site['theme'];
        $this->assertInstanceOf('Dj_App_Options', $theme_array);
        $this->assertEquals('default', $theme_array['name']);
        $this->assertEquals('1.0.0', $theme_array['version']);

        // Test even deeper array access
        $settings_array = $site['theme']['settings'];
        $this->assertInstanceOf('Dj_App_Options', $settings_array);
        $this->assertEquals('dark', $settings_array['color_scheme']);
        $this->assertEquals('14px', $settings_array['font_size']);


        // Test that we can still use the get() method on nested objects
        $this->assertEquals('Sample Site', $site->get('site_title'));
        $this->assertEquals('default', $site->get('nonexistent', 'default'));
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
        $this->assertEquals('', $non_existent);

        $non_existent_with_default = $options_obj->get('meta.default.nonexistent', 'fallback');
        $this->assertEquals('fallback', $non_existent_with_default);

        // Test partial path that doesn't exist
        $non_existent_section = $options_obj->get('nonexistent.section.key');
        $this->assertEquals('', $non_existent_section);
    }
}