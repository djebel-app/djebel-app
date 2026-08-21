<?php

// Load WP and everything
$_SERVER['https'] = 'on';
$_SERVER['PROTOCOL'] = 'https';
$_SERVER['GATEWAY_INTERFACE'] = 'CGI/1.1';
$_SERVER['SERVER_SOFTWARE'] = 'Apache';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
$_SERVER['REQUEST_TIME'] = time();
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PORT'] = 443;

putenv('DJEBEL_APP_CORE_RUN=0');

// Enable Dj_App_Lib loading for the lib test suite.
putenv('DJEBEL_APP_CORE_LOAD_LIBS=1');

// Committed sample/fixture files used by the unit tests.
define('DJEBEL_APP_TEST_DATA_DIR', __DIR__ . '/unit_tests/data');

// A site brings its own .ht_djebel; the framework package does not ship one, and no site
// is loaded here. Left alone the private-dir scan finds nothing and settles beside the
// phpunit binary, writing into tests/vendor/. Pinning it to a disposable temp dir keeps
// every suite — the framework's own and every addon's — out of the repo.
$dj_app_test_private_dir = sys_get_temp_dir() . '/dj_app_tests/.ht_djebel';
putenv('DJEBEL_APP_PRIVATE_DIR=' . $dj_app_test_private_dir);

$dj_app_dir = dirname(__DIR__);
require_once $dj_app_dir . '/index.php';

require_once __DIR__ . '/vendor/autoload.php';
