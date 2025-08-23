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

$dj_app_dir = dirname(__DIR__);
require_once $dj_app_dir . '/index.php';

require_once __DIR__ . '/vendor/autoload.php';
