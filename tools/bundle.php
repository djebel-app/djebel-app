#!/usr/bin/env php
<?php
// Creates bundle packages with djebel app, plugins, themes, and configuration
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

$tool = new Djebel_Tool_Bundle();

// Help check (exit early before any processing)
foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h' || $arg === '-help' || $arg == 'help') {
        echo "Usage: php $tool_name --bundle_id=VALUE --bundle_description='VALUE' --bundle_ver=VALUE --dir=VALUE [--target_dir=VALUE] [--help|-h]\n";
        echo "Options:\n";
        echo "  --help, -h                     Show this help message\n";
        echo "  --bundle_id=VALUE              Bundle identifier (required, alphanumeric + hyphens)\n";
        echo "  --bundle_description='VALUE'   Bundle description (required)\n";
        echo "  --bundle_ver=VALUE             Bundle version (required, format: X.Y.Z)\n";
        echo "  --dir=VALUE                    Site directory to bundle (required)\n";
        echo "                                 Can be site name from app/sites/ or full path\n";
        echo "  --target_dir=VALUE             Output directory for bundle (optional)\n";
        echo "                                 Overrides DJEBEL_TOOL_BUNDLE_TARGET_DIR env var\n";
        echo "\n";
        echo "Environment Variables:\n";
        echo "  DJEBEL_TOOL_BUNDLE_TARGET_DIR  Custom output directory (default: build/bundles/)\n";
        echo "  DJEBEL_TOOL_BUNDLE_VERBOSE      Enable verbose error output\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php $tool_name --bundle_id=simple-blog --bundle_description='Complete blog setup' --bundle_ver=1.0.0 --dir=djebel-live\n";
        echo "  php $tool_name --bundle_id=starter --bundle_description='Starter bundle' --bundle_ver=0.1.0 --dir=djebel-live\n";
        echo "  php $tool_name --bundle_id=custom --bundle_description='Custom site' --bundle_ver=1.0.0 --dir=/path/to/site\n";
        echo "  php $tool_name --bundle_id=mybundle --bundle_description='My bundle' --bundle_ver=1.0.0 --dir=djebel-live --target_dir=/tmp/bundles\n";
        exit(0);
    }
}

$exit_code = 0;

