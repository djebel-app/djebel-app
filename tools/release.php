<?php
/**
 * Release/packaging tool for djebel-app, plugins, and themes
 *
 * Builds a distributable zip using ozip.
 * Excludes tests/, build/, .git/, and dev files.
 *
 * Usage:
 *   php release.php                                  # Package djebel-app
 *   php release.php --plugin-dir=/path/to/plugin     # Package a plugin
 *   php release.php --theme-dir=/path/to/theme       # Package a theme
 *   php release.php --dry-run                        # Show what would be packaged
 *   php release.php --help                           # Show usage
 */

// CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Cannot run');
}

$tool_name = basename(__FILE__);
$app_dir = dirname(__DIR__);

// Load core libs
require_once $app_dir . '/src/core/lib/cli_util.php';
putenv('DJEBEL_APP_CORE_RUN=0');
require_once $app_dir . '/index.php';

// Get command line arguments
$args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
array_shift($args);

// Help check (exit early before any processing)
foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h' || $arg === '-help' || $arg == 'help') {
        echo "Usage: php $tool_name [--plugin-dir=<path>] [--theme-dir=<path>] [--build-dir=<path>] [--dry-run] [--help|-h]\n";
        echo "Options:\n";
        echo "  --help, -h              Show this help message\n";
        echo "  --plugin-dir=<path>     Package a plugin directory\n";
        echo "  --theme-dir=<path>      Package a theme directory\n";
        echo "  --build-dir=<path>      Custom build output directory\n";
        echo "  --dry-run               Show what would be packaged without creating the zip\n";
        echo "  (no dir flag)           Package djebel-app itself\n";
        echo "\n";
        echo "Environment Variables:\n";
        echo "  DJEBEL_APP_TOOL_RELEASE_PLUGIN_DIR   Fallback for --plugin-dir\n";
        echo "  DJEBEL_APP_TOOL_RELEASE_THEME_DIR    Fallback for --theme-dir\n";
        echo "  DJEBEL_APP_TOOL_RELEASE_BUILD_DIR    Fallback for --build-dir\n";
        echo "  DJEBEL_APP_TOOL_RELEASE_DRY_RUN      Set to 1 to enable dry-run\n";
        echo "\n";
        echo "Output:\n";
        echo "  djebel-app:   build/djebel-app-{version}.zip\n";
        echo "  plugin:       build/plugins/{name}-{version}.zip\n";
        echo "  theme:        build/themes/{name}-{version}.zip\n";
        echo "\n";
        echo "Excludes:\n";
        echo "  tests/, .phpunit.cache, build/, .git/, .claude/, docs/,\n";
        echo "  *.log, .DS_Store, .gitignore, .htaccess\n";
        echo "\n";
        echo "Examples:\n";
        echo "  php $tool_name                                                    # Package djebel-app\n";
        echo "  php $tool_name --plugin-dir=../../path/to/my-plugin               # Package a plugin\n";
        echo "  php $tool_name --theme-dir=../../path/to/my-theme                 # Package a theme\n";
        echo "  php $tool_name --plugin-dir=../../path/to/my-plugin --dry-run     # Dry run\n";
        exit(0);
    }
}

$exit_code = 0;

