#!/usr/bin/env php
<?php
// packages the djebel app into a phar archive that's one file.
// Usage: php opt.php
// Author: Svetoslav Marinov | https://orbisius.com
// Copyright: All Rights Reserved
// Check command line arguments first
$args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];

$tool = new Djebel_Tool_Opt();
$phar_name = getenv('DJEBEL_TOOL_OPT_PHAR_NAME');

// The file's extension must end in .phar
define('DJEBEL_TOOL_OPT_PHAR_NAME', empty($phar_name) ? 'djebel-app.phar' : $phar_name);

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

$exit_code = 0;

try {
    // Check if phar.readonly is enabled and auto-restart if needed
    $can_create_php_phar = ini_get('phar.readonly');
    
    // phar is readonly by default but we need to not be just for a moment so we can create phar
    // to do that we'll restart the script with the proper php params -d phar.readonly=0
    if (!empty($can_create_php_phar) && preg_match('#on|1|true#si', $can_create_php_phar)) {
        // Check if we're already running with the parameter to avoid infinite loops
        $args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
        $has_phar_param_and_still_ro = false; // can this ever happen?

        foreach ($args as $arg) {
            if (strpos($arg, 'phar.readonly=0') !== false) {
                $has_phar_param_and_still_ro = true;
                break;
            }
        }

        if ($has_phar_param_and_still_ro) {
            throw new Exception("Cannot create phar. phar.readonly is enabled and cannot be overridden for some reason.");
        }
    
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
            $tool->stderr("Restarting with -d phar.readonly=0 to be able to create a phar file ...");
            passthru($command, $exit_code);
        } elseif (function_exists('exec')) {
            $tool->stderr("Restarting with -d phar.readonly=0 to be able to create a phar file ...");
            $output = [];
            exec($command, $output, $exit_code);
            echo join('', $output) . "\n"; // output already has new lines
        } else {
            throw new Exception("Cannot restart the app with -d phar.readonly=0 please do it manually or set phar.readonly=0 in php.ini");
        }

        exit($exit_code); // this is the parent no need to continue
    }

    $dir = __DIR__;
    $app_dir = dirname(__DIR__); // one level up
    $src_root = "$app_dir/src";
    $build_dir = "$app_dir/build";
    $phar_file = $build_dir . '/' . DJEBEL_TOOL_OPT_PHAR_NAME;
    
    // Ensure build directory exists
    if (!is_dir($build_dir) && !mkdir($build_dir, 0750, true)) {
        throw new RuntimeException("Failed to create build directory: $build_dir");
    }

    // clean up
    $clean_up_files = [
        $phar_file,
        $phar_file . '.zip',
    ];

    foreach ($clean_up_files as $clean_up_file) {
        if (file_exists($clean_up_file)) {
            if (unlink($clean_up_file)) {
                $tool->stderr("Deleting [$clean_up_file] OK");
            } else {
                $tool->stderr("Warning: Could not delete [$clean_up_file]");
            }
        }
    }

    // Create the PHAR file with proper exception handling
    $phar = null;

    // Validate source directory exists
    if (!is_dir($src_root)) {
        throw new InvalidArgumentException("Source directory does not exist: $src_root");
    }

    echo "Source directory: [$src_root]\n";
    echo "Building [$phar_file] ...\n";
    echo "Build directory: [$build_dir]\n";

    $phar = new Phar($phar_file,
        FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
        basename(DJEBEL_TOOL_OPT_PHAR_NAME)
    );

    // start buffering. Mandatory to modify stub to add shebang
    $phar->startBuffering();

    // pointing main file which requires all classes
    //$phar->setDefaultStub('index.php', 'index.php');

    // creating our library using whole directory with filtering
    class FilteringIterator extends FilterIterator {
        public function accept(): bool {
            $file = $this->getInnerIterator()->current();
            $path = $file->getPathname();
            $basename = $file->getBasename();
            
            // Exclude patterns - keep composer files but exclude others
            $exclude_patterns = [
                // File extensions
                '#\.(tmp|log|bak|sql)$#i' => $basename,
                // Git/SVN directories
                '#/\.(git|svn)/#' => $path,
                // Environment files (only .env* files starting with dot)
                '#^\.env[\w\-\.]*$#i' => $basename,
                // Test directories
                '#/tests?/#i' => $path,
                // README files (with or without extension)
                '#^README(\.md|\.txt|\.docx)?$#i' => $basename,
                // System files
                '#^\.DS_Store$#' => $basename,
            ];
            
            foreach ($exclude_patterns as $pattern => $target) {
                if (preg_match($pattern, $target)) {
                    return false;
                }
            }
            
            return true;
        }
    }

    $iterator = new FilteringIterator(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src_root, FilesystemIterator::SKIP_DOTS)
        )
    );

    // Build from iterator manually to preserve src/ directory structure
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            // Keep the full path relative to app_dir to preserve src/ folder
            $relative_path = substr($file->getPathname(), strlen($app_dir) + 1);
            $phar->addFile($file->getPathname(), $relative_path);
        }
    }
    
    // Add the root index.php file as the main entry point
    $root_index = $app_dir . '/index.php';

    if (file_exists($root_index)) {
        $phar->addFile($root_index, 'index.php');
        echo "Added root index.php to PHAR\n";
    } else {
        throw new RuntimeException("Root index.php not found at: $root_index");
    }

    /*$build_res = $phar->buildFromDirectory($src_root);
    $build_res = true; // buildFromIterator doesn't return a result

    if (!$build_res) {
        throw new RuntimeException("Failed to build PHAR from directory: [$src_root]");
    }*/

    $built_date = date('r');

    // Customize the stub to add the shebang (only for CLI)
    $stub = '';
    // $stub .= "#!/usr/bin/env php\n"; // commented out for web usage

    $php_header_rows = [];
    $php_header_rows[] = "define('DJEBEL_TOOL_OPT_PHAR_BUILD_DATE', '$built_date');";

    $git_output = function_exists('shell_exec') ? shell_exec("git rev-list -1 HEAD") : '';
    $git_commit = empty($git_output) ? '' : trim($git_output);

    if (!empty($git_commit)) {
        $php_header_rows[] = "define('DJEBEL_TOOL_OPT_PHAR_BUILD_GIT_COMMIT', '$git_commit');";
    }

    if (!empty($php_header_rows)) {
        $stub .= "<?php\n";
        $stub .= join("\n", $php_header_rows);
        $stub .= "?>"; // don't add new line so we don't break headers
    }

    // Create the default stub from main.php entry point
    $default_stub = $phar->createDefaultStub('index.php');
    $stub .= $default_stub;
    $phar->setStub($stub); // Add the stub

    $phar->stopBuffering();

    // plus - compressing it into gzip
    $phar->compressFiles(Phar::GZ);

    // was it successful?
    if (!file_exists($phar_file)) {
        throw new RuntimeException("Failed to create as: $phar_file");
    }

    // Validate PHAR file creation and set permissions
    // it's going to be loaded and not executed ... for now, so don't set the permissions.
    /*if (!chmod($phar_file, 0755)) {
        throw new RuntimeException("Failed to set permissions on PHAR file: $phar_file");
    }*/

    $size = filesize($phar_file);
    $size_fmt = number_format($size, 0);
    echo "PHAR created: [$phar_file]\n";
    echo "Size: $size_fmt bytes\n";
} catch (Exception $e) {
    // Clean up partially created PHAR file on failure
    if (!empty($phar) && file_exists($phar_file)) {
        if (!unlink($phar_file)) {
            $tool->stderr("Warning: Could not clean up partial PHAR file");
        }
    }

    // Main exception handler - catches all unhandled exceptions
    $tool->stderr("Build failed: " . $e->getMessage());
    
    // If this is a nested exception, show the original cause
    $previous = $e->getPrevious();

    if ($previous !== null) {
        $tool->stderr("Caused by: " . $previous->getMessage());
    }
    
    // Provide stack trace in verbose mode (can be enabled via environment variable)
    if (!empty(getenv('VERBOSE'))) {
        $tool->stderr("Stack trace:");
        $tool->stderr($e->getTraceAsString());
    }
    
    $exit_code = 255;
} finally {
    if (!empty($phar)) {
        unset($phar);
    }

    exit($exit_code);
}

class Djebel_Tool_Opt {
    function stderr($msg) {
        if (empty($msg)) {
            return false;
        }

        fputs(STDERR, $msg . "\n");
    }
}