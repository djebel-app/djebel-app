<?php

use PHPUnit\Framework\TestCase;

class Util_Test extends TestCase {
    private $result;
    
    public function setUp() : void {
    }

    public function tearDown() : void {
    }

    public function testinjectContent() {
        $buff = '<html><head></head><body></body></html>';
        $inject_content = '__CONTENT__';
        $exp_buff = "<html><head>$inject_content</head><body></body></html>";

        // must be injected after the tag
        $new_buff = Dj_App_Util::injectContent(
            $inject_content,
            $buff,
            '<head'
        );

        $this->assertEquals($exp_buff, $new_buff);

        // must be injected before the tag
        $inject_content = '__CONTENT__';
        $exp_buff = "<html><head>$inject_content</head><body></body></html>";

        $new_buff = Dj_App_Util::injectContent(
            $inject_content,
            $buff,
            '</head'
        );

        $this->assertEquals($exp_buff, $new_buff);
    }

    public function testReplaceTagContent()
    {
        // Test replacing existing content
        $buff = '<html><head><title>Old Title</title></head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
        $this->assertEquals('<html><head><title>New Title</title></head></html>', $new_buff);

        // Test with tag having attributes
        $buff = '<html><head><title class="main">Old Title</title></head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
        $this->assertEquals('<html><head><title class="main">New Title</title></head></html>', $new_buff);

        // Test adding new tag when not found
        $buff = '<html><head></head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'Added Title', $buff);
        $this->assertEquals("<html><head><title>Added Title</title>\n</head></html>", $new_buff);

        // Test with tag passed with brackets
        $buff = '<html><head><title>Old Title</title></head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('<title>', 'New Title', $buff);
        $this->assertEquals('<html><head><title>New Title</title></head></html>', $new_buff);

        // Test with empty buffer
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', '');
        $this->assertEquals('', $new_buff);

        // Test with empty tag
        $buff = '<html><head><title>Old Title</title></head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('', 'New Title', $buff);
        $this->assertEquals($buff, $new_buff);

        // Test with malformed HTML (no end tag)
        $buff = '<html><head><title>Old Title</head></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
        $this->assertEquals($buff, $new_buff);

        // Test adding title tag specifically to head section
        $buff = '<html><head></head><body></body></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
        
        $this->assertEquals('<html><head><title>New Title</title>' . "\n" . '</head><body></body></html>', $new_buff);

        // Test adding title when no head tag exists
        $buff = '<html><body></body></html>';
        $new_buff = Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
        $this->assertEquals("<html><body></body></html>\n<title>New Title</title>", $new_buff);
    }

    public function testreplaceTagContent2()
    {
        $buff = <<<BUFF_EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>aaaaaa</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="__theme_url__/css/style.css" />
</head>
<body class="dj-app-default-body">
    Content
</body>
</html>
BUFF_EOF;

        $new_title = 'test_dj_dir';
        $params['theme_url'] = 'https://djebel.com/test_dj_dir';
        $buff1 = Dj_App_Util::replaceTagContent('title', $new_title, $buff);
        $this->assertStringContainsString("<title>$new_title</title>", $buff1);

        $buff = <<<BUFF_EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="____theme_uri____/css/style.css" />
</head>
<body class="dj-app-default-body">
    Content
</body>
</html>
BUFF_EOF;
        $buff2 = Dj_App_Util::replaceTagContent('title', $new_title, $buff); // auto add the tag
        $this->assertStringContainsString("<title>$new_title</title>", $buff2);
    }

    public function testreplaceMagicVars()
    {
        $buff = <<<BUFF_EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>aaaaaa</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="__theme_url__/css/style.css" />
</head>
<body class="dj-app-default-body">
    Content
</body>
</html>
BUFF_EOF;

        $params['theme_dir'] = 'test_dj_dir';
        $params['theme_url'] = 'https://djebel.com/test_dj_dir';
        $buff = Dj_App_Util::replaceMagicVars($buff, $params);
        $this->assertStringContainsString($params['theme_url'], $buff);

        $buff = <<<BUFF_EOF
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>aaaaaa</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="____theme_uri____/css/style.css" />
    <link rel="stylesheet" href="____theme_url____/css/style2.css" />
    <link rel="stylesheet" href="____---theme_url____---/css/style3.css" />
</head>
<body class="dj-app-default-body">
    Content
</body>
</html>
BUFF_EOF;

        $buff = Dj_App_Util::cleanMagicVars($buff, $params);
        $this->assertStringContainsStringIgnoringCase('__theme_url__/css/style.css', $buff);
        $this->assertStringContainsStringIgnoringCase('__theme_url__/css/style2.css', $buff);
        $this->assertStringContainsStringIgnoringCase('__theme_url__/css/style3.css', $buff);
    }

