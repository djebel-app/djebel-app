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

// Load CLI utilities
$app_dir = dirname(__DIR__);
require_once $app_dir . '/src/core/lib/cli_util.php';

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
    // Load Djebel app to use existing utilities
    $app_dir = dirname(__DIR__);
    putenv('DJEBEL_APP_CORE_RUN=0'); // Don't execute, just load classes
    require_once $app_dir . '/index.php';

    // Parse command-line parameters with defaults
    $expected_params = [
        'dir' => '',
        'bundle_id' => '',
        'bundle_description' => '',
        'bundle_ver' => '1.0.0',
        'bundle_url' => '',
        'target_dir' => '',
    ];

    $params = Dj_Cli_Util::parseArgs($expected_params, $args);

    // Extract to local variables
    $bundle_id = $params['bundle_id'];
    $bundle_description = $params['bundle_description'];
    $bundle_ver = $params['bundle_ver'];
    $bundle_url = $params['bundle_url'];
    $dir_input = $params['dir'];
    $target_dir_param = $params['target_dir'];

    // Validate required parameters - cheap checks first
    if (empty($bundle_id)) {
        throw new InvalidArgumentException('Missing required parameter: --bundle_id');
    }

    if (empty($dir_input)) {
        throw new InvalidArgumentException('Missing required parameter: --dir');
    }

    // Validate and format bundle_id - alphanumeric and hyphens only, lowercase
    if (!Dj_App_String_Util::isAlphaNumericExt($bundle_id)) {
        throw new InvalidArgumentException("Invalid bundle_id format. Use alphanumeric characters and hyphens only.");
    }

    $bundle_id = Dj_App_String_Util::formatStringId($bundle_id, Dj_App_String_Util::KEEP_DASH);

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
    $bundle_dirname = sprintf('djebel-bundle-%s', $bundle_id);
    $bundle_dir = $target_dir . '/' . $bundle_dirname;
    $bundle_filename = sprintf('djebel-bundle-%s-%s.zip', $bundle_id, $bundle_ver);
    $bundle_file = $bundle_dir . '/' . $bundle_filename;
    $zip_root_dir = $bundle_dirname;

    // Ensure bundle directory exists
    if (!is_dir($bundle_dir) && !mkdir($bundle_dir, 0750, true)) {
        throw new RuntimeException("Failed to create bundle directory: $bundle_dir");
    }

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
    $site_dir = $dir_input;

    // Validate directory exists
    if (!is_dir($site_dir)) {
        throw new RuntimeException('Site directory not found: ' . $dir_input);
    }

    // Resolve to real path
    $site_dir = realpath($site_dir);

    if (empty($site_dir)) {
        throw new RuntimeException('Failed to resolve site directory path: ' . $dir_input);
    }

    // Create ZIP archive
    $zip = new ZipArchive();
    $zip_result = $zip->open($bundle_file, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($zip_result !== true) {
        throw new RuntimeException("Failed to create ZIP file: $bundle_file (Error: $zip_result)");
    }

    echo "Scanning plugins...\n";

    $plugins = [];

    // Scan and process site plugins from the site being bundled
    $site_plugins_dir = $site_dir . '/dj-content/plugins';
    $site_plugins_load_res = Dj_App_Plugins::loadPlugins(['dir' => $site_plugins_dir]);
    $site_plugins = empty($site_plugins_load_res->data['plugins']) ? [] : $site_plugins_load_res->data['plugins'];

    foreach ($site_plugins as $id => $plugin) {
        $plugin_name = empty($plugin['meta']['plugin_name']) ? '' : $plugin['meta']['plugin_name'];
        $plugin_version = empty($plugin['meta']['version']) ? '1.0.0' : $plugin['meta']['version'];
        $plugin_type = empty($plugin['type']) ? 'site' : $plugin['type'];

        $plugins[] = [
            'id' => $id,
            'type' => $plugin_type,
            'name' => $plugin_name,
            'version' => $plugin_version,
        ];
    }

    // Scan and process system plugins from the site being bundled
    $system_plugins_dir = $site_dir . '/.ht_djebel/app/plugins';
    $system_plugins_load_res = Dj_App_Plugins::loadPlugins(['dir' => $system_plugins_dir, 'is_system' => true]);
    $system_plugins = empty($system_plugins_load_res->data['plugins']) ? [] : $system_plugins_load_res->data['plugins'];

    foreach ($system_plugins as $id => $plugin) {
        $plugin_name = empty($plugin['meta']['plugin_name']) ? '' : $plugin['meta']['plugin_name'];
        $plugin_version = empty($plugin['meta']['version']) ? '1.0.0' : $plugin['meta']['version'];
        $plugin_type = empty($plugin['type']) ? 'system' : $plugin['type'];

        $plugins[] = [
            'id' => $id,
            'type' => $plugin_type,
            'name' => $plugin_name,
            'version' => $plugin_version,
        ];
    }

    echo "Found " . count($plugins) . " plugins\n\n";

    // Define exclusion patterns - cheap checks first
    $exclude_patterns = [
        '#(^|/)\.(git|svn)#',                    // Version control files/dirs
        '#\.(log|tmp|bak|sql)$#i',              // Temp/log files
        '#\.env[\w\-\.]*$#i',                    // Environment files
        '#/cache/#',                             // Cache directories
        '#/logs?/#i',                            // Log directories
        '#\.(zip|tar|gz|tgz)$#i',               // Archive files
    ];

    echo "Copying site files to bundle...\n";
    $add_dir_params = [
        'zip_obj' => $zip,
        'source_dir' => $site_dir,
        'zip_prefix' => $zip_root_dir,
        'exclude_patterns' => $exclude_patterns,
    ];

    $tool->addDirectoryToZip($add_dir_params);

    // Generate manifest
    echo "\nGenerating manifest...\n";
    $manifest_params = [
        'bundle_id' => $bundle_id,
        'bundle_description' => $bundle_description,
        'bundle_ver' => $bundle_ver,
        'bundle_url' => $bundle_url,
        'plugins' => $plugins,
    ];
    $manifest = $tool->generateManifest($manifest_params);
    $manifest_json = json_encode($manifest, JSON_PRETTY_PRINT);
    $zip->addFromString($zip_root_dir . '/.ht_djebel-manifest.json', $manifest_json);

    // Add readme files
    echo "Adding readme files...\n";
    $site_url = Dj_App::SITE_URL;

    $readme_txt_lines = [
        sprintf('Djebel Bundle: %s', $bundle_id),
        '',
        $bundle_description,
        '',
        sprintf('For more info go to %s', $site_url),
    ];

    if (!empty($bundle_url)) {
        $readme_txt_lines[] = sprintf('Bundle URL: %s', $bundle_url);
    }

    $readme_txt = join("\n", $readme_txt_lines);
    $zip->addFromString($zip_root_dir . '/000_readme.txt', $readme_txt);

    $readme_html_params = [
        'site_url' => $site_url,
        'bundle_id' => $bundle_id,
        'bundle_description' => $bundle_description,
        'bundle_url' => $bundle_url,
    ];

    $readme_html = $tool->generateReadmeHtml($readme_html_params);
    $zip->addFromString($zip_root_dir . '/000_readme.html', $readme_html);

    // Add ZIP comment
    $built_date = date('r');
    $zip_comment_lines = [
        sprintf('Djebel Bundle: %s v%s', $bundle_id, $bundle_ver),
        sprintf('Created: %s', $built_date),
        sprintf('Site: %s', $site_url),
    ];

    if (!empty($bundle_url)) {
        $zip_comment_lines[] = sprintf('URL: %s', $bundle_url);
    }

    $zip_comment = join("\n", $zip_comment_lines);
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
    Dj_Cli_Util::stderr("Error: " . $e->getMessage());

    $previous = $e->getPrevious();
    if ($previous !== null) {
        Dj_Cli_Util::stderr("Caused by: " . $previous->getMessage());
    }

    // Provide stack trace in verbose mode
    if (!empty(getenv('DJEBEL_TOOL_BUNDLE_VERBOSE'))) {
        Dj_Cli_Util::stderr("Stack trace:");
        Dj_Cli_Util::stderr($e->getTraceAsString());
    }

    $exit_code = 255;
}

