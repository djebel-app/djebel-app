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
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['PHP_SELF'] = '/success.php';
        
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
        $_SERVER['SCRIPT_NAME'] = '/success.php';
        $_SERVER['PHP_SELF'] = '/success.php';
        
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
            $_SERVER['SCRIPT_NAME'] = $script_name;
            $_SERVER['PHP_SELF'] = $script_name;
            
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
}
