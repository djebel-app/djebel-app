<?php
/**
 * CLI utility class for command-line tools
 * Provides argument parsing and normalization
 */
class Dj_App_Cli_Util {
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
     * Handles every form a CLI tool actually receives:
     *
     *   --key=value        →  [ 'key' => 'value', ]
     *   --key value        →  [ 'key' => 'value', ]       (space separated)
     *   --flag             →  [ 'flag' => true, ]         (value-less switch)
     *   --tag=a --tag=b    →  [ 'tag' => [ 'a', 'b', ], ] (repeated key)
     *   -k value           →  [ 'k' => 'value', ]         (short option)
     *   -abc               →  [ 'a' => true, 'b' => true, 'c' => true, ]
     *   positional         →  [ 0 => 'positional', ]      (numeric key)
     *
     * It previously read ONLY `--key=value` and silently dropped everything else, so
     * a value-less `--run` never arrived and callers had to re-scan argv themselves
     * to find it. Value-less switches and space-separated values are the two
     * commonest CLI forms; dropping them pushed the same workaround into every tool
     * instead of solving it once, here.
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

        // Normalize arguments (convert hyphens to underscores)
        $args = Dj_App_Cli_Util::normalizeArgs($args);

        // Initialize with defaults
        $params = $expected_params;
        $known_params = array_keys($expected_params);
        $has_expected_params = !empty($expected_params);
        $help_args = [ '--help', '-h', '-help', 'help', ];
        $skip_positions = [];

        foreach ($args as $idx => $arg) {
            // Already consumed as the previous option's value
            if (isset($skip_positions[$idx])) {
                continue;
            }

            if (!is_scalar($arg)) {
                continue;
            }

            // Shell normally strips quotes; this handles re-quoted input.
            $arg = Dj_App_String_Util::trim($arg, "\"'");

            // '' and NOT empty(): a literal '0' is a legitimate positional arg and a
            // legitimate value in `--key 0`, and empty() would eat both.
            if ($arg === '') {
                continue;
            }

            // Skip help arguments - cheap check first
            if ((strpos($arg, 'h') !== false) && in_array($arg, $help_args)) {
                continue;
            }

            // Cheap single-char test before any substr(): most args are not options.
            if ($arg[0] !== '-') {
                $params[] = $arg;
                continue;
            }

            $is_long_option = isset($arg[1]) && $arg[1] === '-';
            $option_body = $is_long_option ? substr($arg, 2) : substr($arg, 1);
            $equals_pos = strpos($option_body, '=');
            $body_length = strlen($option_body);

            // -abc → three flags. SHORT options only, no '=', more than one char.
            $is_short_cluster = empty($is_long_option) && ($equals_pos === false) && ($body_length > 1);

            if ($is_short_cluster) {
                $flag_chars = str_split($option_body);

                foreach ($flag_chars as $flag_char) {
                    $params[$flag_char] = isset($params[$flag_char]) ? $params[$flag_char] : true;
                }

                continue;
            }

            if ($equals_pos !== false) {
                $key = substr($option_body, 0, $equals_pos);
                $value = substr($option_body, $equals_pos + 1);
            } else {
                $key = $option_body;
                $next_idx = $idx + 1;
                $next_arg = isset($args[$next_idx]) ? $args[$next_idx] : '';

                // '' not empty(): `--key 0` must keep the 0 rather than read --key as
                // a value-less flag. A next arg starting with '-' is the NEXT option.
                $next_is_value = is_scalar($next_arg) && ($next_arg !== '') && ($next_arg[0] !== '-');

                if ($next_is_value) {
                    $value = Dj_App_String_Util::trim($next_arg, "\"'");
                    $skip_positions[$next_idx] = true;
                } else {
                    $value = true;
                }
            }

            // Skip unknown keys if we have expected params
            if ($has_expected_params && !in_array($key, $known_params)) {
                continue;
            }

            // A repeated key COLLECTS rather than overwrites, so `--tag=a --tag=b`
            // keeps both. Guarded on the key not being an expected one, because a
            // DEFAULT must be replaced by the first real value, never appended to.
            $is_expected_key = in_array($key, $known_params);
            $has_prior_value = isset($params[$key]) && empty($is_expected_key);

            if ($has_prior_value) {
                $existing_values = (array) $params[$key];
                $existing_values[] = $value;
                $params[$key] = $existing_values;
                continue;
            }

            $params[$key] = $value;
        }

        return $params;
    }
}
