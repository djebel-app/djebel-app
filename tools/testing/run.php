#!/usr/bin/env php
<?php
// Runs an addon's test suite: prepares the framework, loads the addon, hands
// its tests to PHPUnit. Addons ship tests only — no bootstrap, no plumbing.
// Author: Svetoslav Marinov | https://orbisius.com
// Copyright: All Rights Reserved

$tool_name = basename(__FILE__);
$exit_code = 0;

// Load core libs. Through index.php, which loads them in dependency order — cli_util
// sits on Dj_App_Hooks and Dj_App_Util, so requiring it alone leaves it half-built.
// CORE_RUN=0 keeps it to the libraries; nothing is served.
$app_dir = dirname(__DIR__, 2);
putenv('DJEBEL_APP_CORE_RUN=0');
require_once $app_dir . '/index.php';

// Refuses to run outside CLI (403 + die), sends errors to stderr so stdout keeps
// carrying only PHPUnit's report, unbuffers output so a long suite reports as it runs,
// and bounds the run. One call instead of a hand-rolled sapi check per tool.
Dj_App_Cli_Util::init();

$expected_params = [
    'entry' => '',
    'filter' => '',
];

$params = Dj_App_Cli_Util::parseArgs($expected_params);
$args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];

foreach ($args as $arg) {
    if ($arg === '--help' || $arg === '-h' || $arg === '-help' || $arg == 'help') {
        Djebel_Tool_Test_Runner::printUsage($tool_name);
        exit(0);
    }
}