try {
    // Load Dj_App class for constants
    $app_dir = dirname(__DIR__);
    require_once $app_dir . '/src/core/lib/util.php';

    // Parse command-line parameters
    $bundle_id = '';
    $bundle_description = '';
    $bundle_ver = '';
    $site_dir_param = '';
    $target_dir_param = '';

    foreach ($args as $arg) {
        // Parse --key=value format
        if (strpos($arg, '--bundle_id=') === 0) {
            $bundle_id = substr($arg, strlen('--bundle_id='));
        } elseif (strpos($arg, '--bundle_description=') === 0) {
            $bundle_description = substr($arg, strlen('--bundle_description='));
        } elseif (strpos($arg, '--bundle_ver=') === 0) {
            $bundle_ver = substr($arg, strlen('--bundle_ver='));
        } elseif (strpos($arg, '--dir=') === 0) {
            $site_dir_param = substr($arg, strlen('--dir='));
        } elseif (strpos($arg, '--target_dir=') === 0) {
            $target_dir_param = substr($arg, strlen('--target_dir='));
        } elseif (!in_array($arg, [ '--help', '-h', '-help', 'help', ], true)) {
            // Security: Reject unknown arguments
            throw new InvalidArgumentException("Unknown option: $arg");
        }
    }

    // Validate required parameters - cheap checks first
    if (empty($bundle_id)) {
        throw new InvalidArgumentException('Missing required parameter: --bundle_id');
    }

    if (empty($bundle_description)) {
        throw new InvalidArgumentException('Missing required parameter: --bundle_description');
    }

    if (empty($bundle_ver)) {
        throw new InvalidArgumentException('Missing required parameter: --bundle_ver');
    }

    if (empty($site_dir_param)) {
        throw new InvalidArgumentException('Missing required parameter: --dir');
    }

    // Validate bundle_id format - alphanumeric and hyphens only
    if (!preg_match('/^[\w\-]+$/si', $bundle_id)) {
        throw new InvalidArgumentException("Invalid bundle_id format. Use alphanumeric characters and hyphens only.");
    }

    // Validate version format (semantic versioning)
    if (!preg_match('/^\d+\.\d+\.\d+(-[\w\.\-]+)?$/si', $bundle_ver)) {
        throw new InvalidArgumentException("Invalid bundle_ver format. Use semantic versioning: X.Y.Z");
    }

    // Get target directory: --target_dir > env var > default
    if (!empty($target_dir_param)) {
        $target_dir = $target_dir_param;
    } else {
        $target_dir_env = getenv('DJEBEL_TOOL_BUNDLE_TARGET_DIR');
        $target_dir = empty($target_dir_env) ? "$app_dir/build/bundles" : $target_dir_env;
    }

    // Ensure target directory exists
    if (!is_dir($target_dir) && !mkdir($target_dir, 0750, true)) {
        throw new RuntimeException("Failed to create target directory: $target_dir");
    }

    // Define bundle filename and paths
    $bundle_filename = sprintf('djebel-bundle-%s-%s.zip', $bundle_id, $bundle_ver);
    $bundle_file = $target_dir . '/' . $bundle_filename;
    $zip_root_dir = sprintf('djebel-bundle-%s', $bundle_id);

    // Clean up old bundle file if exists
    if (file_exists($bundle_file) && !unlink($bundle_file)) {
        throw new RuntimeException("Failed to remove old bundle file: $bundle_file");
    }

    echo "Creating bundle: $bundle_filename\n";
    echo "Bundle ID: $bundle_id\n";
    echo "Version: $bundle_ver\n";
    echo "Description: $bundle_description\n";
    echo "Target: $target_dir\n\n";

    // Determine site directory path
    // If --dir is absolute path, use it directly; otherwise treat as site name
    if (strpos($site_dir_param, '/') === 0) {
        // Absolute path provided
        $site_dir = $site_dir_param;
    } else {
        // Relative site name - validate format
        if (!preg_match('/^[\w\-]+$/si', $site_dir_param)) {
            throw new InvalidArgumentException('Invalid site name format. Use alphanumeric characters and hyphens only.');
        }

        // Source directory (site to bundle)
        // From: /path/to/djebel/github/djebel-app
        // To: /path/to/djebel/app/sites/{site_dir_param}
        $djebel_root = dirname(dirname($app_dir));
        $site_dir = $djebel_root . '/app/sites/' . $site_dir_param;
    }

    // Resolve to real path and validate
    $site_dir = realpath($site_dir);
    if ($site_dir === false || !is_dir($site_dir)) {
        throw new RuntimeException('Site directory not found: ' . $site_dir_param);
    }

    // Create ZIP archive
    $zip = new ZipArchive();
    $zip_result = $zip->open($bundle_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($zip_result !== true) {
        throw new RuntimeException("Failed to create ZIP file: $bundle_file (Error: $zip_result)");
    }

    echo "Scanning plugins...\n";
    $scanner = new Djebel_Bundle_Plugin_Scanner();
    $plugins = $scanner->scanPlugins($site_dir);

    echo "Found " . count($plugins) . " plugins\n\n";

    // Define exclusion patterns - cheap checks first
    $exclude_patterns = [
        '#^\.git$#',                             // .git directory itself
        '#^\.gitignore$#',                       // .gitignore file
        '#^\.gitmodules$#',                      // .gitmodules file
        '#\.(log|tmp|bak|sql)$#i',              // Temp/log files
        '#\.env[\w\-\.]*$#i',                    // Environment files
        '#/\.(git|svn)/#',                       // Version control directories
        '#/\.gitignore$#',                       // .gitignore files
        '#/cache/#',                             // Cache directories
        '#/logs?/#i',                            // Log directories
        '#\.(zip|tar|gz|tgz)$#i',               // Archive files
    ];

    echo "Copying files to bundle...\n";

    // Copy index.php
    $index_file = $site_dir . '/index.php';
    if (file_exists($index_file)) {
        $zip->addFile($index_file, $zip_root_dir . '/index.php');
        echo "  + index.php\n";
    }

    // Copy .ht_djebel directory
    $ht_djebel_dir = $site_dir . '/.ht_djebel';
    if (is_dir($ht_djebel_dir)) {
        echo "  + .ht_djebel/\n";
        $tool->addDirectoryToZip($zip, $ht_djebel_dir, $zip_root_dir . '/.ht_djebel', $exclude_patterns);
    }

    // Copy dj-content directory
    $dj_content_dir = $site_dir . '/dj-content';
    if (is_dir($dj_content_dir)) {
        echo "  + dj-content/\n";
        $tool->addDirectoryToZip($zip, $dj_content_dir, $zip_root_dir . '/dj-content', $exclude_patterns);
    }

    // Generate manifest
    echo "\nGenerating manifest...\n";
    $manifest = $tool->generateManifest($bundle_id, $bundle_description, $bundle_ver, $plugins);
    $zip->addFromString($zip_root_dir . '/.ht_djebel-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

    // Add readme files
    echo "Adding readme files...\n";
    $site_url = Dj_App::SITE_URL;

    $readme_txt = "Djebel Bundle: {$bundle_id}\n\n{$bundle_description}\n\nFor more info go to {$site_url}";
    $zip->addFromString($zip_root_dir . '/000_readme.txt', $readme_txt);

    $readme_html = $tool->generateReadmeHtml($site_url, $bundle_id, $bundle_description);
    $zip->addFromString($zip_root_dir . '/000_readme.html', $readme_html);

    // Add ZIP comment
    $built_date = date('r');
    $zip_comment = "Djebel Bundle: {$bundle_id} v{$bundle_ver}\nCreated: $built_date\nSite: $site_url";
    $zip->setArchiveComment($zip_comment);

    $zip->close();

    if (!file_exists($bundle_file)) {
        throw new RuntimeException("Failed to create bundle file: $bundle_file");
    }

    $size = filesize($bundle_file);
    $size_fmt = number_format($size, 0);

    echo "\nBundle created successfully!\n";
    echo "File: $bundle_file\n";
    echo "Size: $size_fmt bytes\n";

} catch (Exception $e) {
    $tool->stderr("Error: " . $e->getMessage());

    $previous = $e->getPrevious();
    if ($previous !== null) {
        $tool->stderr("Caused by: " . $previous->getMessage());
    }

    // Provide stack trace in verbose mode
    if (!empty(getenv('DJEBEL_TOOL_BUNDLE_VERBOSE'))) {
        $tool->stderr("Stack trace:");
        $tool->stderr($e->getTraceAsString());
    }

    $exit_code = 255;
}

exit($exit_code);

class Djebel_Tool_Bundle {
    function stderr($msg) {
        if (empty($msg)) {
            return false;
        }

        fputs(STDERR, $msg . "\n");
    }

    function generateReadmeHtml($site_url, $bundle_id, $bundle_description) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Djebel Bundle</title>
</head>
<body>
    <h1><?php echo htmlspecialchars($bundle_id); ?></h1>
    <p><?php echo htmlspecialchars($bundle_description); ?></p>
    <p>For more info go to <a href='<?php echo htmlspecialchars($site_url); ?>' target='_blank' rel='noopener'><?php echo htmlspecialchars($site_url); ?></a></p>
</body>
</html>
<?php
        $html = ob_get_clean();
        return $html;
    }

    function generateManifest($bundle_id, $bundle_description, $bundle_ver, $plugins) {
        $manifest = [
            'plugins' => [],
            'themes' => [],
            'meta' => [
                'bundle_id' => $bundle_id,
                'bundle_version' => $bundle_ver,
                'description' => $bundle_description,
                'created' => date('c'),
                'djebel_version' => Dj_App::VERSION,
                'site_url' => Dj_App::SITE_URL,
            ],
        ];

        // Add plugins to manifest
        foreach ($plugins as $plugin) {
            $plugin_entry = [
                'id' => $plugin['id'],
                'version' => empty($plugin['version']) ? '1.0.0' : $plugin['version'],
                'active' => true,
                'location' => $plugin['location'],
            ];

            if (!empty($plugin['name'])) {
                $plugin_entry['name'] = $plugin['name'];
            }

            $manifest['plugins'][] = $plugin_entry;
        }

        return $manifest;
    }

    function addDirectoryToZip($zip, $source_dir, $zip_prefix, $exclude_patterns) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $file_path = $file->getPathname();
            $relative_path = substr($file_path, strlen($source_dir) + 1);
            $base_name = $file->getBasename();

            // Check exclusion patterns
            $excluded = false;
            foreach ($exclude_patterns as $pattern) {
                // Cheap check first: basename is smaller
                if (preg_match($pattern, $base_name) || preg_match($pattern, $relative_path)) {
                    $excluded = true;
                    break;
                }
            }

            if ($excluded) {
                continue;
            }

            $zip_path = $zip_prefix . '/' . $relative_path;

            if ($file->isDir()) {
                $zip->addEmptyDir($zip_path);
            } else {
                $zip->addFile($file_path, $zip_path);
            }
        }
    }
}

