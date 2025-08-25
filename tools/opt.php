#!/usr/bin/env php
<?php
//
// Usage: php opt.php
// Author: Svetoslav Marinov | https://orbisius.com
// Copyright: All Rights Reserved
// Check command line arguments first
$args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h' || $arg === '-help' || $arg == 'help') {
        echo "Usage: php opt.php [--help|-h]\n";
        echo "Options:\n";
        echo "  --help, -h         Show this help message\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php opt.php              # Build PHAR\n";
        exit(0);
    }
}

// Check if exec() is available
if (!function_exists('exec')) {
    echo "exec() function is not available. This script requires exec() to be enabled.\n";
    exit(1);
}

// Check if phar.readonly is enabled and auto-restart if needed
$can_create_php_phar = ini_get('phar.readonly');

if (!empty($can_create_php_phar) && preg_match('#on|1#si', $can_create_php_phar)) {
    // Check if we're already running with the parameter to avoid infinite loops
    $args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
    $has_phar_param = false;
    
    foreach ($args as $arg) {
        if (strpos($arg, 'phar.readonly=0') !== false) {
            $has_phar_param = true;
            break;
        }
    }
    
    if (!$has_phar_param) {
        echo "phar.readonly is enabled. Restarting with -d phar.readonly=0...\n";
        
        $script_path = __FILE__;
        $script_path_esc = escapeshellarg($script_path);
        $command = sprintf('php -d phar.readonly=0 %s', $script_path_esc);
        
        // Pass through any additional arguments
        if (count($args) > 1) {
            $args_copy = $args;
            array_shift($args_copy); // Remove script name
            $escaped_args = array_map('escapeshellarg', $args_copy);
            $command .= ' ' . join(' ', $escaped_args);
        }
        
        if (function_exists('passthru')) {
            passthru($command, $exit_code);
        } else {
            // Fallback to exec() if passthru() is not available
            $output = [];
            $exit_code = 0;
            exec($command, $output, $exit_code);
            echo join('', $output) . "\n"; // output already has new lines
        }
        exit($exit_code);
    } else {
        echo "Cannot create phar. phar.readonly is enabled and cannot be overridden.\n";
        exit(1);
    }
}

// Usage:
// php --define phar.readonly=0 create_phar.php

// see https://medium.com/@tfidry/create-and-deploy-secure-phars-c5572f10b4dd
// phar to load external config???

// https://gist.githubusercontent.com/odan/8051a1cf01b922df8c6e0f9100703bfa/raw/c14ce52be8dc27cc283270b1b728de0853455da4/create-phar.php
// https://gist.github.com/odan/8051a1cf01b922df8c6e0f9100703bfa
// https://www.sitepoint.com/packaging-your-apps-with-phar/
// https://cweiske.de/tagebuch/phar-renaming-no-ext.htm
// To make this executable we need to insert a shebang
// https://blog.programster.org/creating-phar-files

// The file's extension must end in .phar
define('DJEBEL_TOOL_OPT_PHAR_NAME', 'djebel-app.phar');

// next: // http://php.net/manual/en/phar.mount.php
$dir = __DIR__;
$app_dir = dirname(__DIR__);
$src_root = "$app_dir/src";
$build_root = "$app_dir/build";
$phar_file = $build_root . '/' . DJEBEL_TOOL_OPT_PHAR_NAME;

// clean up
$clean_up_files = [
    $phar_file,
    $phar_file . '.zip',
];

foreach ($clean_up_files as $clean_up_file) {
    if (file_exists($clean_up_file)) {
        $res = unlink($clean_up_file);
        opt_stderr("Deleting [$phar_file] " . (empty($res)) ? 'Failed' : 'OK' );
    }
}

echo "Building [$phar_file] ...\n";
echo "Source directory: $src_root\n";
echo "Build directory: $build_root\n";

$phar = new Phar($phar_file, FilesystemIterator::CURRENT_AS_FILEINFO | 	FilesystemIterator::KEY_AS_FILENAME, basename(DJEBEL_TOOL_OPT_PHAR_NAME));

// start buffering. Mandatory to modify stub to add shebang
$phar->startBuffering();

// Create the default stub from main.php entry point
$default_stub = $phar->createDefaultStub('index.php');

// pointing main file which requires all classes
//$phar->setDefaultStub('index.php', 'index.php');

// creating our library using whole directory
$build_res = $phar->buildFromDirectory($src_root);

$built_date = date('r');
$git_commit = shell_exec("git rev-list -1 HEAD");
$git_commit = empty($git_commit) ? '' : $git_commit;

// Customize the stub to add the shebang
$stub = '';
$stub .= "#!/usr/bin/env php\n";
$stub .= "<?php define('DJEBEL_TOOL_OPT_PHAR_BUILD_DATE', '$built_date');?>";

if (!empty($git_commit)) {
    $stub .= "<?php define('DJEBEL_TOOL_OPT_PHAR_BUILD_GIT_COMMIT', '$git_commit');?>";
}

$stub .= $default_stub;
$phar->setStub($stub); // Add the stub

$phar->stopBuffering();

// plus - compressing it into gzip
$phar->compressFiles(Phar::GZ);

//$phar->buildFromIterator(
//	new RecursiveIteratorIterator(
//		new RecursiveDirectoryIterator($src_root, FilesystemIterator::SKIP_DOTS)
//	),
//	$src_root
//);

//$phar = $phar->convertToExecutable(Phar::PHAR, Phar::GZ);

$target_conf_file = $build_root . "/conf/config.ini";

if (file_exists($src_root . "/conf/config.ini")) {
	if ( ! is_dir( dirname( $build_root . "/conf/config.ini" ) ) ) {
		mkdir( dirname( $build_root . "/conf/config.ini" ), 0750, 1 );
	}

	$copy_res = copy( $src_root . "/conf/config.ini", $target_conf_file );
}

if (file_exists($phar_file)) {
	chmod($phar_file, 0755);
    $size = filesize($phar_file);
    $size_fmt = number_format($size, 0);
    echo "size: " . $size_fmt . " bytes\n";
}


$target_dist_dir = getenv('HOME') . sprintf('/Dropbox/Business/%d/software', date('Y'));

if (!is_dir($target_dist_dir)) {
    mkdir($target_dist_dir, 0700, true);
}

if (!is_dir($target_dist_dir)) {
    echo "Error: Could not create target distribution directory [$target_dist_dir].\n";
    exit(1);
}

$target_file = $target_dist_dir . '/' . DJEBEL_TOOL_OPT_PHAR_NAME;

if (file_exists($target_file)) {
    unlink($target_file); // Remove existing file if it exists
}

if (copy($phar_file, $target_file)) {
    echo "Copied to [$target_file]\n";
    chmod($target_file, 0755);
} else {
    echo "Error: Could not copy to target file [$target_file].\n";
    exit(1);
}

function opt_stderr($msg) {
    fputs(STDERR, $msg . "\n");
}