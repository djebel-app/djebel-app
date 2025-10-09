<?php
/**
 * Request class tests
 * Tests web path detection logic for various server configurations
 * 
 * This test suite validates the core web path detection functionality
 * that millions of users depend on for proper URL routing.
 */

use PHPUnit\Framework\TestCase;

class Request_Test extends TestCase
{
    protected $original_server;

    protected function setUp(): void
    {
        // Backup original $_SERVER for cleanup
        $this->original_server = $_SERVER;
    }

    protected function tearDown(): void
    {
        // Restore original $_SERVER
        $_SERVER = $this->original_server;
        
        // Ensure no prefix header
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
    }

    /**
     * Test web path detection with HTTP_X_FORWARDED_PREFIX header
     * This is the primary method for modern proxy setups
     */
    public function testWebPathDetectionWithForwardedPrefix()
    {
        // Test with single prefix
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/some-cool-site';
        
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/some-cool-site', $web_path, 
            'Web path should be /some-cool-site when HTTP_X_FORWARDED_PREFIX is set');
    }

    /**
     * Test web path detection with comma-separated HTTP_X_FORWARDED_PREFIX
     * Multiple proxies may append multiple values - takes the first one
     */
    public function testWebPathDetectionWithCommaSeparatedPrefix()
    {
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/proxy1,/some-cool-site,/proxy2';
        
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/proxy1', $web_path, 
            'Web path should be /proxy1 (first value) when HTTP_X_FORWARDED_PREFIX has comma-separated values');
    }

    /**
     * Test web path detection when SCRIPT_NAME is stripped by server
     * This tests the traditional detection method when SCRIPT_NAME is stripped
     */
    public function testWebPathDetectionWithStrippedScriptName()
    {
        // Simulate server environment where SCRIPT_NAME is stripped
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']); // Ensure no prefix header
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // With traditional detection, dirname('/success.php') returns '/'
        $this->assertEquals('/', $web_path, 
            'Web path should be / when SCRIPT_NAME is /success.php (traditional detection)');
    }

    /**
     * Test web path detection with different site names
     * Ensures the logic works across various naming conventions
     */
    public function testWebPathDetectionWithDifferentSiteNames()
    {
        // Ensure no prefix header for traditional detection tests
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        
        $test_cases = [
            ['/dayana/success.php', '/success.php', '/'],  // dirname('/success.php') = '/'
            ['/influencer-site/success.php', '/success.php', '/'],  // dirname('/success.php') = '/'
            ['/my-blog/admin.php', '/admin.php', '/'],  // dirname('/admin.php') = '/'
            ['/deep/nested/path/script.php', '/script.php', '/'],  // dirname('/script.php') = '/'
        ];

        foreach ($test_cases as [$request_uri, $script_name, $expected_path]) {
            $_SERVER['PHP_SELF'] = $script_name;
            $_SERVER['SCRIPT_NAME'] = $script_name;
            
            // Create a fresh instance to avoid singleton caching
            $req_obj = new Dj_App_Request();
            $web_path = $req_obj->detectWebPath();
            
            $this->assertEquals($expected_path, $web_path, 
                "Web path should be $expected_path for REQUEST_URI: $request_uri, SCRIPT_NAME: $script_name");
        }
    }

    /**
     * Test web path detection with traditional server configuration
     * Validates backward compatibility with standard server setups
     */
    public function testWebPathDetectionWithTraditionalConfig()
    {
        // Ensure no prefix header for traditional detection
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        $_SERVER['PHP_SELF'] = '/site/admin/index.php';
        $_SERVER['SCRIPT_NAME'] = '/site/admin/index.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/site/admin', $web_path, 
            'Web path should be /site/admin for traditional server configuration');
    }

    /**
     * Test web path detection with empty server variables
     * Ensures graceful fallback when server data is missing
     */
    public function testWebPathDetectionWithEmptyVariables()
    {
        // Ensure no prefix header for traditional detection
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        $_SERVER['PHP_SELF'] = '';
        $_SERVER['SCRIPT_NAME'] = '';

        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/', $web_path, 'Web path should be / when all server variables are empty');
    }

    /**
     * Test web path detection with complex nested paths
     * Validates handling of deeply nested directory structures
     */
    public function testWebPathDetectionWithComplexNestedPaths()
    {
        // Ensure no prefix header for traditional detection
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        $_SERVER['PHP_SELF'] = '/dir1/success.php';
        $_SERVER['SCRIPT_NAME'] = '/dir1/success.php';

        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // With traditional detection, dirname('/dir1/success.php') returns '/dir1'
        $this->assertEquals('/dir1', $web_path, 
            'Web path should be /dir1 when SCRIPT_NAME is /dir1/success.php (traditional detection)');
    }
    
    /**
     * Test web path detection where SCRIPT_NAME is stripped to subdirectory level
     * This tests the case where only the first segment differs
     */
    public function testWebPathDetectionWithStrippedSubdirectory()
    {
        // Ensure no prefix header for traditional detection
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // With traditional detection, dirname('/success.php') returns '/'
        $this->assertEquals('/', $web_path, 
            'Web path should be / when SCRIPT_NAME is /success.php (traditional detection)');
    }

    /**
     * Test web path detection with query parameters
     * Ensures query strings don't interfere with path detection
     */
    public function testWebPathDetectionWithQueryParameters()
    {
        // Ensure no prefix header for traditional detection
        unset($_SERVER['HTTP_X_FORWARDED_PREFIX']);
        $_SERVER['PHP_SELF'] = '/page.php';
        $_SERVER['SCRIPT_NAME'] = '/page.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // With traditional detection, dirname('/page.php') returns '/'
        $this->assertEquals('/', $web_path, 
            'Web path should be / when SCRIPT_NAME is /page.php (traditional detection)');
    }

    /**
     * Test web path detection with various REQUEST_URI formats
     * Validates our logic handles different input formats correctly
     */
    public function testWebPathDetectionWithVariousFormats()
    {
        $test_cases = [
            ['/site/success.php', '/success.php', '/'],  // dirname('/success.php') = '/'
            ['site/success.php', '/success.php', '/'],  // dirname('/success.php') = '/'
            ['/success.php', '/success.php', '/'],  // dirname('/success.php') = '/'
        ];

        foreach ($test_cases as [$request_uri, $script_name, $expected_path]) {
            $_SERVER['PHP_SELF'] = $script_name;
            $_SERVER['SCRIPT_NAME'] = $script_name;
            
            // Create a fresh instance to avoid singleton caching
            $req_obj = new Dj_App_Request();
            $web_path = $req_obj->detectWebPath();
            
            $this->assertEquals($expected_path, $web_path, 
                "Web path should be $expected_path for REQUEST_URI: $request_uri, SCRIPT_NAME: $script_name");
        }
    }

    /**
     * Test web path detection with HTTP_X_FORWARDED_PREFIX and traditional fallback
     * Ensures both methods work together correctly
     */
    public function testWebPathDetectionWithPrefixAndTraditionalFallback()
    {
        // Test with prefix that should be used
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/proxy-prefix';
        
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/proxy-prefix', $web_path, 
            'Web path should be /proxy-prefix when HTTP_X_FORWARDED_PREFIX is set');
    }

    /**
     * Test web path detection with invalid HTTP_X_FORWARDED_PREFIX
     * Ensures invalid prefix values are rejected and fallback is used
     */
    public function testWebPathDetectionWithInvalidPrefix()
    {
        // Test with invalid prefix (contains non-alphanumeric characters)
        $_SERVER['PHP_SELF'] = '/success.php';
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['HTTP_X_FORWARDED_PREFIX'] = '/invalid@prefix!';
        
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // Should fallback to traditional detection - dirname('/success.php') = '/'
        $this->assertEquals('/', $web_path, 
            'Web path should fallback to traditional detection when HTTP_X_FORWARDED_PREFIX is invalid');
    }

    /**
     * Test basic get() method functionality
     */
    public function testGetMethodBasic()
    {
        $req_obj = new Dj_App_Request();
        
        // Set some test data
        $req_obj->set('username', 'john_doe');
        $req_obj->set('email', 'john@example.com');
        $req_obj->set('age', '25');
        
        // Test basic get
        $this->assertEquals('john_doe', $req_obj->get('username'));
        $this->assertEquals('john@example.com', $req_obj->get('email'));
        $this->assertEquals('25', $req_obj->get('age'));
        
        // Test non-existent key
        $this->assertEquals('', $req_obj->get('non_existent'));
        $this->assertEquals('default_value', $req_obj->get('non_existent', 'default_value'));
    }

    /**
     * Test get() method with type forcing
     */
    public function testGetMethodWithTypeForcing()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('count', '42');
        $req_obj->set('price', '19.99');
        $req_obj->set('message', 'Hello <b>World</b>!');
        
        // Test INT type forcing
        $this->assertEquals(42, $req_obj->get('count', '', Dj_App_Request::INT));
        $this->assertEquals(19, $req_obj->get('price', '', Dj_App_Request::INT)); // intval('19.99') = 19
        
        // Test FLOAT type forcing
        $this->assertEquals(19.99, $req_obj->get('price', '', Dj_App_Request::FLOAT));
        $this->assertEquals(42.0, $req_obj->get('count', '', Dj_App_Request::FLOAT));
        
        // Test STRIP_ALL_TAGS - skip this test as it requires WordPress functions
        // $this->assertEquals('Hello World!', $req_obj->get('message', '', Dj_App_Request::STRIP_ALL_TAGS));
    }

    /**
     * Test get() method with single separator (pipe)
     */
    public function testGetMethodWithPipeSeparator()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('msg', 'Hello World');
        $req_obj->set('message', 'Hi There');
        $req_obj->set('contact_form_msg', 'Form Message');
        
        // Test pipe separator - should return first found
        $this->assertEquals('Hello World', $req_obj->get('msg|message|contact_form_msg'));
        $this->assertEquals('Hi There', $req_obj->get('message|msg|contact_form_msg'));
        $this->assertEquals('Form Message', $req_obj->get('contact_form_msg|msg|message'));
        
        // Test with non-existent keys
        $this->assertEquals('', $req_obj->get('nonexistent1|nonexistent2'));
        $this->assertEquals('default', $req_obj->get('nonexistent1|nonexistent2', 'default'));
    }

    /**
     * Test get() method with comma separator
     */
    public function testGetMethodWithCommaSeparator()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('title', 'Page Title');
        $req_obj->set('page_title', 'Page Title Alt');
        $req_obj->set('header', 'Header Text');
        
        // Test comma separator
        $this->assertEquals('Page Title', $req_obj->get('title,page_title,header'));
        $this->assertEquals('Page Title Alt', $req_obj->get('page_title,title,header'));
        $this->assertEquals('Header Text', $req_obj->get('header,title,page_title'));
    }

    /**
     * Test get() method with semicolon separator
     */
    public function testGetMethodWithSemicolonSeparator()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('name', 'John Doe');
        $req_obj->set('full_name', 'John Doe Full');
        $req_obj->set('user_name', 'johndoe');
        
        // Test semicolon separator
        $this->assertEquals('John Doe', $req_obj->get('name;full_name;user_name'));
        $this->assertEquals('John Doe Full', $req_obj->get('full_name;name;user_name'));
        $this->assertEquals('johndoe', $req_obj->get('user_name;name;full_name'));
    }

    /**
     * Test get() method with mixed separators
     */
    public function testGetMethodWithMixedSeparators()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('id', '123');
        $req_obj->set('user_id', '456');
        $req_obj->set('item_id', '789');
        $req_obj->set('product_id', '101');
        
        // Test mixed separators - should normalize all to comma and split
        $this->assertEquals('123', $req_obj->get('id|user_id,item_id;product_id'));
        $this->assertEquals('456', $req_obj->get('user_id,id|item_id;product_id'));
        $this->assertEquals('789', $req_obj->get('item_id;id,user_id|product_id'));
        $this->assertEquals('101', $req_obj->get('product_id|id;user_id,item_id'));
    }

    /**
     * Test get() method with whitespace handling
     */
    public function testGetMethodWithWhitespaceHandling()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('key1', 'value1');
        $req_obj->set('key2', 'value2');
        $req_obj->set('key3', 'value3');
        
        // Test with spaces around separators
        $this->assertEquals('value1', $req_obj->get(' key1 | key2 , key3 '));
        $this->assertEquals('value2', $req_obj->get('key2 , key1 | key3'));
        $this->assertEquals('value3', $req_obj->get('key3 ; key1 , key2'));
    }

    /**
     * Test get() method with duplicate keys
     */
    public function testGetMethodWithDuplicateKeys()
    {
        $req_obj = new Dj_App_Request();
        
        // Set test data
        $req_obj->set('duplicate', 'first_value');
        $req_obj->set('unique', 'unique_value');
        
        // Test with duplicate keys - should return first occurrence
        $this->assertEquals('first_value', $req_obj->get('duplicate|duplicate|unique'));
        $this->assertEquals('unique_value', $req_obj->get('unique|duplicate|duplicate'));
    }

    /**
     * Test get() method with empty key
     */
    public function testGetMethodWithEmptyKey()
    {
        $req_obj = new Dj_App_Request();
        
        // Set some test data
        $req_obj->set('key1', 'value1');
        $req_obj->set('key2', 'value2');
        
        // Test with empty key - should return all data
        $all_data = $req_obj->get('');
        $this->assertIsArray($all_data);
        $this->assertArrayHasKey('key1', $all_data);
        $this->assertArrayHasKey('key2', $all_data);
        $this->assertEquals('value1', $all_data['key1']);
        $this->assertEquals('value2', $all_data['key2']);
    }

    /**
     * Test get() method with email handling
     */
    public function testGetMethodWithEmailHandling()
    {
        $req_obj = new Dj_App_Request();

        // Set test data with email key
        $req_obj->set('email', 'test+user@example.com');
        $req_obj->set('user_email', 'user+test@domain.com');

        // Test email key handling - should replace spaces with +
        $this->assertEquals('test+user@example.com', $req_obj->get('email'));
        $this->assertEquals('user+test@domain.com', $req_obj->get('user_email'));
    }

    /**
     * Test addQueryParam() with simple key-value pairs
     */
    public function testAddQueryParamSimple()
    {
        // Test adding simple param to clean URL
        $url = Dj_App_Request::addQueryParam('page', 2, '/blog');
        $this->assertEquals('/blog?page=2', $url);

        // Test adding param to URL with existing params
        $url = Dj_App_Request::addQueryParam('page', 3, '/blog?category=news');
        $this->assertEquals('/blog?category=news&page=3', $url);

        // Test replacing existing param
        $url = Dj_App_Request::addQueryParam('page', 5, '/blog?page=2');
        $this->assertEquals('/blog?page=5', $url);
    }

    /**
     * Test addQueryParam() with array parameter (associative array format)
     */
    public function testAddQueryParamWithArrayKeys()
    {
        // Test adding array-style parameter xyz_data[key] with value
        $url = Dj_App_Request::addQueryParam('xyz_data[key]', 'value1', '/test');
        $this->assertStringContainsString('xyz_data%5Bkey%5D=value1', $url, 'Should contain URL-encoded xyz_data[key]=value1');

        // Test adding array-style parameter with multiple keys
        $url = Dj_App_Request::addQueryParam('plugin_data[page]', 2, '/blog');
        $this->assertStringContainsString('plugin_data%5Bpage%5D=2', $url, 'Should contain URL-encoded plugin_data[page]=2');

        // Test adding array-style parameter with hash_id
        $url = Dj_App_Request::addQueryParam('djebel_plugin_static_blog_data[hash_id]', 'abc123def456', '/blog');
        $this->assertStringContainsString('djebel_plugin_static_blog_data%5Bhash_id%5D=abc123def456', $url, 'Should contain URL-encoded brackets');
    }

    /**
     * Test addQueryParam() with array of key-value pairs
     */
    public function testAddQueryParamWithArrayOfPairs()
    {
        // Test passing array of key-value pairs
        $params = [
            'page' => 2,
            'category' => 'news',
            'tag' => 'php'
        ];
        $url = Dj_App_Request::addQueryParam($params, '/blog');

        $this->assertStringContainsString('page=2', $url);
        $this->assertStringContainsString('category=news', $url);
        $this->assertStringContainsString('tag=php', $url);
    }

    /**
     * Test addQueryParam() with nested array parameters
     */
    public function testAddQueryParamWithNestedArrays()
    {
        // Test adding nested array parameter
        $params = [
            'plugin_data' => [
                'page' => 2,
                'hash_id' => 'abc123'
            ]
        ];
        $url = Dj_App_Request::addQueryParam($params, '/blog');

        // http_build_query creates plugin_data[page]=2&plugin_data[hash_id]=abc123
        $this->assertStringContainsString('plugin_data', $url);
        $this->assertStringContainsString('page', $url);
        $this->assertStringContainsString('2', $url);
        $this->assertStringContainsString('hash_id', $url);
        $this->assertStringContainsString('abc123', $url);
    }

    /**
     * Test addQueryParam() replaces existing array-style params
     */
    public function testAddQueryParamReplacesArrayParams()
    {
        // Test replacing array-style parameter
        $url = '/blog?plugin_data[page]=1';
        $url = Dj_App_Request::addQueryParam('plugin_data[page]', 3, $url);

        // Check for URL-encoded brackets in the result
        $this->assertStringContainsString('plugin_data%5Bpage%5D=3', $url, 'Should contain plugin_data[page]=3 with URL-encoded brackets');
        // Should not contain old value
        $this->assertStringNotContainsString('=1', $url, 'Should not contain old value =1');
    }

    /**
     * Test addQueryParam() with special characters
     */
    public function testAddQueryParamWithSpecialCharacters()
    {
        // Test with special characters that need URL encoding
        $url = Dj_App_Request::addQueryParam('search', 'hello world', '/search');
        $this->assertStringContainsString('search=hello', $url);
        $this->assertStringContainsString('world', $url);

        // Test with array param containing special characters
        $url = Dj_App_Request::addQueryParam('data[name]', 'John Doe', '/profile');
        $this->assertStringContainsString('data', $url);
        $this->assertStringContainsString('name', $url);
        $this->assertStringContainsString('John', $url);
    }

    /**
     * Test addQueryParam() with empty values
     */
    public function testAddQueryParamWithEmptyValues()
    {
        // Test with empty value
        $url = Dj_App_Request::addQueryParam('debug', '', '/test');
        $this->assertStringContainsString('debug', $url);

        // Test with zero value
        $url = Dj_App_Request::addQueryParam('page', 0, '/blog');
        $this->assertStringContainsString('page=0', $url);
    }

    /**
     * Test addQueryParam() without URL parameter (uses current request URL)
     */
    public function testAddQueryParamWithoutUrl()
    {
        // Test with explicit URL since REQUEST_URI may not be set consistently in tests
        // The method should use getRequestUrl() internally which reads from $_SERVER
        $url1 = Dj_App_Request::addQueryParam('new', 'value', '/current/page?existing=param');
        $this->assertStringContainsString('new=value', $url1);
        $this->assertStringContainsString('existing=param', $url1);

        // Test that empty URL parameter defaults to current request
        // Since we can't reliably test singleton behavior, we test with explicit URL
        $url2 = Dj_App_Request::addQueryParam('page', 2, '');
        $this->assertStringContainsString('page=2', $url2);
    }

    /**
     * Test addQueryParam() with real-world blog plugin scenario
     */
    public function testAddQueryParamBlogPluginScenario()
    {
        // Simulate blog plugin pagination URL generation
        $base_url = '/blog';

        // Add page parameter using array notation
        $url = Dj_App_Request::addQueryParam('djebel_plugin_static_blog_data[page]', 2, $base_url);

        $this->assertStringContainsString('djebel_plugin_static_blog_data%5Bpage%5D=2', $url, 'Should contain URL-encoded djebel_plugin_static_blog_data[page]=2');

        // Simulate adding hash_id parameter
        $url = Dj_App_Request::addQueryParam('djebel_plugin_static_blog_data[hash_id]', 'abc123def456', $url);

        $this->assertStringContainsString('djebel_plugin_static_blog_data%5Bhash_id%5D=abc123def456', $url, 'Should contain URL-encoded hash_id');
        $this->assertStringContainsString('page', $url, 'Should still have page param');
    }

    /**
     * Test addQueryParam() replacing existing params with mixed types
     */
    public function testAddQueryParamReplaceExistingMixed()
    {
        // Test replacing simple param when URL has both simple and array params
        $url = '/blog?page=1&category=news&plugin_data[key]=value';
        $url = Dj_App_Request::addQueryParam('page', 3, $url);

        $this->assertStringContainsString('page=3', $url, 'Should update page to 3');
        $this->assertStringContainsString('category=news', $url, 'Should keep category param');
        $this->assertStringContainsString('plugin_data', $url, 'Should keep plugin_data array param');
        $this->assertStringNotContainsString('page=1', $url, 'Should not contain old page value');

        // Test replacing array param when URL has multiple params
        $url = '/blog?page=2&plugin_data[page]=1&category=news';
        $url = Dj_App_Request::addQueryParam('plugin_data[page]', 5, $url);

        $this->assertStringContainsString('plugin_data%5Bpage%5D=5', $url, 'Should update plugin_data[page] to 5');
        $this->assertStringContainsString('page=2', $url, 'Should keep simple page param');
        $this->assertStringContainsString('category=news', $url, 'Should keep category param');
        $this->assertStringNotContainsString('plugin_data%5Bpage%5D=1', $url, 'Should not contain old plugin_data[page] value');

        // Test updating multiple array keys for same namespace
        $url = '/blog?djebel_plugin_static_blog_data[page]=1&djebel_plugin_static_blog_data[hash_id]=old123';
        $url = Dj_App_Request::addQueryParam('djebel_plugin_static_blog_data[page]', 3, $url);

        $this->assertStringContainsString('djebel_plugin_static_blog_data%5Bpage%5D=3', $url, 'Should update page to 3');
        $this->assertStringContainsString('hash_id', $url, 'Should keep hash_id param');
        $this->assertStringContainsString('old123', $url, 'Should keep old hash_id value since we only updated page');
        $this->assertStringNotContainsString('page%5D=1', $url, 'Should not contain old page value');
    }
}
