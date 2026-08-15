<?php

/**
 * Simple file logger. Writes timestamped, labelled lines to a dated dir under the app's private
 * data dir — getCorePrivateDataDir()/logs/Y/m/d. Static and always available, so any core or
 * plugin caller logs without wiring: Dj_App_Log::error($msg, $label). Non-scalar messages are
 * var_dump'd and lightly redacted (absolute dirs stripped) before they hit disk.
 */
class Dj_App_Log {
    private static $log_file = '';
    private static $logging_enabled = 1;
    private static $retry_attempts = 3;
    private static $retry_delay_ms = 250;

    // errno => label for the app error log. error_get_last() and the error handler both
    // hand over the raw errno under 'type', so fatals and warnings share ONE entry format.
    // An errno that is not listed falls back to the fatal label.
    const ERROR_TYPE_LABELS = [
        E_ERROR => 'Fatal Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_COMPILE_ERROR => 'Compile Error',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
    ];

    /**
     * The current log dir, date-nested: getCorePrivateDataDir()/logs/Y/m/d. Filterable.
     * Dj_App_Log::getCurrentLogDir();
     * @return string
     */
    public static function getCurrentLogDir() {
        $base_dir = Dj_App_Util::getCorePrivateDataDir() . '/logs';
        $base_dir = Dj_App_Hooks::applyFilter('app.core.log.dir', $base_dir);
        $date_rel_dir = date('Y/m/d');
        $dir = $base_dir . '/' . $date_rel_dir;

        return $dir;
    }

    /**
     * Sets or returns the current log file. Passing $file sets it; otherwise a cfg override
     * (app.core.log.file) wins, else a dated file under getCurrentLogDir().
     * Dj_App_Log::file();
     * @param string $file
     * @return string
     */
    public static function file($file = '') {
        if (!empty($file)) {
            self::$log_file = $file;

            return self::$log_file;
        }

        if (!empty(self::$log_file)) {
            return self::$log_file;
        }

        $cfg_file = Dj_App_Config::cfg('app.core.log.file');

        if (!empty($cfg_file)) {
            self::$log_file = $cfg_file;

            return self::$log_file;
        }

        $log_dir = Dj_App_Log::getCurrentLogDir();
        $log_file = $log_dir . '/app_' . date('Y-m-d') . '.log';
        self::$log_file = $log_file;

        return self::$log_file;
    }

    /**
     * Dj_App_Log::enableLogging();
     */
    public static function enableLogging() {
        self::$logging_enabled = 1;
    }

    /**
     * Dj_App_Log::disableLogging();
     */
    public static function disableLogging() {
        self::$logging_enabled = 0;
    }

    /**
     * Normalizes a message to a string: a non-scalar is var_dump'd.
     * @param string|mixed $msg
     * @return string
     */
    public static function prepMsg($msg) {
        if (is_scalar($msg)) {
            return $msg;
        }

        ob_start();
        var_dump($msg);
        $msg = ob_get_clean();

        return $msg;
    }

    /**
     * Strips absolute dirs (document root, the app private dir) and trims var_dump type noise, so
     * lines stay short and don't leak the filesystem layout.
     * @param string|mixed $buff
     * @return string
     */
    public static function removeNotEssentialStuff($buff) {
        $buff = is_scalar($buff) ? $buff : Dj_App_Log::prepMsg($buff);

        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $doc_root_dir = realpath($_SERVER['DOCUMENT_ROOT']);
            $doc_root_dir = str_replace('\\', '/', $doc_root_dir);

            if (!empty($doc_root_dir)) {
                $buff = str_replace($doc_root_dir, '', $buff);
            }
        }

        $private_dir = Dj_App_Util::getCorePrivateDir();

        if (!empty($private_dir)) {
            $buff = str_replace($private_dir, '', $buff);
        }

        // Compact var_dump type noise: `string(5) "x"` -> `"x"`, `int(3)` -> `3`, bools -> words.
        $buff = preg_replace('#\s*string\(\d+\)[^\S\r\n]*#s', '', $buff);
        $buff = preg_replace('#\s*int\((\d+)\)[^\S\r\n]*#s', '${1}', $buff);
        $buff = str_replace('bool(true)', 'true', $buff);
        $buff = str_replace('bool(false)', 'false', $buff);