try {
    // Positional args are the addon dirs; parseArgs returns them under numeric keys.
    $addon_dirs = [];

    foreach ($params as $key => $val) {
        if (!is_int($key)) {
            continue;
        }

        $addon_dirs[] = $val;
    }

    // Run from inside an addon and it just works — the cwd is the obvious default.
    if (empty($addon_dirs)) {
        $addon_dirs[] = getcwd();
    }

    $entry_files = [];
    $tests_dirs = [];
    // --entry accepts a list (a,b or a|b) and a repeated flag, which parseArgs already
    // returns as an array. Flattened here so both forms land in one place.
    $entry_override = empty($params['entry']) ? '' : $params['entry'];
    $entry_names_override = [];

    foreach ((array) $entry_override as $entry_item) {
        $entry_items = Dj_App_String_Util::splitOnSeparators($entry_item);
        $entry_names_override = array_merge($entry_names_override, $entry_items);
    }

    $resolved_addon_dirs = [];

    foreach ($addon_dirs as $addon_dir) {
        // Expands $HOME, ${HOME} and ~/ before anything touches the filesystem.
        $addon_dir = Dj_App_File_Util::resolvePath($addon_dir);

        // Resolved into its own var: realpath() returns false for a missing dir, and
        // overwriting the input first left the error message with nothing to name.
        // realpath() also resolves a RELATIVE dir against the cwd, so ../foo and . work.
        $addon_dir_real = realpath($addon_dir);

        if (empty($addon_dir_real) || !is_dir($addon_dir_real)) {
            throw new Dj_App_Exception("Not a directory: {$addon_dir}", [
                'code' => 'tool.test.bad_addon_dir',
                'addon_dir' => $addon_dir,
                'exit_code' => 2,
            ]);
        }

        $addon_dir = $addon_dir_real;

        // Hand it a container — a site, a plugins dir — and it finds the addons inside
        // that actually carry tests, so a whole site runs in one go.
        $own_tests_dir = Djebel_Tool_Test_Runner::resolveTestsDir($addon_dir);

        if (empty($own_tests_dir)) {
            $found_dirs = Djebel_Tool_Test_Runner::findAddonDirs($addon_dir);

            if (empty($found_dirs)) {
                throw new Dj_App_Exception("No tests dir in {$addon_dir}, and no addon with tests underneath it", [
                    'code' => 'tool.test.no_tests_dir',
                    'addon_dir' => $addon_dir,
                    'exit_code' => 2,
                ]);
            }

            $resolved_addon_dirs = array_merge($resolved_addon_dirs, $found_dirs);
            continue;
        }

        $resolved_addon_dirs[] = $addon_dir;
    }

    $resolved_addon_dirs = array_unique($resolved_addon_dirs);

    foreach ($resolved_addon_dirs as $addon_dir) {
        // The framework itself is not an addon: its classes come from the bootstrap, so
        // there is nothing extra to load and no entry file to demand.
        $is_framework = $addon_dir == $app_dir;

        if (!$is_framework) {
            $found_entry_files = Djebel_Tool_Test_Runner::resolveEntryFiles($addon_dir, $entry_names_override);

            if (empty($found_entry_files)) {
                $entry_names = implode(', ', Djebel_Tool_Test_Runner::ENTRY_FILE_NAMES);

                throw new Dj_App_Exception("No entry file in {$addon_dir} (looked for: {$entry_names}). Pass --entry=NAME.", [
                    'code' => 'tool.test.no_entry_file',
                    'addon_dir' => $addon_dir,
                    'exit_code' => 2,
                ]);
            }

            $entry_files = array_merge($entry_files, $found_entry_files);
        }
        $tests_dirs[] = Djebel_Tool_Test_Runner::resolveTestsDir($addon_dir);
    }

    $phpunit_file = $app_dir . '/tests/vendor/bin/phpunit';

    if (!is_file($phpunit_file)) {
        throw new Dj_App_Exception('PHPUnit is not installed. Run composer install in the tests dir.', [
            'code' => 'tool.test.phpunit_missing',
            'phpunit_file' => $phpunit_file,
            'exit_code' => 3,
        ]);
    }

    // The bootstrap reads this: it is how a core-owned bootstrap learns which addon
    // to load, since PHPUnit owns argv and a bootstrap cannot take arguments of its own.
    $test_files_env = implode(':', $entry_files);

    if (!empty($test_files_env)) {
        putenv('DJEBEL_TEST_FILES=' . $test_files_env);
    }

    $bootstrap_file = __DIR__ . '/bootstrap.php';

    $cmd_parts = [
        escapeshellarg($phpunit_file),
        '--bootstrap ' . escapeshellarg($bootstrap_file),
        '--no-configuration',
    ];

    if (!empty($params['filter'])) {
        $cmd_parts[] = '--filter ' . escapeshellarg($params['filter']);
    }

    foreach ($tests_dirs as $tests_dir) {
        $cmd_parts[] = escapeshellarg($tests_dir);
    }

    $cmd = implode(' ', $cmd_parts);
    $tests_dirs_str = implode(' ', $tests_dirs);

    $loading_str = empty($test_files_env) ? '(framework only)' : $test_files_env;

    Dj_App_Cli_Util::stderr('Loading: ' . $loading_str);
    Dj_App_Cli_Util::stderr('Testing: ' . $tests_dirs_str);

    // PHPUnit's own exit code IS this tool's result.
    passthru($cmd, $exit_code);
} catch (Dj_App_Exception $e) {
    Dj_App_Cli_Util::stderr('Error: ' . $e->getMessage());
    $exc_data = $e->getData();
    $exit_code = empty($exc_data['exit_code']) ? 255 : $exc_data['exit_code'];
} catch (Exception $e) {
    Dj_App_Cli_Util::stderr('Error: ' . $e->getMessage());
    $exit_code = 255;
} finally {
    // Every failure path sets $exit_code and falls through to here — an exit() inside the
    // try would still run this block and overwrite the code with the initial 0.
    exit($exit_code);
}

class Djebel_Tool_Test_Runner
{
    // Entry file by addon type, in probe order. A plugin and a lib name theirs
    // differently, and a CLI tool keeps its own name, which --entry covers.
    const ENTRY_FILE_NAMES = [ 'plugin.php', 'lib.php', ];

    // Where a suite lives, in probe order. The nested one is probed FIRST: a tests dir
    // can also hold vendor/ and a bootstrap, which PHPUnit would otherwise scan.
    const TESTS_DIR_NAMES = [ 'tests/unit_tests', 'tests', ];

    // Where addons live inside a site, relative to it. The bare '*' covers a container
    // handed over directly, such as a plugins or lib dir.
    const ADDON_SCAN_PATTERNS = [
        '.ht_djebel/app/plugins/*',
        '.ht_djebel/app/lib/*',
        'public/dj-content/plugins/*',
        'public/dj-content/themes/*',
        'dj-content/plugins/*',
        'dj-content/themes/*',
        '*',
    ];

