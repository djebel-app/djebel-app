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
        echo "Usage: php $tool_name --bundle_id=VALUE --bundle_description='VALUE' --bundle_ver=VALUE --dir=VALUE [--target_dir=VALUE] [--compression_level=VALUE] [--help|-h]\n";
        echo "Options:\n";
        echo "  --help, -h                     Show this help message\n";
        echo "  --bundle_id=VALUE              Bundle identifier (required, alphanumeric + hyphens)\n";
        echo "  --bundle_description='VALUE'   Bundle description (required)\n";
        echo "  --bundle_ver=VALUE             Bundle version (required, format: X.Y.Z)\n";
        echo "  --dir=VALUE                    Site directory to bundle (required)\n";
        echo "                                 Can be site name from app/sites/ or full path\n";
        echo "  --target_dir=VALUE             Output directory for bundle (optional)\n";
        echo "                                 Overrides DJEBEL_TOOL_BUNDLE_TARGET_DIR env var\n";
        echo "  --compression_level=VALUE      ZIP compression level 0-9 (optional, default: 9)\n";
        echo "                                 0 = no compression, 9 = maximum compression\n";
        echo "                                 Overrides DJEBEL_TOOL_BUNDLE_COMPRESSION_LEVEL env var\n";
        echo "\n";
        echo "Environment Variables:\n";
        echo "  DJEBEL_TOOL_BUNDLE_TARGET_DIR        Custom output directory (default: build/bundles/)\n";
        echo "  DJEBEL_TOOL_BUNDLE_COMPRESSION_LEVEL ZIP compression level 0-9 (default: 9)\n";
        echo "  DJEBEL_TOOL_BUNDLE_VERBOSE            Enable verbose error output\n";
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
        'bundle_id' => 'default',
        'bundle_description' => '',
        'bundle_ver' => '1.0.0',
        'bundle_url' => '',
        'target_dir' => '',
        'compression_level' => 9,
    ];

    $params = Dj_Cli_Util::parseArgs($expected_params, $args);

    // Extract to local variables
    $bundle_id = $params['bundle_id'];
    $bundle_description = $params['bundle_description'];
    $bundle_ver = $params['bundle_ver'];
    $bundle_url = $params['bundle_url'];
    $dir_input = $params['dir'];
    $target_dir_param = $params['target_dir'];
    $compression_level_param = $params['compression_level'];

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

    // Get compression level: --compression_level > env var > default (9)
    if (!empty($compression_level_param)) {
        $compression_level = $compression_level_param;
    } else {
        $compression_level_env = getenv('DJEBEL_TOOL_BUNDLE_COMPRESSION_LEVEL');
        $compression_level = empty($compression_level_env) ? 9 : $compression_level_env;
    }

    // Validate compression level (0-9)
    $compression_level = (int) $compression_level;

    if ($compression_level < 0 || $compression_level > 9) {
        throw new InvalidArgumentException("Invalid compression level: $compression_level. Must be 0-9.");
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

    if (!empty($bundle_description)) {
        echo "Description: $bundle_description\n";
    }

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

    // Create ZIP archive with maximum compression
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
        '#(^|/)cache(/|$)#',                     // Cache directories
        '#(^|/)logs?(/|$)#i',                    // Log directories
        '#\.(zip|tar|gz|tgz|rar|7z|bz2)$#i',   // Archive files
        '#\.phar$#i',                            // PHAR files
        '#(^|/)\.DS_Store$#',                   // macOS metadata
        '#(^|/)Thumbs\.db$#i',                  // Windows thumbnails
        '#(^|/)desktop\.ini$#i',                // Windows folder config
        '#(^|/)~\$#',                            // Office temp files
    ];

    // Prepare variables for readme files and index.php
    $site_url = Dj_App::SITE_URL;
    $built_date = date('r');
    $priv_dir_name = $tool->getDjebelPrivDirName($bundle_id);

    // Add readme files first (appear at top of ZIP listing)
    echo "Adding readme files...\n";

    $readme_txt_lines = [
        sprintf('Djebel Bundle: %s', $bundle_id),
        sprintf('Version: %s', $bundle_ver),
        sprintf('Created: %s', $built_date),
        '',
        $bundle_description,
        '',
        sprintf('For more info go to %s', $site_url),
    ];

    if (!empty($bundle_url)) {
        $readme_txt_lines[] = sprintf('Bundle URL: %s', $bundle_url);
    }

    $readme_txt = join("\n", $readme_txt_lines);
    $readme_txt = trim($readme_txt);
    $zip->addFromString('000_readme.txt', $readme_txt);

    $readme_html_params = [
        'site_url' => $site_url,
        'bundle_id' => $bundle_id,
        'bundle_ver' => $bundle_ver,
        'bundle_url' => $bundle_url,
        'bundle_description' => $bundle_description,
        'built_date' => $built_date,
    ];

    $readme_html = $tool->generateReadmeHtml($readme_html_params);
    $readme_html = trim($readme_html);
    $zip->addFromString('000_readme.html', $readme_html);

    // Generate and add index.php to public/
    echo "Generating public/index.php...\n";
    $index_params = [
        'bundle_id' => $bundle_id,
    ];

    $index_content = $tool->generateMainIndexFile($index_params);
    $zip->addFromString('public/index.php', $index_content);

    // Copy dj-content to public/dj-content
    $site_content_dir = $site_dir . '/dj-content';

    if (is_dir($site_content_dir)) {
        echo "Adding dj-content...\n";
        $add_content_params = [
            'zip_obj' => $zip,
            'source_dir' => $site_content_dir,
            'zip_prefix' => 'public/dj-content',
            'exclude_patterns' => $exclude_patterns,
            'compression_level' => $compression_level,
        ];

        $tool->addDirectoryToZip($add_content_params);
    }

    // Copy .htaccess to public/.htaccess
    $site_htaccess = $site_dir . '/.htaccess';

    if (file_exists($site_htaccess)) {
        echo "Adding .htaccess...\n";
        $add_result = $zip->addFile($site_htaccess, 'public/.htaccess');

        if ($add_result) {
            $zip->setCompressionName('public/.htaccess', ZipArchive::CM_DEFLATE, $compression_level);
        }
    }

    // Find .ht_djebel directory
    $site_ht_djebel_dir = $site_dir . '/.ht_djebel';

    if (!is_dir($site_ht_djebel_dir)) {
        echo "Searching for .ht_djebel* directory...\n";
        $glob_pattern = $site_dir . '/.ht_djebel*';
        $found_dirs = glob($glob_pattern, GLOB_ONLYDIR);

        if (empty($found_dirs)) {
            throw new RuntimeException("No .ht_djebel directory found in: $site_dir");
        }

        if (count($found_dirs) > 1) {
            $dirs_list = implode(', ', $found_dirs);
            throw new RuntimeException("Multiple .ht_djebel directories found: $dirs_list. Please specify which one to use.");
        }

        $site_ht_djebel_dir = $found_dirs[0];
        echo "Found: " . basename($site_ht_djebel_dir) . "\n";
    }

    // Copy .ht_djebel directory to .ht_djebel_{bundle_id} (or .ht_djebel for 'default')
    echo "Adding $priv_dir_name directory...\n";
    $add_ht_djebel_params = [
        'zip_obj' => $zip,
        'source_dir' => $site_ht_djebel_dir,
        'zip_prefix' => $priv_dir_name,
        'exclude_patterns' => $exclude_patterns,
        'compression_level' => $compression_level,
    ];

    $tool->addDirectoryToZip($add_ht_djebel_params);

    // Replace with latest PHAR from build
    echo "Replacing with latest Djebel app PHAR...\n";
    $build_dir = $app_dir . '/build';
    $phar_pattern = $build_dir . '/djebel-app-*.phar';
    $phar_files = glob($phar_pattern);
    $djebel_app_version = '';

    if (empty($phar_files)) {
        throw new RuntimeException("No PHAR file found in $build_dir. Run 'php tools/pkg.php --phar' to build it.");
    }

    usort($phar_files, [$tool, 'compareVersions']);

    $latest_phar = $phar_files[0];
    $phar_basename = basename($latest_phar);
    $phar_zip_path = $priv_dir_name . '/app/djebel-app.phar';

    $version_pattern = '/djebel-app-(.+)\.phar$/';

    if (preg_match($version_pattern, $phar_basename, $version_matches)) {
        $djebel_app_version = $version_matches[1];
    }

    echo "Using PHAR: $phar_basename\n";
    $add_result = $zip->addFile($latest_phar, $phar_zip_path);

    if ($add_result) {
        $zip->setCompressionName($phar_zip_path, ZipArchive::CM_DEFLATE, $compression_level);
    }

    // Extract commit hash from PHAR header
    $phar_header = file_get_contents($latest_phar, false, null, 0, 1024);
    $djebel_app_git_commit = '';

    if (preg_match('/DJEBEL_TOOL_PKG_PHAR_BUILD_GIT_COMMIT.+[\'"]([a-f0-9]{7,40})[\'"]/', $phar_header, $commit_matches)) {
        $djebel_app_git_commit = $commit_matches[1];
        $short_hash = substr($djebel_app_git_commit, 0, 12);
        echo "PHAR commit: $short_hash\n";
    }

    // Generate manifest
    echo "Generating manifest...\n";
    $manifest_params = [
        'bundle_id' => $bundle_id,
        'bundle_description' => $bundle_description,
        'bundle_ver' => $bundle_ver,
        'bundle_url' => $bundle_url,
        'plugins' => $plugins,
        'djebel_app_version' => $djebel_app_version,
        'djebel_app_git_commit' => $djebel_app_git_commit,
    ];

    $manifest = $tool->generateManifest($manifest_params);
    $manifest_json = json_encode($manifest, JSON_PRETTY_PRINT);
    $zip->addFromString($priv_dir_name . '/.ht_djebel-manifest.json', $manifest_json);

    // Add ZIP comment
    echo "Adding ZIP comment...\n";
    $zip_comment_lines = [
        sprintf('Djebel Bundle: %s', $bundle_id),
        sprintf('Version: %s', $bundle_ver),
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

    echo "Bundle created successfully!\n";
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
    function compareVersions($a, $b) {
        // Extract version from filename: djebel-app-1.2.3.phar
        $pattern = '/djebel-app-(.+)\.phar$/';
        $basename_a = basename($a);
        $basename_b = basename($b);

        $version_a = '0.0.0';
        $version_b = '0.0.0';

        if (preg_match($pattern, $basename_a, $matches_a)) {
            $version_a = $matches_a[1];
        }

        if (preg_match($pattern, $basename_b, $matches_b)) {
            $version_b = $matches_b[1];
        }

        return version_compare($version_b, $version_a);
    }

    // Get private directory name based on bundle_id (single source of truth)
    function getDjebelPrivDirName($bundle_id) {
        $priv_dir_name = '.ht_djebel';

        if (!empty($bundle_id) && $bundle_id != 'default') {
            $priv_dir_name .= '_' . $bundle_id;
        }

        return $priv_dir_name;
    }

    function generateReadmeHtml($params) {
        $site_url = $params['site_url'];
        $bundle_id = $params['bundle_id'];
        $bundle_ver = $params['bundle_ver'];
        $bundle_description = $params['bundle_description'];
        $built_date = $params['built_date'];
        $bundle_url = empty($params['bundle_url']) ? '' : $params['bundle_url'];

        $bundle_url_line = '';

        if (!empty($bundle_url)) {
            $bundle_url_line = "<p>Bundle URL: <a href='{{bundle_url_esc}}' target='_blank' rel='noopener'>{{bundle_url_esc}}</a></p>";
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>Djebel Bundle</title>
</head>
<body>
    <h1>{{bundle_id_esc}}</h1>
    <p><strong>Version:</strong> {{bundle_ver_esc}}</p>
    <p><strong>Created:</strong> {{built_date_esc}}</p>
    <p>{{bundle_description_esc}}</p>
    <p>For more info go to <a href='{{site_url_esc}}' target='_blank' rel='noopener'>{{site_url_esc}}</a></p>
    {{bundle_url_line}}
</body>
</html>
<?php
        $html = ob_get_clean();
        $html = trim($html);

        $replace_vars = [
            '{{bundle_id_esc}}' => htmlspecialchars($bundle_id),
            '{{bundle_ver_esc}}' => htmlspecialchars($bundle_ver),
            '{{built_date_esc}}' => htmlspecialchars($built_date),
            '{{bundle_description_esc}}' => htmlspecialchars($bundle_description),
            '{{site_url_esc}}' => htmlspecialchars($site_url),
            '{{bundle_url_esc}}' => htmlspecialchars($bundle_url),
            '{{bundle_url_line}}' => $bundle_url_line,
        ];

        $html = str_replace(array_keys($replace_vars), array_values($replace_vars), $html);

        return $html;
    }

    // Generate index.php for bundle with auto-detection of private dir
    function generateMainIndexFile($params) {
        $bundle_id = $params['bundle_id'];

        ob_start();
        ?>
{{php_open_tag}}
/**
 * Djebel app loader.
 * https://djebel.com
 */

// Full path override via environment
$app_djebel_priv_dir = getenv('DJEBEL_APP_PRIVATE_DIR');

// Auto-detect if not set
if (empty($app_djebel_priv_dir)) {
    $priv_dir_basename = '{{priv_dir_name}}';

    // Check directories in order (set to 0 to skip for better performance)
    $check_dirs = [
        dirname(__DIR__) => 1,      // Same level as public/ (which is document root (www/public_html)
        dirname(__DIR__, 2) => 1,   // One level up (non-public)
        dirname(__DIR__, 3) => 0,   // Two levels up (non-public)
    ];

    foreach ($check_dirs as $base_dir => $enabled) {
        if (empty($enabled)) {
            continue;
        }

        $check_path = $base_dir . '/' . $priv_dir_basename;

        if (is_dir($check_path)) {
            $app_djebel_priv_dir = $check_path;
            break;
        }
    }

    putenv('DJEBEL_APP_PRIVATE_DIR=' . $app_djebel_priv_dir);
}


// Check for PHAR package path
$app_djebel_phar = getenv('DJEBEL_APP_PKG');

if (!empty($app_djebel_phar)) {
    if (substr($app_djebel_phar, -5) !== '.phar') {
        die('Error: DJEBEL_APP_PKG must point to a .phar file');
    }
} else {
    $app_djebel_phar = $app_djebel_priv_dir . '/app/djebel-app.phar';
}

if (!file_exists($app_djebel_phar)) {
    die('Error: Cannot find Djebel app PHAR at: ' . $app_djebel_phar);
}

require_once $app_djebel_phar;
<?php
        $content = ob_get_clean();
        $content = trim($content);
        $content .= "\n";

        $priv_dir_name = $this->getDjebelPrivDirName($bundle_id);

        $replace_vars = [
            '{{php_open_tag}}' => '<?php',
            '{{priv_dir_name}}' => $priv_dir_name,
        ];

        $content = str_replace(array_keys($replace_vars), array_values($replace_vars), $content);

        return $content;
    }

    function generateManifest($params) {
        $bundle_id = $params['bundle_id'];
        $bundle_description = $params['bundle_description'];
        $bundle_ver = $params['bundle_ver'];
        $bundle_url = empty($params['bundle_url']) ? '' : $params['bundle_url'];
        $plugins = $params['plugins'];
        $djebel_app_version = empty($params['djebel_app_version']) ? '' : $params['djebel_app_version'];
        $djebel_app_git_commit = empty($params['djebel_app_git_commit']) ? '' : $params['djebel_app_git_commit'];

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

        if (!empty($djebel_app_version)) {
            $manifest['meta']['djebel_app_version'] = $djebel_app_version;
        }

        if (!empty($djebel_app_git_commit)) {
            $manifest['meta']['djebel_app_git_commit'] = $djebel_app_git_commit;
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
        $compression_level = empty($params['compression_level']) ? 9 : $params['compression_level'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $file_path = $file->getPathname();
            $source_dir_len = strlen($source_dir);
            $relative_path = substr($file_path, $source_dir_len + 1);
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
                $add_result = $zip_obj->addFile($file_path, $zip_path);

                if ($add_result) {
                    // Set compression level for this file
                    $zip_obj->setCompressionName($zip_path, ZipArchive::CM_DEFLATE, $compression_level);
                }
            }
        }
    }
}
