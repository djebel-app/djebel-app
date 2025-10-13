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
        $this->assertEquals('', $non_existent);

        $non_existent_with_default = $options_obj->get('meta.default.nonexistent', 'fallback');
        $this->assertEquals('fallback', $non_existent_with_default);

        // Test partial path that doesn't exist
        $non_existent_section = $options_obj->get('nonexistent.section.key');
        $this->assertEquals('', $non_existent_section);
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
}