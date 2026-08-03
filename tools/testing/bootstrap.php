<?php
/**
 * Shared bootstrap for PLUGIN, THEME and LIB test suites.
 *
 * Loads the framework (headless — every core lib loads, nothing serves), then the
 * entry files named by DJEBEL_TEST_FILES. An addon ships only its test files:
 * the setup lives here, written once, instead of a copy inside every addon.
 *
 * DJEBEL_TEST_FILES holds absolute file paths separated by ':'. Entry files are named
 * explicitly rather than guessed, because the entry differs by addon type. List
 * several when a suite needs a sibling addon loaded too.
 *
 * Anything else a suite needs from the environment is passed as ordinary environment
 * variables on the command line — the shell already inherits them into the run, so no
 * addon-specific handling belongs here.
 *
 * See docs/developers/testing.md for concrete invocations.
 */

// The framework's own test bootstrap, two levels up: this file only adds the addon
// loading on top of it.
require_once dirname(__DIR__, 2) . '/tests/bootstrap.php';

$test_files = getenv('DJEBEL_TEST_FILES');

// Optional on purpose: with nothing named, the framework alone is loaded, which is
// exactly what the framework's OWN suite needs. One bootstrap covers both.
if (empty($test_files)) {
    return;
}

$test_files = explode(':', $test_files);
$test_files = Dj_App_String_Util::trim($test_files);

foreach ($test_files as $test_file) {
    if (empty($test_file)) {
        continue;
    }

    if (!is_file($test_file)) {
        die("DJEBEL_TEST_FILES entry not found: {$test_file}\n");
    }

    require_once $test_file;
}
