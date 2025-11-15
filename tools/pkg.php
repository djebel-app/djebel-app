#!/usr/bin/env php
<?php
// packages the djebel app into a phar archive that's one file.
// Author: Svetoslav Marinov | https://orbisius.com
// Copyright: All Rights Reserved

// Security: Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cannot run');
}

// Get tool name for usage messages
$tool_name = basename(__FILE__);

// Check command line arguments first
$args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
array_shift($args); // Remove script name from arguments
// Load CLI utilities and normalize arguments
$app_dir = dirname(__DIR__);
require_once $app_dir . '/src/core/lib/cli_util.php';
$args = Dj_Cli_Util::normalizeArgs($args);

$tool = new Djebel_Tool_Opt();

// Help check (exit early before any processing)
foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h' || $arg === '-help' || $arg == 'help') {
        echo "Usage: php $tool_name [--help|-h] [--phar] [--zip]\n";
        echo "Options:\n";
        echo "  --help, -h         Show this help message\n";
        echo "  --phar             Build PHAR archive only\n";
        echo "  --zip              Create source distribution ZIP only (excludes tools/, .git/, tests/)\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php $tool_name                   # Build both PHAR and source ZIP (default)\n";
        echo "  php $tool_name --phar            # Build PHAR only\n";
        echo "  php $tool_name --zip             # Build source ZIP only\n";
        echo "  php $tool_name --phar --zip      # Build both PHAR and source ZIP (explicit)\n";
        exit(0);
    }
}

$exit_code = 0;