    public function testIsEnabled()
    {
        // Test empty values - should return false
        $this->assertFalse(Dj_App_Util::isEnabled(''));
        $this->assertFalse(Dj_App_Util::isEnabled(null));
        $this->assertFalse(Dj_App_Util::isEnabled(0));
        $this->assertFalse(Dj_App_Util::isEnabled('0'));
        $this->assertFalse(Dj_App_Util::isEnabled(false));
        $this->assertFalse(Dj_App_Util::isEnabled([]));

        // Test boolean true - should return true
        $this->assertTrue(Dj_App_Util::isEnabled(true));

        // Test numeric values - positive numbers should return true
        $this->assertTrue(Dj_App_Util::isEnabled(1));
        $this->assertTrue(Dj_App_Util::isEnabled('1'));
        $this->assertTrue(Dj_App_Util::isEnabled(42));
        $this->assertTrue(Dj_App_Util::isEnabled('42'));
        $this->assertTrue(Dj_App_Util::isEnabled(1000));

        // Test numeric values - zero should return false
        $this->assertFalse(Dj_App_Util::isEnabled(0));
        $this->assertFalse(Dj_App_Util::isEnabled('0'));

        // Test string values - 'true' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isEnabled('true'));
        $this->assertTrue(Dj_App_Util::isEnabled('TRUE'));
        $this->assertTrue(Dj_App_Util::isEnabled('True'));
        $this->assertTrue(Dj_App_Util::isEnabled('tRuE'));

        // Test string values - 'yes' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isEnabled('yes'));
        $this->assertTrue(Dj_App_Util::isEnabled('YES'));
        $this->assertTrue(Dj_App_Util::isEnabled('Yes'));
        $this->assertTrue(Dj_App_Util::isEnabled('yEs'));

        // Test string values - 'on' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isEnabled('on'));
        $this->assertTrue(Dj_App_Util::isEnabled('ON'));
        $this->assertTrue(Dj_App_Util::isEnabled('On'));
        $this->assertTrue(Dj_App_Util::isEnabled('oN'));

        // Test string values - 'enabled' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isEnabled('enabled'));
        $this->assertTrue(Dj_App_Util::isEnabled('ENABLED'));
        $this->assertTrue(Dj_App_Util::isEnabled('Enabled'));
        $this->assertTrue(Dj_App_Util::isEnabled('eNaBlEd'));

        // Test other string values - should return false
        $this->assertFalse(Dj_App_Util::isEnabled('false'));
        $this->assertFalse(Dj_App_Util::isEnabled('no'));
        $this->assertFalse(Dj_App_Util::isEnabled('maybe'));
        $this->assertTrue(Dj_App_Util::isEnabled('enabled')); // now supported
        $this->assertFalse(Dj_App_Util::isEnabled('disabled'));

        // Test edge cases
        $this->assertTrue(Dj_App_Util::isEnabled('true ')); // trailing space - now trimmed
        $this->assertTrue(Dj_App_Util::isEnabled(' true')); // leading space - now trimmed
        $this->assertFalse(Dj_App_Util::isEnabled('truee')); // extra character
        $this->assertFalse(Dj_App_Util::isEnabled('yess')); // extra character
    }