        return $buff;
    }

    /**
     * Writes a timestamped, labelled line to the log file (creating the dated dir first), retrying
     * a few times if the write fails. Non-scalar $msg is dumped + redacted. When the file write
     * keeps failing, the line falls back to PHP's default error log so it is never lost.
     * Returns the line, or '' when the file write failed (the fallback doesn't count as success).
     * Dj_App_Log::msg($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @param string|array $file Target file — or the $extra_opts array (smart slot:
     *                           an array here IS the options, no '' placeholder needed;
     *                           error()/info()/warn() forward it, so they get it too).
     * @param array $extra_opts Optional. 'raw' => 1 writes $msg VERBATIM — no redaction,
     *                          no req_id, no timestamp/label prefix, no appended newline
     *                          (the caller owns the entry's formatting).
     *                          'file' => the target file — honored in either slot when
     *                          no explicit $file param is given.
     * @return string
     */
    public static function msg($msg, $label = '', $file = '', $extra_opts = []) {
        if (empty(self::$logging_enabled)) {
            return '';
        }

        // Smart 3rd slot: an array there is the options.
        if (is_array($file)) {
            $extra_opts = $file;
            $file = '';
        }

        // The options can carry the target file, too — works for both slots.
        // An explicit $file param wins over the options key.
        if (empty($file) && !empty($extra_opts['file'])) {
            $file = $extra_opts['file'];
        }

        $raw = !empty($extra_opts['raw']);

        if ($raw) {
            $line = $msg;
            $line_nl = $line;
        } else {
            $msg = Dj_App_Log::removeNotEssentialStuff($msg);
            $label = Dj_App_Log::removeNotEssentialStuff($label);

            // Decoupled: ask for a request id through a filter — the logger doesn't know who supplies
            // it. Dj_App_Request registers as the default supplier; a plugin can override.
            $req_id = Dj_App_Hooks::applyFilter('app.core.log.req_id', '');

            if (!empty($req_id) && (strpos($label, $req_id) === false)) {
                $label = empty($label) ? "req:$req_id" : "$label req:$req_id";
            }

            $timestamp = date('r');
            $label_prefix = empty($label) ? '' : "[$label] ";
            $line = "[$timestamp] " . $label_prefix . $msg;
            $line_nl = $line . "\n";
        }

        $file = empty($file) ? Dj_App_Log::file() : $file;

        if (!empty($file)) {
            $parent_dir = dirname($file);

            if (!is_dir($parent_dir)) {
                mkdir($parent_dir, 0770, true);
            }
        }

        $log_ok = false;

        for ($attempt = 1; $attempt <= self::$retry_attempts; $attempt++) {
            $log_ok = empty($file) ? error_log($line_nl) : error_log($line_nl, 3, $file);

            if ($log_ok) {
                break;
            }

            if ($attempt < self::$retry_attempts) {
                usleep(self::$retry_delay_ms * 1000);
            }
        }

        // The entry must never be lost: when the FILE write kept failing, fall back
        // to PHP's default error log. Last resort — nowhere further to report.
        if (empty($log_ok)) {
            if (!empty($file)) {
                error_log($line_nl);
            }

            return '';
        }

        return $line;
    }

    /**
     * Dj_App_Log::info($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @param string $file
     * @return string
     */
    public static function info($msg, $label = '', $file = '') {
        $msg = Dj_App_Log::prepMsg($msg);
        $msg = '[INFO] ' . $msg;

        return Dj_App_Log::msg($msg, $label, $file);
    }

    /**
     * Dj_App_Log::warn($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @param string $file
     * @return string
     */
    public static function warn($msg, $label = '', $file = '') {
        $msg = Dj_App_Log::prepMsg($msg);
        $msg = '[WARN] ' . $msg;

        return Dj_App_Log::msg($msg, $label, $file);
    }

    /**
     * Dj_App_Log::error($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @param string $file
     * @return string
     */
    public static function error($msg, $label = '', $file = '') {
        $msg = Dj_App_Log::prepMsg($msg);
        $msg = '[ERROR] ' . $msg;

        return Dj_App_Log::msg($msg, $label, $file);
    }

    /**
     * Appends one entry to the dated APP ERROR log (.ht_app_<date>.log under
     * getCurrentLogDir()), honoring app.error_logging and the app.error_log_file
     * override. Smart input — the caller passes what it HAS and this method owns
     * the formatting: a Throwable (bare, under an 'exception' array key, or
     * carried by a result obj), an error_get_last() style array, or an already
     * formatted string (written as-is). The error log keeps full file paths —
     * that is what a stack trace is for — so entries skip the redaction pipeline
     * (raw). The bootstrap's exception + fatal handlers both write through here,
     * so every failure kind lands in the SAME log.
     * Dj_App_Log::logAppError($exception);
     * @param Throwable|array|object|string $data
     * @return bool true when the entry was logged (the app error log, or PHP's
     *              default error log when the configured file is blank)
     */
    public static function logAppError($data) {
        $log_errors = Dj_App_Config::cfg('app.error_logging', true);

        // Critical facility: stays ON unless REALLY disabled (0/false/off/no) —
        // a blank or garbage value must not silently kill error logging.
        if (Dj_App_Util::isDisabled($log_errors)) {
            return false;
        }

        // A Throwable can arrive bare, under an 'exception' key, or in a result obj.
        $exception = null;

        if ($data instanceof Throwable) {
            $exception = $data;
        } elseif (is_array($data) && !empty($data['exception'])) {
            $exception = $data['exception'];
        } elseif (is_object($data) && !empty($data->exception)) {
            $exception = $data->exception;
        }

        $entry_body = '';

        if (!empty($exception)) {
            $entry_body = 'Exception: ' . $exception->getMessage() .
                ' in ' . $exception->getFile() . ' on line ' . $exception->getLine() .
                "\nStack trace:\n" . $exception->getTraceAsString();
        } elseif (is_array($data)) { // error_get_last() / error-handler shape
            $error_type = empty($data['type']) ? 0 : $data['type'];
            $error_label = empty(self::ERROR_TYPE_LABELS[$error_type]) ? 'Fatal Error' : self::ERROR_TYPE_LABELS[$error_type];

            $entry_body = $error_label . ': ' . $data['message'] .
                ' in ' . $data['file'] . ' on line ' . $data['line'];
        }

        if (empty($entry_body)) {
            $log_entry = $data; // already a formatted entry
        } else {
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] $entry_body\n" . str_repeat('-', 80) . "\n";
        }

        $log_dir = Dj_App_Log::getCurrentLogDir();
        $date_suff = date('Y-m-d');
        $log_file = $log_dir . "/.ht_app_{$date_suff}.log";
        $error_log_file = Dj_App_Config::cfg('app.error_log_file', $log_file);

        // A blanked app.error_log_file still logs — naked error_log() goes to
        // PHP's default log, so enabled logging never silently drops an entry.
        if (empty($error_log_file)) {
            $log_res = error_log($log_entry);

            return $log_res;
        }

        $written_line = Dj_App_Log::msg($log_entry, '', $error_log_file, [ 'raw' => 1, ]);
        $log_ok = !empty($written_line);

        return $log_ok;
    }

    /**
     * Writes to the shell stderr stream — CLI only; a no-op in web context (stderr is for CLI).
     * Dj_App_Log::stderr($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @return void
     */
    public static function stderr($msg = '', $label = '') {
        if (php_sapi_name() != 'cli') {
            return;
        }

        $output = is_scalar($msg) ? $msg : json_encode($msg, JSON_PRETTY_PRINT);

        if (!empty($label)) {
            $output = "[$label] $output";
        }

        fwrite(STDERR, $output . "\n");
    }

    /**
     * On-screen debug dump of a value. Returns the string when $print is false; when printing, only
     * emits on a dev/staging env or a dev IP, so a stray dump() can't leak in production.
     * Dj_App_Log::dump($data, $label);
     * @param mixed $data
     * @param string $label
     * @param bool $print
     * @return void|string
     */
    public static function dump($data, $label = '', $print = true) {
        $data = Dj_App_Log::prepMsg($data);
        $data = trim($data);
        $data = Dj_App_Log::removeNotEssentialStuff($data);

        if (empty($print)) {
            return $data;
        }

        $is_dev = Dj_App_Env::isDev() || Dj_App_Env::isStaging() || Dj_App_Env::isDevIP();

        if (empty($is_dev)) {
            return;
        }

        $label = empty($label) ? 'Data' : $label;
        $label_esc = htmlentities($label, ENT_QUOTES);
        $data_esc = htmlentities($data, ENT_QUOTES);

        $buff = '';
        $buff .= sprintf("<pre style='width:100%%;border:1px solid red;padding:10px 5px;'>%s</pre>", $data_esc);
        $buff .= sprintf(
            "<br/>%s: <textarea style='width:100%%;border:1px solid red;padding:10px 5px;' rows='5' readonly='readonly' onclick='this.select();'>%s</textarea>",
            $label_esc,
            $data_esc
        );

        echo $buff;
    }

    /**
     * Dumps $msg on-screen only when the request comes from a dev IP — a lighter guard than dump().
     * Dj_App_Log::debug($msg);
     * @param mixed $msg
     * @return void
     */
    public static function debug($msg) {
        if (!Dj_App_Env::isDevIP()) {
            return;
        }

        Dj_App_Log::dump($msg);
    }
}
