<?php
/**
 * CLI utility class for command-line tools
 * Provides argument parsing and normalization
 */
class Dj_Cli_Util {
    /**
     * Normalize CLI arguments by converting hyphens to underscores in argument names
     * This allows --bundle-id and --bundle_id to work interchangeably
     *
     * @param array $args Raw command-line arguments from $_SERVER['argv']
     * @return array Normalized arguments with underscores
     */
    static function normalizeArgs($args) {
        $normalized = [];

        foreach ($args as $arg) {
            // Get first 2 chars for cheap prefix check
            $prefix = substr($arg, 0, 2);

            // Cheap check: skip if doesn't start with --
            if ($prefix !== '--') {
                $normalized[] = $arg;
                continue;
            }

            // Cheap check: skip if no hyphens to normalize (after --)
            if (strpos($arg, '-', 2) === false) {
                $normalized[] = $arg;
                continue;
            }

            // Check if argument has a value (contains =)
            $equals_pos = strpos($arg, '=');

            if ($equals_pos !== false) { // Has value: only normalize the key part
                $key = substr($arg, 0, $equals_pos);
                $key_without_prefix = substr($key, 2);
                $value = substr($arg, $equals_pos + 1);
            } else { // No value: normalize entire arg
                $value = '';
                $key_without_prefix = substr($arg, 2);
            }

            // Normalize the key
            $normalized_key = str_replace('-', '_', $key_without_prefix);
            $normalized_arg = $prefix . $normalized_key;

            // Append value if present
            if ($equals_pos !== false) {
                $normalized_arg .= '=' . $value;
            }

            $normalized[] = $normalized_arg;
        }

        return $normalized;
    }

    /**
     * Write message to STDERR
     *
     * @param string $msg Message to write (optional, defaults to empty for newline)
     * @return bool Always returns true
     */
    static function stderr($msg = '') {
        fputs(STDERR, $msg . "\n");
        return true;
    }

    /**
     * Parse command-line arguments with defaults
     *
     * @param array $expected_params Associative array of param_name => default_value
     * @param array $args Raw arguments from $_SERVER['argv'] (optional, defaults to $_SERVER['argv'])
     * @return array Parsed parameters with values
     */
    static function parseArgs($expected_params = [], $args = []) {
        // Default to global argv if not provided
        if (empty($args)) {
            $args = empty($_SERVER['argv']) ? [] : $_SERVER['argv'];
            array_shift($args); // Remove script name
        }

        // Initialize with defaults
        $params = $expected_params;
        $known_params = array_keys($expected_params);
        $has_expected_params = !empty($expected_params);
        $help_args = [ '--help', '-h', '-help', 'help', ];

        foreach ($args as $arg) {
            // Skip help arguments - cheap check first
            if ((strpos($arg, 'h') !== false) && in_array($arg, $help_args)) {
                continue;
            }

            // Skip invalid format: must be --key=value
            if ((strpos($arg, '--') !== 0) || (strpos($arg, '=') === false)) {
                continue;
            }

            // Parse --key=value format
            $equals_pos = strpos($arg, '=');
            $key = substr($arg, 2, $equals_pos - 2);
            $value = substr($arg, $equals_pos + 1);

            // Skip unknown keys if we have expected params
            if ($has_expected_params && !in_array($key, $known_params)) {
                continue;
            }

            $params[$key] = $value;
        }

        return $params;
    }
}