    public function testIsDisabled()
    {
        // Test core disabled values - 0, false, 'no', boolean false
        $this->assertTrue(Dj_App_Util::isDisabled(0));           // numeric zero
        $this->assertTrue(Dj_App_Util::isDisabled('0'));         // string zero
        $this->assertTrue(Dj_App_Util::isDisabled(false));       // boolean false
        $this->assertTrue(Dj_App_Util::isDisabled('no'));        // string 'no'
        $this->assertTrue(Dj_App_Util::isDisabled('NO'));        // uppercase 'no'
        $this->assertTrue(Dj_App_Util::isDisabled('No'));        // mixed case 'no'

        // Test empty values - should return true
        $this->assertFalse(Dj_App_Util::isDisabled('')); // empty string should NOT be disabled. Need specific value
        $this->assertFalse(Dj_App_Util::isDisabled(null));
        $this->assertFalse(Dj_App_Util::isDisabled([])); // array is not scalar, should be NOT disabled

        // Test boolean true - should return false
        $this->assertFalse(Dj_App_Util::isDisabled(true));

        // Test numeric values - positive numbers should return false
        $this->assertFalse(Dj_App_Util::isDisabled(1));
        $this->assertFalse(Dj_App_Util::isDisabled('1'));
        $this->assertFalse(Dj_App_Util::isDisabled(42));
        $this->assertFalse(Dj_App_Util::isDisabled('42'));
        $this->assertFalse(Dj_App_Util::isDisabled(1000));

        // Test string values - 'false' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isDisabled('false'));
        $this->assertTrue(Dj_App_Util::isDisabled('FALSE'));
        $this->assertTrue(Dj_App_Util::isDisabled('False'));
        $this->assertTrue(Dj_App_Util::isDisabled('fAlSe'));

        // Test string values - 'off' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isDisabled('off'));
        $this->assertTrue(Dj_App_Util::isDisabled('OFF'));
        $this->assertTrue(Dj_App_Util::isDisabled('Off'));
        $this->assertTrue(Dj_App_Util::isDisabled('oFf'));

        // Test string values - 'disabled' should return true (case insensitive)
        $this->assertTrue(Dj_App_Util::isDisabled('disabled'));
        $this->assertTrue(Dj_App_Util::isDisabled('DISABLED'));
        $this->assertTrue(Dj_App_Util::isDisabled('Disabled'));
        $this->assertTrue(Dj_App_Util::isDisabled('dIsAbLeD'));

        // Test other string values - should return false
        $this->assertFalse(Dj_App_Util::isDisabled('true'));
        $this->assertFalse(Dj_App_Util::isDisabled('yes'));
        $this->assertFalse(Dj_App_Util::isDisabled('on'));
        $this->assertFalse(Dj_App_Util::isDisabled('enabled'));
        $this->assertFalse(Dj_App_Util::isDisabled('maybe'));
        $this->assertFalse(Dj_App_Util::isDisabled('unknown'));

        // Test edge cases with whitespace
        $this->assertTrue(Dj_App_Util::isDisabled('false ')); // trailing space - now trimmed
        $this->assertTrue(Dj_App_Util::isDisabled(' false')); // leading space - now trimmed
        $this->assertTrue(Dj_App_Util::isDisabled('no ')); // trailing space - now trimmed
        $this->assertTrue(Dj_App_Util::isDisabled(' no')); // leading space - now trimmed
        $this->assertFalse(Dj_App_Util::isDisabled('falsee')); // extra character
        $this->assertFalse(Dj_App_Util::isDisabled('nno')); // extra character
    }

    /**
     * Test replaceMetaTagContent method - basic replacement functionality
     */
    public function testReplaceMetaTagContentBasicReplacement() {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="author" content="Old Author">
    <meta name="description" content="Test page">
</head>
<body></body>
</html>';

        $expected = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="author" content="New Author">
    <meta name="description" content="Test page">
</head>
<body></body>
</html>';

        $result = Dj_App_Util::replaceMetaTagContent('author', 'New Author', $html);
        $this->assertEquals($expected, $result);
    }

    /**
     * Test replaceMetaTagContent method - adding new meta tag when it doesn't exist
     */
    public function testReplaceMetaTagContentAddNewTag() {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta charset="utf-8">
</head>
<body></body>
</html>';

        $result = Dj_App_Util::replaceMetaTagContent('keywords', 'php, web, development', $html);
        
        $this->assertStringContainsString('<meta name="keywords" content="php, web, development">', $result);
        $this->assertStringContainsString('<meta charset="utf-8">', $result);
    }

    /**
     * Test replaceMetaTagContent method - no change when content already matches
     */
    public function testReplaceMetaTagContentNoChangeNeeded() {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="author" content="John Doe">
</head>
<body></body>
</html>';

        $result = Dj_App_Util::replaceMetaTagContent('author', 'John Doe', $html);
        $this->assertEquals($html, $result);
    }

    /**
     * Test replaceMetaTagContent method - special characters are properly encoded
     */
    public function testReplaceMetaTagContentSpecialCharacters() {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="description" content="Old description">
</head>
<body></body>
</html>';

        $special_content = 'A "quoted" description & special chars <script>';
        $result = Dj_App_Util::replaceMetaTagContent('description', $special_content, $html);
        
        $this->assertStringContainsString('&quot;', $result);
        $this->assertStringContainsString('&amp;', $result);
        $this->assertStringContainsString('&lt;', $result);
    }