exit($exit_code);

class Djebel_Tool_Bundle {
    function generateReadmeHtml($params) {
        $site_url = $params['site_url'];
        $bundle_id = $params['bundle_id'];
        $bundle_description = $params['bundle_description'];
        $bundle_url = empty($params['bundle_url']) ? '' : $params['bundle_url'];

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
    <?php if (!empty($bundle_url)): ?>
    <p>Bundle URL: <a href='<?php echo htmlspecialchars($bundle_url); ?>' target='_blank' rel='noopener'><?php echo htmlspecialchars($bundle_url); ?></a></p>
    <?php endif; ?>
</body>
</html>
<?php
        $html = ob_get_clean();
        return $html;
    }

    function generateManifest($params) {
        $bundle_id = $params['bundle_id'];
        $bundle_description = $params['bundle_description'];
        $bundle_ver = $params['bundle_ver'];
        $bundle_url = empty($params['bundle_url']) ? '' : $params['bundle_url'];
        $plugins = $params['plugins'];

        $manifest = [
            'themes' => [],
            'plugins' => [],
            'meta' => [
                'bundle_id' => $bundle_id,
                'bundle_version' => $bundle_ver,
                'created' => date('c'),
                'djebel_version' => Dj_App::VERSION,
                'site_url' => Dj_App::SITE_URL,
            ],
        ];

        if (!empty($bundle_description)) {
            $manifest['meta']['description'] = $bundle_description;
        }

        if (!empty($bundle_url)) {
            $manifest['meta']['bundle_url'] = $bundle_url;
        }

        // Add plugins to manifest
        foreach ($plugins as $plugin) {
            $plugin_version = empty($plugin['version']) ? '1.0.0' : $plugin['version'];

            $plugin_entry = [
                'id' => $plugin['id'],
                'version' => $plugin_version,
                'active' => true,
                'type' => $plugin['type'],
            ];

            if (!empty($plugin['name'])) {
                $plugin_entry['name'] = $plugin['name'];
            }

            $manifest['plugins'][] = $plugin_entry;
        }

        return $manifest;
    }

    function addDirectoryToZip($params) {
        $zip_obj = $params['zip_obj'];
        $source_dir = $params['source_dir'];
        $zip_prefix = $params['zip_prefix'];
        $exclude_patterns = $params['exclude_patterns'];

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
                $zip_obj->addEmptyDir($zip_path);
            } else {
                $zip_obj->addFile($file_path, $zip_path);
            }
        }
    }
}
