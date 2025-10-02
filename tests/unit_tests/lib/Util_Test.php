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

    /**
     * Test addSlash method with different flag combinations
     */
    public function testAddSlash() {
        // Test empty URL
        $this->assertEquals('/', Dj_App_Util::addSlash(''));
        $this->assertEquals('/', Dj_App_Util::addSlash(null));
        
        // Test FLAG_TRAILING (default)
        $this->assertEquals('path/', Dj_App_Util::addSlash('path'));
        $this->assertEquals('path/', Dj_App_Util::addSlash('path/'));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('/path'));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('/path/'));
        
        // Test FLAG_LEADING
        $this->assertEquals('/path', Dj_App_Util::addSlash('path', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('/path', Dj_App_Util::addSlash('/path', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('path/', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('/path/', Dj_App_Util::FLAG_LEADING));
        
        // Test FLAG_BOTH
        $this->assertEquals('/path/', Dj_App_Util::addSlash('path', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('/path', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('path/', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('/path/', Dj_App_Util::addSlash('/path/', Dj_App_Util::FLAG_BOTH));
        
        // Test single slash
        $this->assertEquals('/', Dj_App_Util::addSlash('/'));
        $this->assertEquals('/', Dj_App_Util::addSlash('/', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('/', Dj_App_Util::addSlash('/', Dj_App_Util::FLAG_BOTH));
        
        // Test empty flags
        $this->assertEquals('path', Dj_App_Util::addSlash('path', 0));
        $this->assertEquals('/', Dj_App_Util::addSlash('', 0));
    }

    /**
     * Test removeSlash method with different flag combinations
     */
    public function testRemoveSlash() {
        // Test empty URL
        $this->assertEquals('', Dj_App_Util::removeSlash(''));
        $this->assertEquals('', Dj_App_Util::removeSlash(null));
        
        // Test FLAG_TRAILING (default)
        $this->assertEquals('path', Dj_App_Util::removeSlash('path'));
        $this->assertEquals('path', Dj_App_Util::removeSlash('path/'));
        $this->assertEquals('/path', Dj_App_Util::removeSlash('/path'));
        $this->assertEquals('/path', Dj_App_Util::removeSlash('/path/'));
        
        // Test FLAG_LEADING
        $this->assertEquals('path', Dj_App_Util::removeSlash('path', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('path', Dj_App_Util::removeSlash('/path', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('path/', Dj_App_Util::removeSlash('path/', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('path/', Dj_App_Util::removeSlash('/path/', Dj_App_Util::FLAG_LEADING));
        
        // Test FLAG_BOTH
        $this->assertEquals('path', Dj_App_Util::removeSlash('path', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('path', Dj_App_Util::removeSlash('/path', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('path', Dj_App_Util::removeSlash('path/', Dj_App_Util::FLAG_BOTH));
        $this->assertEquals('path', Dj_App_Util::removeSlash('/path/', Dj_App_Util::FLAG_BOTH));
        
        // Test single slash
        $this->assertEquals('', Dj_App_Util::removeSlash('/'));
        $this->assertEquals('', Dj_App_Util::removeSlash('/', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('', Dj_App_Util::removeSlash('/', Dj_App_Util::FLAG_BOTH));
        
        // Test empty flags
        $this->assertEquals('path', Dj_App_Util::removeSlash('path', 0));
        $this->assertEquals('', Dj_App_Util::removeSlash('', 0));
        
        // Test multiple slashes (removes all consecutive slashes)
        $this->assertEquals('path', Dj_App_Util::removeSlash('path///', Dj_App_Util::FLAG_TRAILING));
        $this->assertEquals('path///', Dj_App_Util::removeSlash('///path///', Dj_App_Util::FLAG_LEADING));
        $this->assertEquals('path', Dj_App_Util::removeSlash('///path///', Dj_App_Util::FLAG_BOTH));
    }

    /**
     * Test getCoreCacheDir method - basic functionality
     */
    public function testGetCoreCacheDir() {
        // Test basic cache directory
        $cache_dir = Dj_App_Util::getCoreCacheDir();
        $this->assertNotEmpty($cache_dir);
        $this->assertStringEndsWith('/cache', $cache_dir);
        
        // Test with plugin parameter
        $plugin_cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => 'test-plugin']);
        $this->assertNotEmpty($plugin_cache_dir);
        $this->assertStringEndsWith('/cache/plugins/test-plugin', $plugin_cache_dir);
        
        // Test with different plugin names
        $plugin_cache_dir2 = Dj_App_Util::getCoreCacheDir(['plugin' => 'my-awesome-plugin']);
        $this->assertStringEndsWith('/cache/plugins/my-awesome-plugin', $plugin_cache_dir2);
        
        // Test with empty parameters
        $empty_cache_dir = Dj_App_Util::getCoreCacheDir([]);
        $this->assertEquals($cache_dir, $empty_cache_dir);
        
        // Test with null parameters
        $null_cache_dir = Dj_App_Util::getCoreCacheDir(null);
        $this->assertEquals($cache_dir, $null_cache_dir);
    }

    /**
     * Test getCoreTempDir method - basic functionality
     */
    public function testGetCoreTempDir() {
        // Test basic temp directory
        $temp_dir = Dj_App_Util::getCoreTempDir();
        $this->assertNotEmpty($temp_dir);
        $this->assertStringEndsWith('/tmp', $temp_dir);
        
        // Test with plugin parameter
        $plugin_temp_dir = Dj_App_Util::getCoreTempDir(['plugin' => 'test-plugin']);
        $this->assertNotEmpty($plugin_temp_dir);
        $this->assertStringEndsWith('/tmp/plugins/test-plugin', $plugin_temp_dir);
        
        // Test with different plugin names
        $plugin_temp_dir2 = Dj_App_Util::getCoreTempDir(['plugin' => 'my-awesome-plugin']);
        $this->assertStringEndsWith('/tmp/plugins/my-awesome-plugin', $plugin_temp_dir2);
        
        // Test with empty parameters
        $empty_temp_dir = Dj_App_Util::getCoreTempDir([]);
        $this->assertEquals($temp_dir, $empty_temp_dir);
        
        // Test with null parameters
        $null_temp_dir = Dj_App_Util::getCoreTempDir(null);
        $this->assertEquals($temp_dir, $null_temp_dir);
    }

    /**
     * Test getCoreCacheDir method - plugin name formatting
     */
    public function testGetCoreCacheDirPluginFormatting() {
        // Test with spaces and special characters
        $plugin_cache_dir = Dj_App_Util::getCoreCacheDir(['plugin' => 'My Awesome Plugin!']);
        $this->assertStringEndsWith('/cache/plugins/my_awesome_plugin', $plugin_cache_dir);
        
        // Test with uppercase
        $plugin_cache_dir2 = Dj_App_Util::getCoreCacheDir(['plugin' => 'UPPERCASE_PLUGIN']);
        $this->assertStringEndsWith('/cache/plugins/uppercase_plugin', $plugin_cache_dir2);
        
        // Test with numbers
        $plugin_cache_dir3 = Dj_App_Util::getCoreCacheDir(['plugin' => 'plugin123']);
        $this->assertStringEndsWith('/cache/plugins/plugin123', $plugin_cache_dir3);
    }

    /**
     * Test getCoreTempDir method - plugin name formatting
     */
    public function testGetCoreTempDirPluginFormatting() {
        // Test with spaces and special characters
        $plugin_temp_dir = Dj_App_Util::getCoreTempDir(['plugin' => 'My Awesome Plugin!']);
        $this->assertStringEndsWith('/tmp/plugins/my_awesome_plugin', $plugin_temp_dir);
        
        // Test with uppercase
        $plugin_temp_dir2 = Dj_App_Util::getCoreTempDir(['plugin' => 'UPPERCASE_PLUGIN']);
        $this->assertStringEndsWith('/tmp/plugins/uppercase_plugin', $plugin_temp_dir2);
        
        // Test with numbers
        $plugin_temp_dir3 = Dj_App_Util::getCoreTempDir(['plugin' => 'plugin123']);
        $this->assertStringEndsWith('/tmp/plugins/plugin123', $plugin_temp_dir3);
    }

    /**
     * Test getCoreCacheDir method - consistency with getCorePrivateDataDir
     */
    public function testGetCoreCacheDirConsistency() {
        // Both should use the same base directory
        $cache_dir = Dj_App_Util::getCoreCacheDir();
        $data_dir = Dj_App_Util::getCorePrivateDataDir();
        
        $this->assertStringContainsString('/cache', $cache_dir);
        $this->assertStringContainsString('/data', $data_dir);
        
        // Both should have the same base path structure
        $cache_base = dirname($cache_dir);
        $data_base = dirname($data_dir);
        $this->assertEquals($cache_base, $data_base);
    }

    /**
     * Test getCoreTempDir method - consistency with other directory methods
     */
    public function testGetCoreTempDirConsistency() {
        // Temp dir should use the same base directory as other methods
        $temp_dir = Dj_App_Util::getCoreTempDir();
        $cache_dir = Dj_App_Util::getCoreCacheDir();
        
        $this->assertStringContainsString('/tmp', $temp_dir);
        $this->assertStringContainsString('/cache', $cache_dir);
        
        // Both should have the same base path structure
        $temp_base = dirname($temp_dir);
        $cache_base = dirname($cache_dir);
        $this->assertEquals($temp_base, $cache_base);
    }

    /**
     * Test formatSlug method - basic functionality
     */
    public function testFormatSlug() {
        // Test basic slug formatting
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('My Awesome Plugin!'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test_plugin'));
        $this->assertEquals('uppercase-plugin', Dj_App_String_Util::formatSlug('UPPERCASE_PLUGIN'));
        $this->assertEquals('plugin123', Dj_App_String_Util::formatSlug('plugin123'));
        
        // Test with special characters
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('My Awesome Plugin!'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test-plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test_plugin'));
        
        // Test with spaces
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('My Awesome Plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('Test Plugin'));
        
        // Test with numbers
        $this->assertEquals('plugin-123', Dj_App_String_Util::formatSlug('Plugin 123'));
        $this->assertEquals('plugin123', Dj_App_String_Util::formatSlug('Plugin123'));
    }

    /**
     * Test formatSlug method - comparison with formatStringId
     */
    public function testFormatSlugVsFormatStringId() {
        $input = 'My Awesome Plugin!';
        
        // formatSlug should use dashes
        $slug = Dj_App_String_Util::formatSlug($input);
        $this->assertEquals('my-awesome-plugin', $slug);
        
        // formatStringId should use underscores by default
        $id = Dj_App_String_Util::formatStringId($input);
        $this->assertEquals('my_awesome_plugin', $id);
        
        // formatStringId with FORMAT_CONVERT_TO_DASHES should match formatSlug
        $id_with_dashes = Dj_App_String_Util::formatStringId($input, Dj_App_String_Util::FORMAT_CONVERT_TO_DASHES);
        $this->assertEquals($slug, $id_with_dashes);
    }

    /**
     * Test formatSlug method - edge cases
     */
    public function testFormatSlugEdgeCases() {
        // Test empty input
        $this->assertEquals('', Dj_App_String_Util::formatSlug(''));
        $this->assertEquals('', Dj_App_String_Util::formatSlug(null));
        
        // Test with only special characters
        $this->assertEquals('', Dj_App_String_Util::formatSlug('!!!'));
        $this->assertEquals('', Dj_App_String_Util::formatSlug('   '));
        
        // Test with mixed dashes and underscores
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test_plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test-plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test_-plugin'));
        
        // Test with multiple consecutive special characters
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test___plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test---plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test_-_plugin'));
    }

    /**
     * Test formatSlug method - with custom flags
     */
    public function testFormatSlugWithFlags() {
        $input = 'My Awesome Plugin!';
        
        // Test with KEEP_CASE flag
        $slug_keep_case = Dj_App_String_Util::formatSlug($input, Dj_App_String_Util::KEEP_CASE);
        $this->assertEquals('My-Awesome-Plugin', $slug_keep_case);
        
        // Test with UPPERCASE flag
        $slug_upper = Dj_App_String_Util::formatSlug($input, Dj_App_String_Util::UPPERCASE);
        $this->assertEquals('MY-AWESOME-PLUGIN', $slug_upper);
        
        // Test with ALLOW_DOT flag
        $slug_with_dots = Dj_App_String_Util::formatSlug('My.Awesome.Plugin', Dj_App_String_Util::ALLOW_DOT);
        $this->assertEquals('my-awesome-plugin', $slug_with_dots);
    }

    /**
     * Test formatSlug method - dots should always be converted to dashes
     */
    public function testFormatSlugConvertsDotsToDashes() {
        // Test basic dot conversion
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('My.Awesome.Plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test.plugin'));
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('my.awesome.plugin'));

        // Test with mixed separators
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('my.awesome_plugin'));
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('my_awesome.plugin'));
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('my.awesome-plugin'));

        // Test with multiple consecutive dots
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('my..awesome...plugin'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('test....plugin'));

        // Test with dots and other special characters
        $this->assertEquals('my-awesome-plugin', Dj_App_String_Util::formatSlug('My.Awesome.Plugin!'));
        $this->assertEquals('test-plugin', Dj_App_String_Util::formatSlug('Test.Plugin@#$'));
    }

    /**
     * Test trim method with extra characters - string parameter
     */
    public function testTrimWithExtraCharsString() {
        // Test trimming brackets with string parameter
        $this->assertEquals('value', Dj_App_String_Util::trim('[value]', '[]'));
        $this->assertEquals('value', Dj_App_String_Util::trim(' [value] ', '[]'));
        $this->assertEquals('value', Dj_App_String_Util::trim('[[value]]', '[]'));

        // Test trimming custom characters
        $this->assertEquals('hello', Dj_App_String_Util::trim('***hello***', '*'));
        $this->assertEquals('test', Dj_App_String_Util::trim('##test##', '#'));
        $this->assertEquals('data', Dj_App_String_Util::trim(' ___data___ ', '_'));

        // Test trimming multiple custom characters
        $this->assertEquals('value', Dj_App_String_Util::trim('<>value<>', '<>'));
        $this->assertEquals('text', Dj_App_String_Util::trim('{}text{}', '{}'));
    }

    /**
     * Test trim method with extra characters - array parameter
     */
    public function testTrimWithExtraCharsArray() {
        // Test trimming with array of characters
        $this->assertEquals('value', Dj_App_String_Util::trim('[value]', ['[', ']']));
        $this->assertEquals('value', Dj_App_String_Util::trim(' <value> ', ['<', '>']));
        $this->assertEquals('test', Dj_App_String_Util::trim('***test***', ['*']));

        // Test with multiple characters in array
        $this->assertEquals('data', Dj_App_String_Util::trim('{[data]}', ['{', '}', '[', ']']));
        $this->assertEquals('hello', Dj_App_String_Util::trim('##__hello__##', ['#', '_']));
    }

    /**
     * Test trim method with extra characters - array of strings
     */
    public function testTrimWithExtraCharsArrayOfStrings() {
        // Test trimming array of strings with extra chars
        $input = ['[value1]', ' [value2] ', '[[value3]]'];
        $expected = ['value1', 'value2', 'value3'];
        $this->assertEquals($expected, Dj_App_String_Util::trim($input, '[]'));

        // Test with different extra chars
        $input2 = ['***item1***', '##item2##', ' ___item3___ '];
        $result = Dj_App_String_Util::trim($input2, '*#_');
        $this->assertEquals(['item1', 'item2', 'item3'], $result);
    }

    /**
     * Test trim method with extra characters - edge cases
     */
    public function testTrimWithExtraCharsEdgeCases() {
        // Test with empty extra chars
        $this->assertEquals('value', Dj_App_String_Util::trim(' value ', ''));
        $this->assertEquals('value', Dj_App_String_Util::trim(' value ', []));

        // Test with empty string
        $this->assertEquals('', Dj_App_String_Util::trim('', '[]'));
        $this->assertEquals('', Dj_App_String_Util::trim('   ', '[]'));

        // Test when no extra chars to trim
        $this->assertEquals('value', Dj_App_String_Util::trim('value', '[]'));
        $this->assertEquals('value', Dj_App_String_Util::trim(' value ', '*'));

        // Test with only extra chars
        $this->assertEquals('', Dj_App_String_Util::trim('[[[', '[]'));
        $this->assertEquals('', Dj_App_String_Util::trim('***', '*'));
    }

    /**
     * Test extractMetaInfo method - parsing array notation
     */
    public function testExtractMetaInfoArrayNotation() {
        // Test basic array notation with brackets
        $meta_text = "tags: [php, web, development]\ncategory: general";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['php', 'web', 'development'], $meta['tags']);
        $this->assertEquals('general', $meta['category']);
    }

    /**
     * Test extractMetaInfo method - array with spaces
     */
    public function testExtractMetaInfoArrayWithSpaces() {
        $meta_text = "tags: [framework, web development, testing]\nauthor: system";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['framework', 'web development', 'testing'], $meta['tags']);
    }

    /**
     * Test extractMetaInfo method - multiple arrays
     */
    public function testExtractMetaInfoMultipleArrays() {
        $meta_text = "tags: [php, javascript]\nrelated_faqs: [faq-001, faq-002, faq-003]\nstatus: active";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['php', 'javascript'], $meta['tags']);
        $this->assertIsArray($meta['related_faqs']);
        $this->assertEquals(['faq-001', 'faq-002', 'faq-003'], $meta['related_faqs']);
        $this->assertEquals('active', $meta['status']);
    }

    /**
     * Test extractMetaInfo method - empty array
     */
    public function testExtractMetaInfoEmptyArray() {
        $meta_text = "tags: []\nstatus: active";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEmpty($meta['tags']);
        $this->assertEquals('active', $meta['status']);
    }

    /**
     * Test extractMetaInfo method - single item array
     */
    public function testExtractMetaInfoSingleItemArray() {
        $meta_text = "tags: [single-tag]\ncategory: test";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['single-tag'], $meta['tags']);
    }

    /**
     * Test extractMetaInfo method - array with extra whitespace
     */
    public function testExtractMetaInfoArrayExtraWhitespace() {
        $meta_text = "tags: [  php  ,   web   ,  development  ]\nstatus: active";
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['php', 'web', 'development'], $meta['tags']);
    }

    /**
     * Test extractMetaInfo method - mixed array and scalar values
     */
    public function testExtractMetaInfoMixedArrayAndScalar() {
        $meta_text = <<<META
title: My Plugin
version: 1.0.0
tags: [plugin, utility, helper]
author: John Doe
keywords: [php, framework]
status: active
META;
        $result = Dj_App_Util::extractMetaInfo($meta_text);

        $this->assertTrue($result->status());
        $meta = $result->data();

        // Scalar values
        $this->assertEquals('My Plugin', $meta['title']);
        $this->assertEquals('1.0.0', $meta['version']);
        $this->assertEquals('John Doe', $meta['author']);
        $this->assertEquals('active', $meta['status']);

        // Array values
        $this->assertIsArray($meta['tags']);
        $this->assertEquals(['plugin', 'utility', 'helper'], $meta['tags']);
        $this->assertIsArray($meta['keywords']);
        $this->assertEquals(['php', 'framework'], $meta['keywords']);
    }

}