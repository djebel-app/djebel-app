<?php
/**
 * CLI utility class for command-line tools
 * Provides argument parsing and normalization
 */
class Dj_App_Cli_Util {
    // How long a run may take when the caller names no limit. 2 hours.
    const DEFAULT_TIME_LIMIT = 2 * 60 * 60;

    // The most a caller can ask for. Past this it is a daemon, not a CLI command.
    const MAX_TIME_LIMIT = 24 * 60 * 60;

    /**
     * Prepare the process for a long-running CLI command: unbuffered output, survive a
     * dead output reader, and a hard ceiling.
     *
     * Returns false on non-CLI rather than throwing, so a caller can invoke it blindly.
     *
     * @param array $params time_limit (seconds; 0/absent = DEFAULT_TIME_LIMIT, capped at
     *        MAX_TIME_LIMIT), ignore_user_abort (absent = on; pass 0 to stay abortable),
     *        display_errors (absent = 'stderr'; pass 0 to silence),
     *        report_all_errors (absent = off; on = E_ALL + log_errors)
     * @return bool true when applied, false when not running under CLI
     */
    static function init($params = []) {
        if (php_sapi_name() != 'cli') {
            return false;
        }

        // Errors to STDERR, because STDOUT carries the RESULT a caller pipes onward and
        // an error printed there corrupts it. Pass display_errors => 0 to silence them,
        // or any value php takes.
        $display_errors = 'stderr';

        if (array_key_exists('display_errors', $params)) {
            $display_errors = $params['display_errors'];
        }

        ini_set('display_errors', $display_errors);

        // How much to report, and whether to log it, is an environment decision — opt-in
        // rather than forced on every deployment.
        if (!empty($params['report_all_errors'])) {
            error_reporting(E_ALL);
            ini_set('log_errors', 1);
        }

        // Report progress as it happens: a buffered long job looks hung, then dumps
        // everything at the end. Usually the CLI default — guaranteed here.
        ini_set('implicit_flush', 1);
        ini_set('output_buffering', 0);
        ob_implicit_flush(true);

        // Only before output starts — setting it later warns into error_log, even with @.
        // Same guard as [Dj_App_Request::finishRequest].
        if (!headers_sent()) {
            ini_set('zlib.output_compression', 0);
        }

        // Keep running when the output reader goes away (`cmd | head`, a `tee` that
        // dies), instead of stopping halfway and leaving partial work behind.
        //
        // ⚠️ Signals are NOT affected — Ctrl+C, a closed terminal and SIGTERM all still
        // kill the process (verified). Outliving a hangup needs nohup/setsid/tmux.
        // On by default; pass ignore_user_abort => 0 to stay abortable.
        $ignore_abort = 1;

        if (array_key_exists('ignore_user_abort', $params)) {
            $ignore_abort = empty($params['ignore_user_abort']) ? 0 : 1;
        }

        if (!empty($ignore_abort)) {
            ignore_user_abort(true);
        }

        // Bound the run. CLI is unlimited by default, so an unattended job that wedges
        // (cron, a scheduler) sits there until someone notices.
        $time_limit = empty($params['time_limit']) ? self::DEFAULT_TIME_LIMIT : (int) $params['time_limit'];

        if ($time_limit > self::MAX_TIME_LIMIT) {
            $time_limit = self::MAX_TIME_LIMIT;
        }

        set_time_limit($time_limit);

        return true;
    }

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