    /**
     * Test replaceMetaTagContent method - non-HTML content returns unchanged
     */
    public function testReplaceMetaTagContentNonHtmlContent() {
        $text = 'This is just plain text without any HTML tags';
        $result = Dj_App_Util::replaceMetaTagContent('author', 'John Doe', $text);
        $this->assertEquals($text, $result);
    }

    /**
     * Test replaceMetaTagContent method - HTML without head section returns unchanged
     */
    public function testReplaceMetaTagContentNoHeadSection() {
        $html = '<html><body><h1>No Head Tag</h1></body></html>';
        $result = Dj_App_Util::replaceMetaTagContent('author', 'John Doe', $html);
        $this->assertEquals($html, $result);
    }

    /**
     * Test replaceMetaTagContent method - complex attributes are preserved
     */
    public function testReplaceMetaTagContentComplexAttributes() {
        $html = '<!DOCTYPE html>
<html>
<head>
    <title>Test</title>
    <meta name="description" content="Old description" property="og:description" data-test="value">
</head>
<body></body>
</html>';

        $result = Dj_App_Util::replaceMetaTagContent('description', 'New description', $html);
        
        $this->assertStringContainsString('property="og:description"', $result);
        $this->assertStringContainsString('data-test="value"', $result);
        $this->assertStringContainsString('content="New description"', $result);
    }

    /**
     * Test replaceMetaTagContent method - empty inputs
     */
    public function testReplaceMetaTagContentEmptyInputs() {
        $html = '<!DOCTYPE html><html><head><title>Test</title></head><body></body></html>';
        
        // Empty tag name
        $result1 = Dj_App_Util::replaceMetaTagContent('', 'content', $html);
        $this->assertEquals($html, $result1);
        
        // Empty buffer
        $result2 = Dj_App_Util::replaceMetaTagContent('author', 'content', '');
        $this->assertEquals('', $result2);
    }

    /**
     * Test replaceSystemVars method - various scenarios for system variable replacement
     */
    public function testReplaceSystemVars() {
        // Test with empty/null values
        $this->assertEquals('', Dj_App_Config::replaceSystemVars(''));
        $this->assertEquals(null, Dj_App_Config::replaceSystemVars(null));
        $this->assertEquals(0, Dj_App_Config::replaceSystemVars(0));
        
        // Test with non-scalar values
        $this->assertEquals([], Dj_App_Config::replaceSystemVars([]));
        $this->assertEquals((object)[], Dj_App_Config::replaceSystemVars((object)[]));
        
        // Test with values without system variables
        $this->assertEquals('plain text', Dj_App_Config::replaceSystemVars('plain text'));
        $this->assertEquals('text with {brackets}', Dj_App_Config::replaceSystemVars('text with {brackets}'));
        $this->assertEquals('text with {other_var}', Dj_App_Config::replaceSystemVars('text with {other_var}'));
        
        // Test with {home} variable
        $home = Dj_App_Config::replaceSystemVars('{home}');
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{home}'));
        $this->assertEquals("Path: $home", Dj_App_Config::replaceSystemVars('Path: {home}'));
        $this->assertEquals("$home/path/to/file", Dj_App_Config::replaceSystemVars('{home}/path/to/file'));
        
        // Test with {user_home} variable
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{user_home}'));
        $this->assertEquals("User home: $home", Dj_App_Config::replaceSystemVars('User home: {user_home}'));
        
        // Test case sensitivity
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{HOME}'));
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{Home}'));
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{USER_HOME}'));
        
        // Test mixed content with system variables
        $this->assertEquals("Config: $home/config.ini", Dj_App_Config::replaceSystemVars('Config: {home}/config.ini'));
        $this->assertEquals("$home/docs and $home/src", Dj_App_Config::replaceSystemVars('{home}/docs and {user_home}/src'));
        
        // Test with options parameter (should be ignored as per current implementation)
        $this->assertEquals($home, Dj_App_Config::replaceSystemVars('{home}', ['some_option' => 'value']));
        
        // Test with complex string containing multiple system variables
        $complex_string = "Project structure:\n- {home}/src\n- {user_home}/docs\n- {home}/tests";
        $expected = "Project structure:\n- $home/src\n- $home/docs\n- $home/tests";
        $this->assertEquals($expected, Dj_App_Config::replaceSystemVars($complex_string));
    }
}