try {
    $djebel_version = Dj_App::VERSION;

    // Parse CLI args with defaults
    $expected_params = [
        'plugin_dir' => '',
        'theme_dir' => '',
        'build_dir' => '',
    ];

    $params = Dj_Cli_Util::parseArgs($expected_params, $args);

    // Extract to local vars
    $plugin_dir_input = $params['plugin_dir'];
    $theme_dir_input = $params['theme_dir'];
    $build_dir_input = $params['build_dir'];

    // Check for --dry-run flag (boolean, not key=value)
    $is_dry_run = false;

    foreach ($args as $arg) {
        if ($arg === '--dry-run') {
            $is_dry_run = true;
            break;
        }
    }

    // Env var fallbacks
    if (empty($plugin_dir_input)) {
        $plugin_dir_env = getenv('DJEBEL_APP_TOOL_RELEASE_PLUGIN_DIR');
        $plugin_dir_input = empty($plugin_dir_env) ? '' : $plugin_dir_env;
    }

    if (empty($theme_dir_input)) {
        $theme_dir_env = getenv('DJEBEL_APP_TOOL_RELEASE_THEME_DIR');
        $theme_dir_input = empty($theme_dir_env) ? '' : $theme_dir_env;
    }

    if (empty($build_dir_input)) {
        $build_dir_env = getenv('DJEBEL_APP_TOOL_RELEASE_BUILD_DIR');
        $build_dir_input = empty($build_dir_env) ? '' : $build_dir_env;
    }

    if (!$is_dry_run) {
        $dry_run_env = getenv('DJEBEL_APP_TOOL_RELEASE_DRY_RUN');
        $is_dry_run = !empty($dry_run_env);
    }

    // Determine what we're packaging
    $package_type = 'app';

    if (!empty($plugin_dir_input)) {
        $package_type = 'plugin';
    } elseif (!empty($theme_dir_input)) {
        $package_type = 'theme';
    }

    if ($package_type === 'app') {
        // Packaging djebel-app
        $target_dir = $app_dir;
        $target_name = 'djebel-app';
        $version = $djebel_version;
        $build_dir = empty($build_dir_input) ? $app_dir . '/build' : $build_dir_input;
    } elseif ($package_type === 'plugin') {
        $dir_input = $plugin_dir_input;
        $target_dir = realpath($dir_input);

        if (empty($target_dir) || !is_dir($target_dir)) {
            throw new RuntimeException("Plugin directory not found: $dir_input");
        }

        $target_name = basename($target_dir);
        $build_dir = empty($build_dir_input) ? $app_dir . '/build/plugins' : $build_dir_input;

        // Read version from plugin.php header
        $plugin_file = $target_dir . '/plugin.php';

        if (!file_exists($plugin_file)) {
            throw new RuntimeException("plugin.php not found in $target_dir");
        }

        $header_content = file_get_contents($plugin_file);

        // Extract header block between /* ... */
        $header_matches = [];
        $has_header = preg_match('#/\*(.+?)\*/#si', $header_content, $header_matches);

        if (empty($has_header)) {
            throw new RuntimeException("Could not find header block in plugin.php");
        }

        $header_block = $header_matches[1];

        // Extract version from header
        $ver_matches = [];
        $has_version = preg_match('#^\s*version\s*:\s*([\d]+\.[\d]+[\.\d]*\S*)\s*$#mi', $header_block, $ver_matches);

        if (empty($has_version)) {
            throw new RuntimeException("Could not find version in plugin.php header");
        }

        $version = trim($ver_matches[1]);
    } else {
        // Theme
        $dir_input = $theme_dir_input;
        $target_dir = realpath($dir_input);

        if (empty($target_dir) || !is_dir($target_dir)) {
            throw new RuntimeException("Theme directory not found: $dir_input");
        }

        $target_name = basename($target_dir);
        $build_dir = empty($build_dir_input) ? $app_dir . '/build/themes' : $build_dir_input;

        // Read version from theme index.php header
        $theme_file = $target_dir . '/index.php';

        if (!file_exists($theme_file)) {
            throw new RuntimeException("index.php not found in $target_dir");
        }

        $header_content = file_get_contents($theme_file);

        // Extract header block between /* ... */
        $header_matches = [];
        $has_header = preg_match('#/\*(.+?)\*/#si', $header_content, $header_matches);

        if (empty($has_header)) {
            throw new RuntimeException("Could not find header block in theme index.php");
        }

        $header_block = $header_matches[1];

        // Extract version from header
        $ver_matches = [];
        $has_version = preg_match('#^\s*version\s*:\s*([\d]+\.[\d]+[\.\d]*\S*)\s*$#mi', $header_block, $ver_matches);

        if (empty($has_version)) {
            throw new RuntimeException("Could not find version in theme index.php header");
        }

        $version = trim($ver_matches[1]);
    }

    if (empty($version)) {
        throw new RuntimeException("Version is empty");
    }

    // Build zip filename
    $zip_name = $target_name . '-' . $version . '.zip';
    $zip_path = $build_dir . '/' . $zip_name;

    // Ensure build directory exists
    if (!is_dir($build_dir) && !mkdir($build_dir, 0750, true)) {
        throw new RuntimeException("Failed to create build directory: $build_dir");
    }

    // Print build info
    echo "Package:         $target_name\n";
    echo "Type:            $package_type\n";
    echo "Version:         $version\n";

    if ($package_type !== 'app') {
        echo "Djebel version:  $djebel_version\n";
    }

    echo "Output:          $zip_path\n";
    echo "\n";

    // --- Find ozip binary ---
    $ozip_bin = '';
    $ozip_candidates = [
        'ozip',
        'ozip_mac',
        $_SERVER['HOME'] . '/.local/bin/ozip_mac',
        '/usr/local/bin/ozip',
        '/usr/local/bin/ozip_mac',
    ];

    foreach ($ozip_candidates as $candidate) {
        $which_result = trim((string) shell_exec('which ' . escapeshellarg($candidate) . ' 2>/dev/null'));

        if (!empty($which_result)) {
            $ozip_bin = $which_result;
            break;
        }

        // Direct path check
        if (file_exists($candidate) && is_executable($candidate)) {
            $ozip_bin = $candidate;
            break;
        }
    }

    if (empty($ozip_bin)) {
        throw new RuntimeException("ozip not found. Install ozip or place ozip_mac in ~/.local/bin/");
    }

    echo "Using:           $ozip_bin\n";
    echo "\n";

    // --- Build exclude patterns ---
    $exclude_patterns = [
        '*/tests/*',
        '*/.phpunit.cache/*',
        '*/build/*',
        '*/.git/*',
        '*/.claude/*',
        '*/.DS_Store',
        '*.log',
    ];

    // Extra excludes for djebel-app
    if ($package_type === 'app') {
        $exclude_patterns[] = '*/tools/*';
        $exclude_patterns[] = '*/docs/*';
        $exclude_patterns[] = '*/.gitignore';
        $exclude_patterns[] = '*/.htaccess';
    }

    // --- Build ozip command ---
    $ozip_args = [
        escapeshellarg($ozip_bin),
        '-9',
        '--app-test-archive',
    ];

    foreach ($exclude_patterns as $pattern) {
        $ozip_args[] = '-x';
        $ozip_args[] = escapeshellarg($pattern);
    }

    if ($is_dry_run) {
        $ozip_args[] = '--app-dry-run';
    }

    $ozip_args[] = escapeshellarg($zip_path);
    $ozip_args[] = escapeshellarg($target_dir . '/');

    $cmd = implode(' ', $ozip_args);

    // Remove old zip if exists
    if (!$is_dry_run && file_exists($zip_path)) {
        unlink($zip_path);
    }

    echo "Running: $cmd\n";
    echo "\n";

    passthru($cmd, $exit_code);

    if ($exit_code !== 0) {
        throw new RuntimeException("ozip failed with exit code $exit_code");
    }

    if (!$is_dry_run) {
        echo "\n";
        echo "Created: $zip_name\n";

        $zip_size = filesize($zip_path);
        $zip_size_fmt = number_format($zip_size);
        echo "Size:    $zip_size_fmt bytes\n";
    }
} catch (Exception $e) {
    Dj_Cli_Util::stderr("Error: " . $e->getMessage());

    $previous = $e->getPrevious();

    if ($previous !== null) {
        Dj_Cli_Util::stderr("Caused by: " . $previous->getMessage());
    }

    $exit_code = 255;
}

exit($exit_code);