try {
    // Load version from Dj_App class
    $app_dir = dirname(__DIR__);
    require_once $app_dir . '/src/core/lib/util.php';
    $version = Dj_App::VERSION;

    // Validate version format (security: ensure it's safe for filenames)
    // Cheap check first: empty
    if (empty($version)) {
        throw new InvalidArgumentException("Version cannot be empty");
    }

    // Expensive check last: regex
    if (!preg_match('/^\d+\.\d+\.\d+(-[\w\.\-]+)?$/si', $version)) {
        throw new InvalidArgumentException("Invalid version format: $version");
    }

    // Allow override via environment variable (with validation)
    $phar_name = getenv('DJEBEL_TOOL_PKG_PHAR_NAME');

    // Validate phar_name if provided (security: prevent path traversal)
    if (!empty($phar_name)) {
        // Cheap checks first: strpos for path separators
        $has_path_separators = (strpos($phar_name, '/') !== false) || (strpos($phar_name, '\\') !== false);

        if ($has_path_separators) {
            throw new InvalidArgumentException("PHAR name cannot contain path separators.");
        }

        // Expensive check last: regex
        if (!preg_match('/^[\w\-\.]+\.phar$/si', $phar_name)) {
            throw new InvalidArgumentException("Invalid PHAR name. Must be a filename ending in .phar");
        }
    }

    // The file's extension must end in .phar
    define('DJEBEL_TOOL_PKG_PHAR_NAME', empty($phar_name) ? "djebel-app-{$version}.phar" : $phar_name);

    // Allow build directory override via environment variable
    $build_dir_env = getenv('DJEBEL_TOOL_PKG_BUILD_DIR');
    $build_dir = empty($build_dir_env) ? "$app_dir/build" : $build_dir_env;

    $create_phar = false;
    $create_zip = false;
    $has_flags = false;

    // Parse command line flags (with validation)
    foreach ($args as $arg) {
        if ($arg === '--phar') {
            $create_phar = true;
            $has_flags = true;
        } elseif ($arg === '--zip') {
            $create_zip = true;
            $has_flags = true;
        } elseif (!in_array($arg, [ '--help', '-h', '-help', 'help', ], true)) {
            // Security: Reject unknown arguments
            throw new InvalidArgumentException("Unknown option: $arg");
        }
    }

    // If no flags provided, build both by default
    if (!$has_flags) {
        $create_zip = true;
        $create_phar = true;
    }

    // Check if phar.readonly is enabled and auto-restart if needed (only if building PHAR)
    if ($create_phar) {
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
    }

    $dir = __DIR__;
    $src_root = "$app_dir/src";
    $phar_file = $build_dir . '/' . DJEBEL_TOOL_PKG_PHAR_NAME;
    $source_zip_file = $build_dir . "/djebel-app-{$version}.zip";
    
    // Ensure build directory exists
    if (!is_dir($build_dir) && !mkdir($build_dir, 0750, true)) {
        throw new RuntimeException("Failed to create build directory: $build_dir");
    }

    // clean up
    $clean_up_files = [];

    if ( $create_phar ) {
        $clean_up_files[] = $phar_file;
    }

    if ( $create_zip ) {
        $clean_up_files[] = $source_zip_file;
    }

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

    if ($create_phar) {
        // Validate source directory exists
        if (!is_dir($src_root)) {
            throw new InvalidArgumentException("Source directory does not exist: $src_root");
        }

        echo "Source directory: [$src_root]\n";
        echo "Building [$phar_file] ...\n";
        echo "Build directory: [$build_dir]\n";

        $phar = new Phar($phar_file,
            FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME,
            basename(DJEBEL_TOOL_PKG_PHAR_NAME)
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

                // Exclude patterns - cheap checks first, ordered by simplicity
                $exclude_patterns = [
                    // Files starting with dot (simplest pattern)
                    '#^\.#' => $basename,
                    // File extensions (common, simple)
                    '#\.(tmp|log|bak|sql|zip|tar|gz|tgz)$#i' => $basename,
                    // Test directories
                    '#/tests?/#i' => $path,
                    // Git/SVN directories
                    '#/\.(git|svn)/#' => $path,
                    // README files (less common, complex pattern)
                    '#^README(\.md|\.txt|\.docx)?$#i' => $basename,
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
        $php_header_rows[] = "define('DJEBEL_TOOL_PKG_PHAR_BUILD_DATE', '$built_date');";

        $git_output = function_exists('shell_exec') ? shell_exec("git rev-list -1 HEAD") : '';
        $git_commit = empty($git_output) ? '' : trim($git_output);

        if (!empty($git_commit)) {
            $php_header_rows[] = "define('DJEBEL_TOOL_PKG_PHAR_BUILD_GIT_COMMIT', '$git_commit');";
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

        // Compress PHAR with gzip (always)
        echo "Compressing PHAR with gzip...\n";
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
    }

    // Create source distribution ZIP (only if --zip flag is provided)
    if ( $create_zip ) {
        if ( !class_exists('ZipArchive') ) {
            throw new RuntimeException("ZipArchive class not available, cannot create source ZIP");
        }

        echo "Creating source distribution ZIP: [$source_zip_file] ...\n";

        $zip = new ZipArchive();
        $zip_result = $zip->open($source_zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ( $zip_result !== true ) {
            throw new RuntimeException("Failed to create source ZIP file: $source_zip_file (Error: $zip_result)");
        }

        // Define exclude patterns - cheap checks first, ordered by simplicity
        $exclude_patterns = [
            '#^\.#',                              // Files starting with dot (simplest)
            '#\.(tmp|log|bak|zip|tar|gz|tgz)$#i', // Temp/archive files (simple)
            '#/build/#',                          // Build output (simple)
            '#/tools/#',                          // Build tools (simple)
            '#/tests?/#i',                        // Test directories (optional s)
            '#/\.(git|svn)/#',                    // Version control (alternation)
        ];

        // Top-level directory name in ZIP
        $zip_root_dir = "djebel-app";

        // Add src/ directory
        $src_iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src_root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($src_iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $file_path = $file->getPathname();
            $relative_path = substr($file_path, strlen($app_dir) + 1);
            $base_name = $file->getBasename();

            $excluded = false;
            foreach ($exclude_patterns as $pattern) {
                // Cheap check first: basename is smaller, faster regex
                if (preg_match($pattern, $base_name) || preg_match($pattern, $relative_path)) {
                    $excluded = true;
                    break;
                }
            }

            if (!$excluded) {
                $zip->addFile($file_path, $zip_root_dir . '/' . $relative_path);
            }
        }

        // Add root index.php
        $root_index = $app_dir . '/index.php';

        if (file_exists($root_index)) {
            $zip->addFile($root_index, $zip_root_dir . '/index.php');
        }

        // Add readme files with site URL
        $site_url = Dj_App::SITE_URL;

        $readme_txt = "For more info go to {$site_url}";
        $zip->addFromString($zip_root_dir . '/000_readme.txt', $readme_txt);

        $readme_html = $tool->generateReadmeHtml($site_url);
        $zip->addFromString($zip_root_dir . '/000_readme.html', $readme_html);

        $zip_comment = "Djebel App v{$version}\nCreated: $built_date";
        $zip_comment .= "\nSite: $site_url";

        if ( !empty($git_commit) ) {
            $zip_comment .= "\nGit commit: $git_commit";
        }

        $zip->setArchiveComment($zip_comment);
        $zip->close();

        if ( !file_exists($source_zip_file) ) {
            throw new RuntimeException("Failed to create source ZIP file: $source_zip_file");
        }

        $zip_size = filesize($source_zip_file);
        $zip_size_fmt = number_format($zip_size, 0);
        echo "Source ZIP created: [$source_zip_file]\n";
        echo "Source ZIP Size: $zip_size_fmt bytes\n";
    }
} catch (Exception $e) {
    // Clean up partially created PHAR file on failure
    if ( $create_phar && !empty($phar) && file_exists($phar_file)) {
        if (!unlink($phar_file)) {
            $tool->stderr("Warning: Could not clean up partial PHAR file");
        }
    }

    // Clean up partially created source ZIP file on failure
    if ( $create_zip && !empty($source_zip_file) && file_exists($source_zip_file) ) {
        if (!unlink($source_zip_file)) {
            $tool->stderr("Warning: Could not clean up partial source ZIP file");
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
    if (!empty(getenv('DJEBEL_TOOL_PKG_VERBOSE'))) {
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

    function generateReadmeHtml($site_url) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Djebel App</title>
</head>
<body>
    <p>For more info go to <a href='<?php echo $site_url; ?>' target='_blank' rel='noopener'><?php echo $site_url; ?></a></p>
</body>
</html>
<?php
        $html = ob_get_clean();
        $html = trim($html);
        return $html;
    }
}