    /**
     * Finds the file(s) that define the addon's classes, so the shared bootstrap can
     * require them before PHPUnit collects any test.
     * @param string $addon_dir
     * @param array $entry_names_override File names relative to $addon_dir; win when given
     * @return array Empty when nothing matched
     */
    public static function resolveEntryFiles($addon_dir, $entry_names_override = [])
    {
        $entry_names = Djebel_Tool_Test_Runner::ENTRY_FILE_NAMES;
        $stop_at_first = 1;

        // An explicit list names every file to load, so all of them are required rather
        // than just the first that exists.
        if (!empty($entry_names_override)) {
            $entry_names = $entry_names_override;
            $stop_at_first = 0;
        }

        $entry_files = [];

        foreach ($entry_names as $name) {
            $entry_file = $addon_dir . '/' . $name;

            if (!is_file($entry_file)) {
                continue;
            }

            $entry_files[] = $entry_file;

            if (!empty($stop_at_first)) {
                break;
            }
        }

        return $entry_files;
    }

    /**
     * Scans a container - a site, a plugins dir - for addons that carry tests. Only dirs
     * with BOTH a tests dir and a resolvable entry file are returned, so a scan never
     * fails the run over an unrelated directory.
     * @param string $container_dir
     * @return array Absolute addon dirs
     */
    public static function findAddonDirs($container_dir)
    {
        $addon_dirs = [];

        foreach (Djebel_Tool_Test_Runner::ADDON_SCAN_PATTERNS as $pattern) {
            $matched_dirs = glob($container_dir . '/' . $pattern, GLOB_ONLYDIR);

            if (empty($matched_dirs)) {
                continue;
            }

            foreach ($matched_dirs as $matched_dir) {
                $tests_dir = Djebel_Tool_Test_Runner::resolveTestsDir($matched_dir);

                if (empty($tests_dir)) {
                    continue;
                }

                $entry_files = Djebel_Tool_Test_Runner::resolveEntryFiles($matched_dir);

                if (empty($entry_files)) {
                    continue;
                }

                $addon_dirs[] = $matched_dir;
            }
        }

        $addon_dirs = array_unique($addon_dirs);
        $addon_dirs = array_values($addon_dirs);

        return $addon_dirs;
    }

    /**
     * Finds the addon's test dir. PHPUnit recurses, so a nested layout is covered
     * by handing it the top dir.
     * @param string $addon_dir
     * @return string Empty when nothing matched
     */
    public static function resolveTestsDir($addon_dir)
    {
        foreach (Djebel_Tool_Test_Runner::TESTS_DIR_NAMES as $name) {
            $tests_dir = $addon_dir . '/' . $name;

            if (is_dir($tests_dir)) {
                return $tests_dir;
            }
        }

        return '';
    }

    /**
     * @param string $tool_name
     * @return void
     */
    public static function printUsage($tool_name)
    {
        $script = __FILE__;
        $entry_names = implode(', ', Djebel_Tool_Test_Runner::ENTRY_FILE_NAMES);

        $usage = <<<USAGE

Usage: php $script [addon-dir ...] [options]

Runs a plugin's or lib's test suite. The framework is prepared here and the addon
is loaded for you, so an addon ships test files only.

Options:
  --entry=NAME     Entry file name inside the addon dir (default: $entry_names)
  --filter=NAME    Only run tests matching NAME (passed to PHPUnit)
  --help, -h       Show this help

The dir may be absolute or relative, may use ~/ or $HOME, and defaults to the current
directory — so running it from inside an addon needs no argument at all.

Examples:
  php $script                                  # test the addon in the current dir
  php $script .
  php $script ../../plugins/djebel-markdown
  php $script ~/projects/djebel-markdown
  php $script /abs/path/djebel-markdown --filter=Cyrillic
  php $script /abs/path/some-plugin --entry=tools/stats.php

  # A suite that needs a sibling loaded too — list both, in load order:
  php $script /abs/path/djebel-markdown /abs/path/djebel-static-content

USAGE;

        Dj_App_Cli_Util::stderr($usage);
    }
}