class Djebel_Bundle_Plugin_Scanner {
    function scanPlugins($base_dir) {
        $plugins = [];

        // Scan user plugins (dj-content/plugins/)
        $user_plugins_dir = $base_dir . '/dj-content/plugins';
        if (is_dir($user_plugins_dir)) {
            $plugins = array_merge($plugins, $this->scanDirectory($user_plugins_dir, 'user'));
        }

        // Scan system plugins (.ht_djebel/app/plugins/)
        $system_plugins_dir = $base_dir . '/.ht_djebel/app/plugins';
        if (is_dir($system_plugins_dir)) {
            $plugins = array_merge($plugins, $this->scanDirectory($system_plugins_dir, 'system'));
        }

        return $plugins;
    }

    function scanDirectory($dir, $location) {
        $plugins = [];

        if (!is_dir($dir)) {
            return $plugins;
        }

        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $plugin_dir = $dir . '/' . $item;

            if (!is_dir($plugin_dir)) {
                continue;
            }

            $plugin_file = $plugin_dir . '/plugin.php';

            if (!file_exists($plugin_file)) {
                continue;
            }

            $plugin_info = $this->parsePluginHeader($plugin_file);

            if (!empty($plugin_info)) {
                $plugin_info['id'] = $item;
                $plugin_info['location'] = $location;
                $plugins[] = $plugin_info;
            }
        }

        return $plugins;
    }

    function parsePluginHeader($plugin_file) {
        $content = file_get_contents($plugin_file, false, null, 0, 8192);

        if ($content === false) {
            return [];
        }

        // Extract header comment block
        if (!preg_match('#/\*(.+?)\*/#si', $content, $matches)) {
            return [];
        }

        $header = $matches[1];
        $info = [];

        // Parse key: value pairs
        $lines = explode("\n", $header);

        foreach ($lines as $line) {
            $line = trim($line);

            if (strpos($line, ':') === false) {
                continue;
            }

            list($key, $value) = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Convert to standard keys
            if ($key === 'plugin_name') {
                $info['name'] = $value;
            } elseif ($key === 'version') {
                $info['version'] = $value;
            } elseif ($key === 'description') {
                $info['description'] = $value;
            }
        }

        return $info;
    }
}
