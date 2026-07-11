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
     * a few times if the write fails. Non-scalar $msg is dumped + redacted. Returns the line.
     * Dj_App_Log::msg($msg, $label);
     * @param string|mixed $msg
     * @param string $label
     * @param string $file
     * @return string
     */
    public static function msg($msg, $label = '', $file = '') {
        if (empty(self::$logging_enabled)) {
            return '';
        }

        $msg = Dj_App_Log::removeNotEssentialStuff($msg);
        $label = Dj_App_Log::removeNotEssentialStuff($label);

        // Decoupled: ask for a request id through a filter — the logger doesn't know who supplies
        // it. Dj_App_Request registers as the default supplier; a plugin can override.
        $req_id = Dj_App_Hooks::applyFilter('app.core.log.req_id', '');

        if (!empty($req_id) && (strpos($label, $req_id) === false)) {
            $label = empty($label) ? "req:$req_id" : "$label req:$req_id";
        }

        $file = empty($file) ? Dj_App_Log::file() : $file;

        if (!empty($file)) {
            $parent_dir = dirname($file);

            if (!is_dir($parent_dir)) {
                mkdir($parent_dir, 0770, true);
            }
        }

        $timestamp = date('r');
        $label_prefix = empty($label) ? '' : "[$label] ";
        $line = "[$timestamp] " . $label_prefix . $msg;
        $line_nl = $line . "\n";

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
