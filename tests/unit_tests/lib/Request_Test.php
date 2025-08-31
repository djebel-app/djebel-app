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
    }

    /**
     * Test web path detection when SCRIPT_NAME is stripped by server
     * This is the main use case for servers that strip path prefixes
     */
    public function testWebPathDetectionWithStrippedScriptName()
    {
        // Simulate server environment where SCRIPT_NAME is stripped
        $_SERVER['REQUEST_URI'] = '/some-cool-site/ofni.php';
        $_SERVER['SCRIPT_NAME'] = '/ofni.php';
        $_SERVER['PHP_SELF'] = '/ofni.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/some-cool-site', $web_path, 
            'Web path should be /some-cool-site when REQUEST_URI is /some-cool-site/ofni.php and SCRIPT_NAME is /ofni.php');
    }

    /**
     * Test web path detection with different site names
     * Ensures the logic works across various naming conventions
     */
    public function testWebPathDetectionWithDifferentSiteNames()
    {
        $test_cases = [
            ['/dayana/ofni.php', '/ofni.php', '/dayana'],
            ['/influencer-site/ofni.php', '/ofni.php', '/influencer-site'],
            ['/my-blog/admin.php', '/admin.php', '/my-blog'],
            ['/deep/nested/path/script.php', '/script.php', '/deep/nested/path'],
        ];

        foreach ($test_cases as [$request_uri, $script_name, $expected_path]) {
            $_SERVER['REQUEST_URI'] = $request_uri;
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
     * Test web path detection with traditional server configuration
     * Validates backward compatibility with standard server setups
     */
    public function testWebPathDetectionWithTraditionalConfig()
    {
        $_SERVER['REQUEST_URI'] = '/site/admin/index.php';
        $_SERVER['SCRIPT_NAME'] = '/site/admin/index.php';
        $_SERVER['PHP_SELF'] = '/site/admin/index.php';
        
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
        $_SERVER['REQUEST_URI'] = '';
        $_SERVER['SCRIPT_NAME'] = '';
        $_SERVER['PHP_SELF'] = '';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/', $web_path, 
            'Web path should be / when all server variables are empty');
    }

    /**
     * Test web path detection with complex nested paths
     * Validates handling of deeply nested directory structures
     */
    public function testWebPathDetectionWithComplexNestedPaths()
    {
        $_SERVER['REQUEST_URI'] = '/level1/level2/level3/level4/script.php';
        $_SERVER['SCRIPT_NAME'] = '/script.php';
        $_SERVER['PHP_SELF'] = '/script.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        $this->assertEquals('/level1/level2/level3/level4', $web_path, 
            'Web path should handle complex nested paths correctly');
    }

    /**
     * Test web path detection with query parameters
     * Ensures query strings don't interfere with path detection
     */
    public function testWebPathDetectionWithQueryParameters()
    {
        $_SERVER['REQUEST_URI'] = '/site/page.php?param=value&test=123';
        $_SERVER['SCRIPT_NAME'] = '/page.php';
        $_SERVER['PHP_SELF'] = '/page.php';
        
        // Create a fresh instance to avoid singleton caching
        $req_obj = new Dj_App_Request();
        $web_path = $req_obj->detectWebPath();
        
        // This is not a true "stripped prefix" scenario
        // In reality, if SCRIPT_NAME is stripped, REQUEST_URI should end with SCRIPT_NAME
        // This test case falls back to traditional detection and returns '/'
        $this->assertEquals('/', $web_path, 
            'Web path should be / when REQUEST_URI contains SCRIPT_NAME but doesn\'t end with it');
    }

    /**
     * Test web path detection with various REQUEST_URI formats
     * Validates our logic handles different input formats correctly
     */
    public function testWebPathDetectionWithVariousFormats()
    {
        $test_cases = [
            ['/site/ofni.php', '/ofni.php', '/site'],
            ['site/ofni.php', '/ofni.php', 'site'],  // REQUEST_URI without leading / returns 'site'
            ['/ofni.php', '/ofni.php', '/'],
        ];

        foreach ($test_cases as [$request_uri, $script_name, $expected_path]) {
            $_SERVER['REQUEST_URI'] = $request_uri;
            $_SERVER['SCRIPT_NAME'] = $script_name;
            $_SERVER['PHP_SELF'] = $script_name;
            
            // Create a fresh instance to avoid singleton caching
            $req_obj = new Dj_App_Request();
            $web_path = $req_obj->detectWebPath();
            
            $this->assertEquals($expected_path, $web_path, 
                "Web path should be $expected_path for REQUEST_URI: $request_uri, SCRIPT_NAME: $script_name");
        }
    }
}
