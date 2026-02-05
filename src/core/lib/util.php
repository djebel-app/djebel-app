<?php

class Dj_App {
    const NAME = 'Djebel';
    const VERSION = '0.0.1';
    const SITE_URL = 'https://djebel.com';

    /**
     * Exit the application
     * Proxy to PHP exit() for testability and future hooks
     *
     * @param array $params Optional parameters
     *   - code: Exit code (default: 0)
     *   - message: Optional message to output before exit
     * @return void
     */
    public static function exit($params = [])
    {
        $code = empty($params['code']) ? 0 : (int) $params['code'];
        $message = empty($params['message']) ? '' : $params['message'];

        if (!empty($message)) {
            echo $message;
        }

        $ctx = [
            'code' => $code,
            'message' => $message,
        ];

        Dj_App_Hooks::doAction('app.exit', $ctx);

        exit($code);
    }
}

class Dj_App_Util {
    static $times = [];

    const INJECT_BEFORE = 1;
    const INJECT_AFTER = 2;

    // Add this near other static properties
    protected static $registry = [];

    /**
     * Returns the start time when an operation takes place so you can later do a delta.
     *
     * If it's called twice with the same parameter.
     * The second time it will return the time delta.
     * Smart, eh?
     *
     * Usage 1:
     * $time_ms = Dj_App_Util::microtime();
     * sprintf( "%.02f", abs( $time_ms - Dj_App_Util::microtime() ) );
     *
     * Usage 2:
     * Dj_App_Util::microtime( 'setup_vhost' );
     * ......
     * $time_delta = Dj_App_Util::microtime( 'setup_vhost' );
     *
     * if you don't want the time formatted (to 2 decimals) pass 0 as 2nd param.
     * $time_delta = Dj_App_Util::microtime( 'setup_vhost', 0 );
     * sprintf( "%.02f", $time_delta );
     *
     * @param string $marker optional
     * @return float|string
     */
    public static function microtime($marker = '', $fmt = 1, $precision = 6)
    {
        if (!is_scalar($marker)) {
            $marker = serialize($marker);
            $marker_hash = sha1($marker);
            $marker = substr($marker_hash, 0, 8);
        }

        $marker = substr($marker, 0, 100);
        $len = strlen($marker);

        $result = '';
        $last_was_underscore = false;
        
        // Convert non-alphanumeric chars to underscores
        for ($i = 0; $i < $len; $i++) {
            $char = $marker[$i];

            if (ctype_alnum($char)) {
                $result .= $char;
                $last_was_underscore = false;
            } elseif (!$last_was_underscore) { // ... avoid consecutive underscores
                $result .= '_';
                $last_was_underscore = true;
            }
        }
        
        $marker = $result;
        
        $marker = trim($marker, '_');
        $marker = strtolower($marker);

        // calc diff from another timestamp
        if (!empty($marker) && is_numeric($marker) && $marker > 100000) {
            $inp_time_ms = floatval($marker);

            list ( $usec, $sec ) = explode( " ", microtime() );
            $time_ms = ( (float) $usec ) + ( (float) $sec );

            $diff = $time_ms - $inp_time_ms;
            $diff = abs($diff); // jic
            $diff = sprintf("%.0{$precision}f", $diff);
            return $diff;
        }

        list ( $usec, $sec ) = explode( " ", microtime() );
        $inp_time_ms = ( (float) $usec ) + ( (float) $sec );

        $marker = empty($marker) ? __METHOD__ : $marker;
        $marker = is_scalar($marker) ? $marker : substr(sha1(serialize($marker)), 0, 10);

        // just log the value and bye
        if (empty(self::$times[$marker])) {
            self::$times[$marker] = $inp_time_ms;
            return $inp_time_ms;
        }

        $diff = $inp_time_ms - self::$times[$marker];
        $diff = abs($diff); // jic
        $diff = sprintf("%.0{$precision}f", $diff);

        return $diff;
    }

    /**
     * Generate SHA1-based hash (optionally partial, defaults to 12 chars)
     *
     * @param mixed $data
     * @param int $length Desired length (1-40); defaults to full SHA1
     * @return string
     */
    public static function generateHash($data = '', $length = 12)
    {
        $data_str = '';

        if (empty($data)) {
            $data = [];
            $data['t'] = self::microtime(true);
            $data['r'] = mt_rand(999, 99999);
            $data['_env'] = $_ENV;
            $data['_server'] = $_SERVER;
        }

        $filter_ctx = [ 'length' => $length, ];
        $data = Dj_App_Hooks::applyFilter('app.util.generate_hash.data', $data, $filter_ctx);

        if (is_scalar($data)) {
            $data_str = $data;
        } elseif (is_array($data)) {
            $normalized = self::normalizeForSerialization($data);
            ksort($normalized); // ensure consistent keys order
        } else {
            $data_str = self::serialize($data);
        }

        $hash = sha1($data_str);
        $length = (int) $length;

        if ($length <= 0 || $length >= 40) {
            return $hash;
        }

        $hash = substr($hash, 0, $length);

        return $hash;
    }

    /**
     * Timezone-aware time() - returns current Unix timestamp in configured timezone
     * Falls back to server timezone if not configured in site.timezone
     *
     * Usage:
     * $timestamp = Dj_App_Util::time();
     *
     * @return int Unix timestamp
     */
    public static function time()
    {
        $timezone = self::getTimezone();

        if ($timezone) {
            $dt = new DateTime('now', $timezone);
            return $dt->getTimestamp();
        }

        return time();
    }

    /**
     * Timezone-aware strtotime() - converts string to Unix timestamp in configured timezone
     * Falls back to server timezone if not configured in site.timezone
     *
     * Usage:
     * $timestamp = Dj_App_Util::strtotime('2025-01-01 12:00:00');
     * $timestamp = Dj_App_Util::strtotime('+1 day');
     *
     * @param string $datetime Date/time string to parse
     * @param int|null $base_timestamp Optional base timestamp (default: current time)
     * @return int|false Unix timestamp or false on failure
     */
    public static function strtotime($datetime, $base_timestamp = null)
    {
        if (empty($datetime)) {
            return false;
        }

        $result = false;
        $timezone = self::getTimezone();

        try {
            if ($timezone) {
                // Create base DateTime in configured timezone
                if (is_null($base_timestamp)) {
                    $dt = new DateTime('now', $timezone);
                } else {
                    $dt = new DateTime('@' . $base_timestamp);
                    $dt->setTimezone($timezone);
                }

                // Modify with the datetime string
                $dt->modify($datetime);
                return $dt->getTimestamp();
            }

            // Fallback to PHP's strtotime
            $result = is_null($base_timestamp) ? strtotime($datetime) : strtotime($datetime, $base_timestamp);
        } catch (Exception $e) {
            // relax
        }

        return $result;
    }

    /**
     * Get configured timezone or server default
     * Returns DateTimeZone object or null if using server default
     *
     * @return DateTimeZone|null
     */
    protected static function getTimezone()
    {
        static $timezone = false;

        if ($timezone !== false) {
            return $timezone;
        }

        $options_obj = Dj_App_Options::getInstance();
        $timezone_str = $options_obj->get('site.timezone');

        if (empty($timezone_str)) {
            $timezone = null;
            return $timezone;
        }

        try {
            $timezone = new DateTimeZone($timezone_str);
            return $timezone;
        } catch (Exception $e) {
            $timezone = null;
            return $timezone;
        }
    }

    /**
     * That's dj-content
     * Dj_App_Util::getContentDirName();
     * @return string
     */
    public static function getContentDirName()
    {
        $dir = 'dj-content';
        $dir = Dj_App_Hooks::applyFilter( 'app.config.content_dir_name', $dir );
        return $dir;
    }

    /**
     * That's the folder that contains /plugins and /themes/ and files/
     * Dj_App_Util::getContentDir();
     * @return string
     */
    public static function getContentDir()
    {
        $dir = Dj_App_Env::getEnvConst('DJEBEL_APP_CONTENT_DIR');

        if (empty($dir)) {
            $app_base_dir = Dj_App_Env::getEnvConst('DJEBEL_APP_CONTENT_PARENT_DIR');

            if (empty($app_base_dir)) {
                $app_base_dir = Dj_App_Util::getScriptDir();
            }

            if (empty($app_base_dir)) {
                $app_base_dir = Dj_App_Util::getDocRootDir();
            }

            $app_base_dir = empty($app_base_dir) ? Dj_App_Config::cfg(Dj_App_Config::APP_BASE_DIR) : $app_base_dir;
            $dir = $app_base_dir . '/' . Dj_App_Util::getContentDirName();
        }

        $dir = Dj_App_Hooks::applyFilter( 'app.config.content_dir', $dir );
        $dir = self::removeSlash($dir);
        return $dir;
    }

    /**
     * Get public content data directory with optional plugin/theme parameter
     * Creates organized directories for plugins in the public dj-content/data/app/ area
     * Similar to getCorePrivateDataDir but for public content
     *
     * Usage:
     * Dj_App_Util::getContentDataDir(['plugin' => 'my-plugin']);
     * Returns: /path/to/site/dj-content/data/app/plugins/my-plugin
     *
     * @param array $params Optional parameters (plugin or theme)
     * @return string The content data directory path
     */
    public static function getContentDataDir($params = [])
    {
        $dir = Dj_App_Util::getContentDir();

        if (empty($dir)) {
            return '';
        }

        $dir .= '/data/app';

        $ctx = [];
        $dir = Dj_App_Hooks::applyFilter('app.config.content_data_dir', $dir, $ctx);

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/plugins/' . $slug;
            $dir = Dj_App_Hooks::applyFilter('app.config.content_data_plugin_dir', $dir, $ctx);
        } elseif (!empty($params['theme'])) {
            $slug = $params['theme'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/themes/' . $slug;
            $dir = Dj_App_Hooks::applyFilter('app.config.content_data_theme_dir', $dir, $ctx);
        }

        return $dir;
    }

    /**
     * Gets the content directory URL (site URL + content dir name)
     * Dj_App_Util::getContentDirUrl();
     *
     * Example: https://example.com/dj-content
     *
     * @return string Content directory URL
     */
    public static function getContentDirUrl()
    {
        static $content_dir_url = null;

        if (!is_null($content_dir_url)) {
            return $content_dir_url;
        }

        $req_obj = Dj_App_Request::getInstance();
        $site_url = Dj_App_Util::removeSlash($req_obj->getSiteUrl());
        $content_dir_name = Dj_App_Util::getContentDirName();

        $url_parts = [$site_url];
        $url_parts[] = $content_dir_name;
        $content_dir_url = implode('/', $url_parts);

        return $content_dir_url;
    }

    /**
     * Dj_App_Util::getDocRootDir();
     * @return string
     */
    public static function getDocRootDir()
    {
        $site_doc_root = empty($_SERVER['DOCUMENT_ROOT']) ? '' : $_SERVER['DOCUMENT_ROOT'];
        $site_doc_root = Dj_App_Config::cfg('app_doc_root_dir', $site_doc_root);
        $site_doc_root = Dj_App_Hooks::applyFilter( 'app.config.doc_root', $site_doc_root );
        $site_doc_root = self::removeSlash($site_doc_root);
        return $site_doc_root;
    }

    /**
     * Dj_App_Util::getScriptDir();
     * @return string
     */
    public static function getScriptDir()
    {
        if (empty($_SERVER['SCRIPT_FILENAME'])) { // apache
            return '';
        }

        $dir = dirname($_SERVER['SCRIPT_FILENAME']);

        return $dir;
    }

    /**
     * Gets the folder that's right above the document root or the script
     * Dj_App_Util::getSiteRootDir();
     * @return string
     */
    public static function getSiteRootDir()
    {
        $site_root_dir = Dj_App_Env::getEnvConst('DJEBEL_APP_SITE_ROOT_DIR');

        if (empty($site_root_dir)) {
            $site_doc_root = Dj_App_Util::getScriptDir();

            if (empty($site_doc_root)) {
                $site_doc_root = Dj_App_Util::getDocRootDir();
            }

            $site_root_dir = dirname($site_doc_root); // 1 level up usually!
            $site_root_dir = Dj_App_Config::cfg('app_site_root_dir', $site_root_dir);
        }

        $site_root_dir = Dj_App_Hooks::applyFilter( 'app.config.site_root_dir', $site_root_dir );
        $site_root_dir = self::removeSlash($site_root_dir);
        return $site_root_dir;
    }

    /**
     * This folder is supposed to reside outside of the public html folder for security reasons.
     * it can have these folders
     * .ht_djebel/conf
     * .ht_djebel/logs
     * .ht_djebel/data
     * Dj_App_Util::getCorePrivateDir();
     * @param array $params
     * @return string
     */
    public static function getCorePrivateDir($params = [])
    {
        static $dir = null;

        if (!is_null($dir)) {
            return $dir;
        }

        $dir = Dj_App_Env::getEnvConst('DJEBEL_APP_PRIVATE_DIR');

        if (empty($dir)) {
            $priv_dir_name = Dj_App_Config::cfg('app.core.private_dir_name', '.ht_djebel');
            $script_dir = Dj_App_Util::getScriptDir();

            // Scan from script dir up to find private dir (handles symlinks)
            if (!empty($script_dir)) {
                $check_dirs = [
                    $script_dir => 1,              // Script dir itself
                    dirname($script_dir) => 1,     // One level up (site root)
                    dirname($script_dir, 2) => 1,  // Two levels up
                ];

                foreach ($check_dirs as $candidate_dir => $enabled) {
                    if (empty($enabled)) {
                        continue;
                    }

                    $check_path = $candidate_dir . '/' . $priv_dir_name;

                    if (is_dir($check_path)) {
                        $dir = $check_path;
                        break;
                    }
                }
            }

            // Fallback to original hardcoded path
            if (empty($dir)) {
                $dir = Dj_App_Util::getSiteRootDir() . '/' . $priv_dir_name;
            }
        }

        // Resolve $HOME, ~/, symlinks etc.
        $dir = Dj_App_File_Util::resolvePath($dir);
        $dir = Dj_App_Hooks::applyFilter( 'app.config.djebel_private_dir', $dir );

        return $dir;
    }

    /**
     * @param array $params
     * @return string
     */
    public static function getCorePrivateDataDir($params = [])
    {
        $dir = Dj_App_Util::getCorePrivateDir();

        if (empty($dir)) {
            return '';
        }

        $dir .= '/data/app';

        $ctx = [];
        $dir = Dj_App_Hooks::applyFilter( 'app.config.djebel_private_data_dir', $dir, $ctx );

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/plugins/' . $slug;
            $dir = Dj_App_Hooks::applyFilter( 'app.config.djebel_private_data_plugin_dir', $dir, $ctx );
        } else if (!empty($params['theme'])) {
            $slug = $params['theme'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/themes/' . $slug;
            $dir = Dj_App_Hooks::applyFilter( 'app.config.djebel_private_data_theme_dir', $dir, $ctx );
        }

        return $dir;
    }

    /**
     * Get core cache directory with optional plugin parameter
     * @param array $params
     * @return string
     */
    public static function getCoreCacheDir($params = [])
    {
        $dir = Dj_App_Util::getCorePrivateDir();

        if (empty($dir)) {
            return '';
        }

        $dir .= '/data/cache';

        $ctx = [];
        $dir = Dj_App_Hooks::applyFilter('app.config.djebel_cache_dir', $dir, $ctx);

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/plugins/' . $slug;
            $dir = Dj_App_Hooks::applyFilter('app.config.djebel_cache_plugin_dir', $dir, $ctx);
        }

        return $dir;
    }

    /**
     * Get core temporary directory with optional plugin parameter
     * @param array $params
     * @return string
     */
    public static function getCoreTempDir($params = [])
    {
        $dir = Dj_App_Util::getCorePrivateDir();

        if (empty($dir)) {
            return '';
        }

        $dir .= '/data/tmp';

        $ctx = [];
        $dir = Dj_App_Hooks::applyFilter('app.config.djebel_temp_dir', $dir, $ctx);

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/plugins/' . $slug;
            $dir = Dj_App_Hooks::applyFilter('app.config.djebel_temp_plugin_dir', $dir, $ctx);
        }

        return $dir;
    }

    /**
     * @param array $params
     * @return string
     */
    public static function getCoreConfDir($params = [])
    {
        $config_dir_env = Dj_App_Env::getEnvConst('DJEBEL_APP_CONF_DIR');

        if (!empty($config_dir_env)) {
            return $config_dir_env;
        }

        $config_dir = Dj_App_Config::cfg('app.core.conf_dir');

        if (!empty($config_dir)) {
            return $config_dir;
        }

        $config_dir = Dj_App_Util::getCorePrivateDir() . '/conf';
        $config_dir = Dj_App_Hooks::applyFilter( 'app.config.djebel_conf_dir', $config_dir );

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $config_dir .= '/plugins/' . $slug;
            $ctx = [ 'plugin' => $slug, ];
            $config_dir = Dj_App_Hooks::applyFilter( 'app.config.plugin_conf_dir', $config_dir, $ctx );
        }

        return $config_dir;
    }

    /**
     * Prints output and stops processing.
     * Dj_App_Util::die('Error message', 'Error title', ['code' => 500]);
     * @param string $content
     * @param string $title
     * @param array $args
     * @return void
     */
    public static function die($content, $title = '', $args = []) {
        $code = empty($args['code']) ? 500 : (int) $args['code'];
        $content = Dj_App_Hooks::applyFilter('app.page.die.content', $content);
        
        $options = [
            'status_code' => $code,
        ];

        Djebel_App_HTML::renderPage($content, $title ?: 'Application Error', $options);
    }

    /**
     * Returns the times that were logged.
     * @return array
     */
    public static function getTimes() {
        return self::$times;
    }

    /**
     * Dj_App_Util::getAdminDir();
     * @return string
     */
    public static function getAdminDir()
    {
        $dir = Dj_App_Env::getEnvConst('DJEBEL_APP_CORE_DIR') . '/admin';
        $dir = Dj_App_Env::getEnvConst('DJEBEL_APP_ADMIN_DIR', $dir);
        $dir = Dj_App_Hooks::applyFilter( 'app.admin.dir', $dir );
        return $dir;
    }

    /**
     * Dj_App_Util::sortByPriority();
     * Sorts two arrays by priority. Could be used for tabs, plugins, etc.
     * @param array $a
     * @param array $b
     * @return int
     * @throws Dj_App_Exception
     */
    // create a static function to sort by priority.
    public static function sortByPriority( $a, $b ) {
        if (!is_array($a) || !is_array($b)) {
            throw new Dj_App_Exception('Both sort arguments must be arrays');
        }

        $a_prio = 50;
        $b_prio = 50;

        if (isset($a['priority'])) {
            $a_prio = $a['priority'];
        } elseif (isset($a['load_priority'])) {
            $a_prio = $a['load_priority'];
        }

        if (isset($b['priority'])) {
            $b_prio = $b['priority'];
        } elseif (isset($b['load_priority'])) {
            $b_prio = $b['load_priority'];
        }

        $a_prio = (int) $a_prio;
        $b_prio = (int) $b_prio;

        return $a_prio - $b_prio;
    }


    /**
     *
     * Receives a small buffer and parses meta info in the plugin or theme file's header.
     * Dj_App_Util::extractMetaInfo()
     * @param string $buff
     * @return Dj_App_Result
     */
    public static function extractMetaInfo($buff)
    {
        $res_obj = new Dj_App_Result();
        Dj_App_Util::microtime( __METHOD__ );

        try {
            if (empty($buff)) {
                throw new Dj_App_Exception('Empty buffer.');
            }

            $small_buff = substr($buff, 0, 50);

            if (strpos($small_buff, ':') === false) {
                throw new Dj_App_Exception('Missing field separator : in meta data');
            }

            $meta = [];
            $lines = explode("\n", $buff);
            $lines = array_filter($lines);

            foreach ($lines as $line) {
                $line = Dj_App_String_Util::trim($line);

                if (empty($line)) {
                    continue;
                }

                $first_char = substr($line, 0, 1);

                // Let's get the last chars and remove any leading/trailing spaces to see if it's a ;
                // if it is that means that the code starts and we need to stop processing the meta info.
                $last_chars = substr($line, -2);
                $last_chars = trim($last_chars);

                // skip comments, empty lines and non-alphabetic lines
                if ($first_char == '#'
                    || $first_char == ';'
                    || $last_chars == '*/' // closing comment
                    || !ctype_alpha($first_char)
                    || ($first_char == '/' && in_array(substr($line, 1, 1), ['/', '*']))
                ) {
                    continue;
                }

                // Find position of first colon
                $pos = strpos($line, ':');

                if ($pos === false) {
                    continue;
                }

                // We're done processing the meta info
                if ($last_chars == ';' // code?
                    || (stripos($line, 'addFilter(') !== false)
                    || (stripos($line, 'addAction(') !== false)
                    || (stripos($line, 'define(') !== false)
                ) {
                    break;
                }

                $key = substr($line, 0, $pos);
                $value = substr($line, $pos + 1);

                $key = Dj_App_String_Util::formatKey($key);

                // Handle array notation: [item1, item2, item3]
                $val_had_brackets = strpos($value, '[') !== false;
                $value = Dj_App_String_Util::trim($value, '[]');

                if ($val_had_brackets) {
                    if (strpos($value, ',') !== false) {
                        $items = explode(',', $value);
                        $items = Dj_App_String_Util::trim($items, '\'"');
                        $value = array_filter($items);
                    } else {
                        $value = empty($value) ? [] : (array) $value;
                    }
                }

                $meta[$key] = $value;
            }

            $meta['type']  = '';

            if (!empty($meta['plugin_name'])) {
                $meta['type'] = 'plugin';
            } else if (!empty($meta['theme_name'])) {
                $meta['type'] = 'theme';
            }

            $res_obj->status(true);
            $res_obj->data($meta);
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
        } finally {
            $res_obj->exec_time = Dj_App_Util::microtime( __METHOD__ );
        }

        return $res_obj;
    }

    const MSG_ERROR = 0;
    const MSG_SUCCESS = 1;
    const MSG_NOTICE = 2;

    /**
     * QS_Site_App_Util::msg();
     * QS_Site_App_Util::msg('', QS_Site_App_Util::MSG_ERROR);
     * QS_Site_App_Util::msg('', QS_Site_App_Util::MSG_SUCCESS);
     * QS_Site_App_Util::msg('', QS_Site_App_Util::MSG_NOTICE);
     * a simple status message, no formatting except color
     */
    public static function msg($msg, $status = self::MSG_ERROR, $use_inline_css = 0) {
        $id = 'dj-app';
        $cls = $extra = $inline_css = $extra_attribs = '';

        $msg = is_scalar($msg) ? $msg : join("\n<br/>", $msg);
        $icon = 'exclamation-sign';

        if ( $status === 2 || $status == self::MSG_NOTICE) { // notice
            $cls = 'dj-app-info alert alert-info';
        } elseif ( $status === 6 ) { // dismissable notice
            $cls = 'dj-app-info alert alert-danger alert-dismissable';
            $extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false"><span aria-hidden="true">&times;</span><span class="__sr-only">Close</span></button>';
            //$extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false">X</button>';
        } elseif ( $status === 4 ) { // dismissable notice
            $cls = 'dj-app-info alert alert-info alert-dismissable';
            $extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false"><span aria-hidden="true">&times;</span><span class="__sr-only">Close</span></button>';
            //$extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false">X</button>';
        } elseif ( $status == 0 || $status === false || $status === self::MSG_ERROR ) {
            $cls = 'dj-app-error alert alert-danger';
            $icon = 'remove';
        } elseif ( $status == 1 || $status === true || $status === self::MSG_SUCCESS ) {
            $cls = 'dj-app-success alert alert-success';
            $icon = 'ok';
        }

        if (is_array($use_inline_css)) {
            $extra_attribs = self::convertArrayToDataAttr($use_inline_css);
        } elseif (!empty($use_inline_css)) {
            $inline_css = empty($status) ? 'background-color:red;' : 'background-color:green;';
            $inline_css .= 'text-align:center;margin-left: auto; margin-right:auto; padding-bottom:10px;color:white;';
        }

        $msg_icon = "<span class='glyphicon glyphicon-$icon' aria-hidden='true'></span>";
        $msg = $msg_icon . ' ' . $msg;

        $str = <<<MSG_EOF
<div id='$id-notice' class='$cls' style="$inline_css" $extra_attribs>$msg $extra</div>
MSG_EOF;
        return $str;
    }

    /**
     * Convert array to HTML5 data attributes
     * Dj_App_Util::convertArrayToDataAttr(['user_id' => '123', 'action' => 'delete'])
     * Returns: data-user-id="123" data-action="delete"
     *
     * @param array $data
     * @return string
     */
    public static function convertArrayToDataAttr($data) {
        if (empty($data) || !is_array($data)) {
            return '';
        }

        $attributes = [];

        foreach ($data as $key => $value) {
            $key = Dj_App_String_Util::formatKey($key);
            $key = str_replace('_', '-', $key);
            $value_esc = dj_esc_attr($value);
            $attributes[] = "data-{$key}=\"{$value_esc}\"";
        }

        $result = implode(' ', $attributes);

        return $result;
    }

    /**
     * Quick check if it's an HTML for some tags
     * Dj_App_Util::isHTML($buff);
     * @param string $buff
     * @return bool
     */
    public static function isHTML($buff) {
        $small_buff = substr($buff, 0, 1 * 1024); // first ? KB

        if (stripos($small_buff, '<html') !== false) {
            return true;
        }

        if (stripos($small_buff, '<head') !== false) {
            return true;
        }

        $small_buff = substr($buff, 0, 50 * 1024); // first ? KB

        if (stripos($small_buff, '<body') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Runs some hooks in case the theme didn't run them and inject the content automatically.
     * Dj_App_Util::autoInjectSysHookContent($buff);
     * @param string $buff
     * @return string
     */
    public static function autoInjectSysHookContent($buff)
    {
        if (!Dj_App_Util::isHTML($buff)) {
            return $buff;
        }

        $auto_inject_hook_content = [
            'app.page.html.head' => '</head>',
            'app.page.html.body.start' => '<body',
            'app.page.html.body.end' => '</body>',
        ];

        foreach ($auto_inject_hook_content as $hook => $content) {
            if (Dj_App_Hooks::hasRun($hook)) {
                continue;
            }

            $hook_content = Dj_App_Hooks::captureHookOutput($hook);

            if (empty($hook_content)) {
                continue;
            }

            $new_buff = Dj_App_Util::injectContent($hook_content, $buff, $content);

            if (!empty($new_buff)) {
                $buff = $new_buff;
            }
        }

        return $buff;
    }

    /**
     * Injects body classes into the buffer.
     * Dj_App_Util::injectBodyClasses($buff);
     * @param string $buff
     * @return string
     */
    public static function injectBodyClasses($buff) {
        if (!Dj_App_Util::isHTML($buff)) {
            return $buff;
        }

        $body_start_pos = stripos($buff, '<body');

        if ($body_start_pos === false) {
            return $buff;
        }

        $body_end_pos = stripos($buff, '>', $body_start_pos);

        // this contains just the <body ...> tag
        $body_chunk = substr($buff, $body_start_pos, $body_end_pos - $body_start_pos + 1);

        // Initialize body classes array
        $body_classes = [];

        // First try to find class attribute directly
        $class_start_pos = stripos($body_chunk, 'class=');

        if ($class_start_pos !== false) {
            // Found class attribute, extract using string functions
            // Move position after 'class=' (6 chars: 5 for 'class' + 1 for '=')
            $pos_after_equals = $class_start_pos + 6;
            $next_char = substr($body_chunk, $pos_after_equals, 1);
            
            // Handle quoted and unquoted class values
            if ($next_char === '"' || $next_char === "'") {
                // For quoted values: skip quote char and find matching end quote
                $class_end_pos = strpos($body_chunk, $next_char, $pos_after_equals + 1);
                $class_start_offset = $pos_after_equals + 1;
                $removal_end = $class_end_pos + 1; // Include the closing quote
            } else {
                // For unquoted values: find next space or closing bracket
                $class_end_pos = strpos($body_chunk, ' ', $pos_after_equals);

                if ($class_end_pos === false) {
                    $class_end_pos = strpos($body_chunk, '>', $pos_after_equals);
                }

                $class_start_offset = $pos_after_equals;
                $removal_end = $class_end_pos;
            }
            
            if ($class_end_pos !== false) {
                $existing_classes = substr($body_chunk, $class_start_offset, $class_end_pos - $class_start_offset);
                $existing_classes = explode(' ', $existing_classes);
                $body_classes = $existing_classes;
                
                // Remove just the class="..." attribute, preserving other attributes
                $removal_start = $class_start_pos - 1; // Include the space before class=
                $buff = substr($buff, 0, $body_start_pos + $removal_start) . 
                        substr($buff, $body_start_pos + $removal_end);
            }
        }

        $req_obj = Dj_App_Request::getInstance();
        $req_url = $req_obj->getCleanRequestUrl();
        $req_url = trim($req_url, '/'); // remove leading/trailing slashes so we don't end up with empty elements later

        // the $req_url should be look like e.g. contact or services or products/product123
        if (!empty($req_url)) {
            $page_slugs = [];
            $page_slugs = explode('/', $req_url);
            $page_slugs[] = 'dj-app-page-body'; // Add our new classes after collecting all others
            $page_slugs[] = $req_url; // let's put the full link too and not just its parts

            foreach ($page_slugs as $page_slug) {
                $page_slug = 'dj-app-page-' . $page_slug;
                $page_slug = Dj_App_String_Util::formatStringId($page_slug, Dj_App_String_Util::FORMAT_CONVERT_TO_DASHES);
                $body_classes[] = $page_slug;
            }
        }

        $body_classes = array_filter($body_classes); // rm empty elements just in case
        $body_classes = array_unique($body_classes); // everybody is unique
        natsort($body_classes);

        $body_classes = Dj_App_Hooks::applyFilter("app.page.body_class", $body_classes);

        if (empty($body_classes)) {
            return $buff;
        }

        // Replace <body with new class attribute
        $body_class_str = implode(' ', $body_classes);
        $replace_body_with_classes = sprintf('<body class="%s"', $body_class_str);
        $buff = str_ireplace('<body', $replace_body_with_classes, $buff);

        return $buff;
    }

    /**
     * Recursively converts data to arrays.
     * Supports only: scalars, arrays, and objects with public properties
     * All empty values and unsupported types become empty arrays
     * Dj_App_Util::toArray();
     * @param mixed $data The data to convert
     * @return array
     */
    public static function toArray($data) 
    {
        $converter = function($data, $depth = 0) use (&$converter) {
            static $processed_objects = [];
            
            if ($depth > 100) {
                return [];
            }

            // Handle empty values consistently
            if (empty($data)) {
                return [];
            }

            // Handle scalar values as single-element arrays
            if (is_scalar($data)) {
                return [$data];
            }

            // Handle arrays recursively
            if (is_array($data)) {
                $result = [];
                foreach ($data as $key => $value) {
                    $safe_key = is_string($key) ? $key : (string)$key;
                    $result[$safe_key] = $converter($value, $depth + 1);
                }
                return $result;
            }

            // Handle objects with public properties
            if (is_object($data)) {
                $hash = spl_object_hash($data);
                if (isset($processed_objects[$hash])) {
                    return [];
                }
                $processed_objects[$hash] = true;

                $result = [];
                $reflection = new ReflectionObject($data);
                $props = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
                
                foreach ($props as $prop) {
                    $prop_name = $prop->getName();
                    if (!is_callable($data->$prop_name)) {
                        $result[$prop_name] = $converter($data->$prop_name, $depth + 1);
                    }
                }
                
                return $result;
            }

            // Everything else becomes an empty array
            return [];
        };

        return $converter($data);
    }

    const FLAG_LEADING = 2;
    const FLAG_TRAILING = 4;
    const FLAG_BOTH = self::FLAG_LEADING | self::FLAG_TRAILING;

    /**
     * Dj_App_Util::addSlash();
     * @param string $url
     * @param int $flags FLAG_LEADING and/or FLAG_TRAILING (default: FLAG_TRAILING)
     * @return string
     */
    static public function addSlash($url, $flags = self::FLAG_TRAILING)
    {
        if (empty($url) || empty($flags)) {
            return empty($url) ? '/' : $url;
        }

        // Add leading slash
        if ($flags & self::FLAG_LEADING) {
            $first_char = substr($url, 0, 1);
            
            if ($first_char != '/') {
                $url = '/' . $url;
            }
        }

        // Add trailing slash
        if ($flags & self::FLAG_TRAILING) {
            $last_char = substr($url, -1);
            
            if ($last_char != '/') {
                $url .= '/';
            }
        }

        return $url;
    }

    /**
     * Dj_App_Util::removeSlash();
     * @param string $url
     * @param int $flags FLAG_LEADING and/or FLAG_TRAILING (default: FLAG_TRAILING)
     * @return string
     */
    static public function removeSlash($url = '', $flags = self::FLAG_TRAILING) {
        if (empty($url) || empty($flags)) {
            return empty($url) ? '' : $url;
        }

        // Remove leading slashes
        if ($flags & self::FLAG_LEADING) {
            $first_char = substr($url, 0, 1);

            if ($first_char == '/' || $first_char == '\\') {
                $url = ltrim($url, '/\\');
            }
        }

        // Remove trailing slashes
        if ($flags & self::FLAG_TRAILING) {
            $last_char = substr($url, -1);

            if ($last_char == '/' || $last_char == '\\') {
                $url = rtrim($url, '/\\');
            }
        }

        return $url;
    }

    /**
     * Injects content relative to a specified HTML tag in the buffer.
     * Automatically detects injection position based on tag type:
     * - Closing tags (</tag>): injects before
     * - Opening tags (<tag>): injects after
     * 
     * Usage:
     * ```php
     * // Auto-detects to inject before </body>
     * $buff = Dj_App_Util::injectContent(
     *     '<script>console.log("hello")</script>',
     *     $buff,
     *     '</body>'
     * );
     * 
     * // Auto-detects to inject after <body
     * $buff = Dj_App_Util::injectContent(
     *     '<div class="wrapper">',
     *     $buff,
     *     '<body'
     * );
     * 
     * // Override auto-detection
     * $buff = Dj_App_Util::injectContent(
     *     '<meta charset="utf-8">',
     *     $buff,
     *     '<head',
     *     Dj_App_Util::INJECT_BEFORE
     * );
     * ```
     * 
     * @param string $content The content to inject
     * @param string $buff The HTML buffer to inject content into
     * @param string $tag The HTML tag to target
     * @param int|null $where Optional override for injection position (INJECT_BEFORE or INJECT_AFTER)
     * @return string Modified buffer with injected content
     */
    public static function injectContent($content, $buff, $tag, $where = null) {
        if (empty($buff) || empty($tag) || empty($content)) {
            return $buff;
        }

        // Auto-detect injection position if not specified
        if ($where === null) {
            $where = (substr($tag, 0, 2) === '</') ? self::INJECT_BEFORE : self::INJECT_AFTER;
        }

        // Find the tag position case-insensitively
        $tag_pos = stripos($buff, $tag);

        if ($tag_pos === false) {
            return $buff;
        }

        // For opening tags without '>', find where the tag ends
        if ($where === self::INJECT_AFTER && substr($tag, -1) !== '>') {
            $tag_end_pos = strpos($buff, '>', $tag_pos);
            
            if ($tag_end_pos === false) {
                return $buff;
            }
            
            $insertion_point = $tag_end_pos + 1;
        } else {
            $insertion_point = $where === self::INJECT_BEFORE ? $tag_pos : $tag_pos + strlen($tag);
        }

        // Insert the content at the determined position
        return substr_replace($buff, $content, $insertion_point, 0);
    }

    /**
     * Get/Set/Delete data in the registry
     * MERGES arrays by default when setting data with an existing key
     *
     * Usage:
     * // Set data (merges if key exists and both values are arrays)
     * Dj_App_Util::data('key', 'value');
     * Dj_App_Util::data('page_data', ['title' => 'Test']);
     * Dj_App_Util::data('page_data', ['author' => 'John']); // Merges with existing
     *
     * // Get data
     * $value = Dj_App_Util::data('key');
     *
     * // Delete data
     * Dj_App_Util::data('key', null);
     *
     * @param string $key The key to get/set/delete
     * @param mixed $val The value to set (null to delete)
     * @return mixed The value if getting, empty string if not found, or the value that was set
     */
    public static function data($key, $val = null)
    {
        // Delete mode
        if (func_num_args() === 2 && $val === null) {
            unset(self::$registry[$key]);
            return null;
        }

        // Set mode (with merge support for arrays)
        if (func_num_args() === 2) {
            // If both existing and new values are arrays, merge them
            if (is_array($val) && isset(self::$registry[$key]) && is_array(self::$registry[$key])) {
                self::$registry[$key] = array_merge(self::$registry[$key], $val);
                return self::$registry[$key];
            }

            // Otherwise, just set the value
            self::$registry[$key] = $val;
            return $val;
        }

        // Get mode
        return isset(self::$registry[$key]) ? self::$registry[$key] : '';
    }

    /**
     * Set data in the registry (OVERRIDES existing value, does not merge)
     * Use this when you want to replace data completely instead of merging
     *
     * Usage:
     * // Set data (replaces any existing value)
     * Dj_App_Util::setData('key', 'value');
     * Dj_App_Util::setData('page_data', ['title' => 'New']); // Replaces all existing data
     *
     * @param string $key The key to set
     * @param mixed $val The value to set
     * @return mixed The value that was set
     */
    public static function setData($key, $val)
    {
        self::$registry[$key] = $val;
        return $val;
    }

    /**
     * Checks if a value represents an enabled state.
     * 
     * Returns true for:
     * - Boolean true
     * - Positive numeric values (including string representations)
     * - String 'true' (case-insensitive, whitespace is trimmed)
     * - String 'yes' (case-insensitive, whitespace is trimmed)
     * - String 'on' (case-insensitive, whitespace is trimmed)
     * - String 'enabled' (case-insensitive, whitespace is trimmed)
     * 
     * Returns false for:
     * - Empty values (null, '', 0, '0', false, empty arrays)
     * - Zero values
     * - Any other values
     * 
     * @param mixed $val The value to check (string values are trimmed of whitespace)
     * @return bool True if the value represents an enabled state, false otherwise
     */

    /**
     * Checks if a value represents an enabled state.
     * 
     * Returns true for:
     * - Boolean true
     * - Positive numeric values (including string representations)
     * - String 'true' (case-insensitive, whitespace is trimmed)
     * - String 'yes' (case-insensitive, whitespace is trimmed)
     * - String 'on' (case-insensitive, whitespace is trimmed)
     * - String 'enabled' (case-insensitive, whitespace is trimmed)
     * 
     * Returns false for:
     * - Empty values (null, '', 0, '0', false, empty arrays)
     * - Zero values
     * - Any other values
     * 
     * @param mixed $val The value to check (string values are trimmed of whitespace)
     * @return bool True if the value represents an enabled state, false otherwise
     */
    public static function isEnabled($val) {
        // OPTIMIZATION: Handle most common cases first (true, 1, "1")
        if ($val === true || $val === 1 || $val === '1') {
            return true;
        }

        if (empty($val) || !is_scalar($val)) {
            return false;
        }

        if (is_numeric($val)) {
            $val_int = (int) $val;
            return $val_int !== 0;
        }

        // OPTIMIZATION: Use hash map for O(1) lookup instead of O(n) loop
        if (is_string($val)) {
            $val = trim($val);
            $val_lower = strtolower($val);

            // Hash map for enabled values (O(1) lookup)
            $enabled_map = [
                'on' => true,
                'yes' => true,
                'true' => true,
                'enabled' => true,
            ];

            return isset($enabled_map[$val_lower]);
        }

        return false;
    }

    /**
     * Checks if a value represents a disabled state.
     * It must be a clearly disabled state.
     * 
     * Returns true for:
     * - Boolean false
     * - Null values
     * - Non-scalar values (arrays, objects, etc.)
     * - Zero values (0, '0')
     * - Empty strings ('')
     * - String 'false' (case-insensitive, whitespace is trimmed)
     * - String 'no' (case-insensitive, whitespace is trimmed)
     * - String 'off' (case-insensitive, whitespace is trimmed)
     * - String 'disabled' (case-insensitive, whitespace is trimmed)
     * 
     * Returns false for:
     * - Boolean true
     * - Positive numeric values
     * - String 'true' (case-insensitive, whitespace is trimmed)
     * - String 'yes' (case-insensitive, whitespace is trimmed)
     * - String 'on' (case-insensitive, whitespace is trimmed)
     * - String 'enabled' (case-insensitive, whitespace is trimmed)
     * - Any other values
     * 
     * @param mixed $val The value to check (string values are trimmed of whitespace)
     * @return bool True if the value represents a disabled state, false otherwise
     */
    public static function isDisabled($val) {
        // OPTIMIZATION: Handle most common cases first (false, 0, "0")
        if ($val === false || $val === 0 || $val === '0') {
            return true;
        }

        if ($val === '') {
            return false; // clear value
        }

        if (is_null($val) || !is_scalar($val)) {
            return false;
        }

        // Handle numeric values - zero should be disabled, positive numbers should not
        if (is_numeric($val)) {
            $val_int = (int) $val;
            return $val_int === 0;
        }

        // OPTIMIZATION: Use hash map for O(1) lookup instead of O(n) loop
        if (is_string($val)) {
            $val = trim($val);
            $val_lower = strtolower($val);

            // Hash map for disabled values (O(1) lookup)
            $disabled_map = [
                'no' => true,
                'off' => true,
                'false' => true,
                'disabled' => true,
            ];

            return isset($disabled_map[$val_lower]);
        }

        return false;
    }

    /**
     * Replaces content between tags or adds new tag with content if not found
     * Dj_App_Util::replaceTagContent('title', 'New Title', $buff);
     * 
     * @param string $tag Tag name without brackets
     * @param string $new_content New content to insert
     * @param string $buff Buffer to modify
     * @return string Modified buffer
     */
    public static function replaceTagContent($tag, $new_content, $buff) 
    {
        if (empty($tag) || empty($buff)) {
            return $buff;
        }

        // Clean tag name from any brackets
        $tag = trim($tag, '<>/ ');
        $tag_lower = strtolower($tag);

        $start_tag = "<$tag";
        $end_tag = "</$tag>";

        // Find start tag position
        $start_pos = stripos($buff, $start_tag);

        if ($start_pos === false) {
            // Special handling for title tag - insert into head
            if ($tag_lower === 'title') {
                $head_end = stripos($buff, '</head>');

                if ($head_end !== false) {
                    // When adding new title tag to head, add newline since it's a new tag
                    $buff = substr_replace($buff, "$start_tag>$new_content$end_tag\n", $head_end, 0);
                    return $buff;
                }
            }

            // Tag not found, add it at the end with newline since it's a new tag
            return $buff . "\n$start_tag>$new_content$end_tag";
        }
        
        // Find where the start tag ends
        $content_start = strpos($buff, '>', $start_pos);

        if ($content_start === false) {
            return $buff;
        }
        
        $content_start++; // Move past '>'

        // Find the end tag
        $content_end = stripos($buff, $end_tag, $content_start);

        if ($content_end === false) {
            return $buff;
        }
        
        // Replace content between existing tags (no newline needed)
        $buff = substr_replace($buff, $new_content, $content_start, $content_end - $content_start);

        return $buff;
    }

    /**
     * Replaces or adds meta tag content efficiently by working on the head section only.
     * Searches for meta tags by name attribute and replaces the content value while preserving other attributes.
     * If the tag doesn't exist, it will be added to the head section.
     * Dj_App_Util::replaceMetaTagContent('title', 'New Title', $buff);
     *
     * Examples:
     * - <meta name="author" content="John Doe"/>
     * - <meta name="description" content="Page description" property="og:description">
     * 
     * @param string $tag_name The name attribute value (e.g., 'author', 'description')
     * @param string $content The new content value
     * @param string $buff The HTML buffer to modify
     * @return string Modified buffer or original buffer if no changes needed
     */
    public static function replaceMetaTagContent($tag_name, $content, $buff) 
    {
        if (empty($tag_name) || empty($buff)) {
            return $buff;
        }

        // Check first 100 bytes for HTML indicators
        $check_buff = substr($buff, 0, 100);

        if (strpos($check_buff, '<') === false) {
            return $buff;
        }

        // Find head section (case insensitive)
        $head_start = stripos($buff, '<head');

        if ($head_start === false) {
            return $buff;
        }

        // Find where <head> tag ends
        $head_tag_end = strpos($buff, '>', $head_start);

        if ($head_tag_end === false) {
            return $buff;
        }

        $head_tag_end++; // Move past '>'

        // Find </head> closing tag
        $head_end = stripos($buff, '</head>', $head_tag_end);

        if ($head_end === false) {
            return $buff;
        }

        // Extract head content between <head> and </head>
        $head_content = substr($buff, $head_tag_end, $head_end - $head_tag_end);
        $original_head_content = $head_content;

        // Quick check: if content already exists, do more precise check
        if (strpos($head_content, $content) !== false) {
            $escaped_content = preg_quote($content, '#');
            $content_pattern = '#content\s*=\s*["\'\s]+' . $escaped_content . '["\'\s]#i';

            // already added somehow?
            if (preg_match($content_pattern, $head_content)) {
                return $buff;
            }
        }

        // Escape tag name for regex
        $escaped_tag_name = preg_quote($tag_name, '#');

        // Pattern to match meta tag with specified name attribute
        $pattern = '#<meta\s+([^>]*\s+)?name\s*=\s*["\']' . $escaped_tag_name . '["\']([^>]*?)>#i';

        if (preg_match($pattern, $head_content, $matches)) {
            // Tag exists, replace content attribute
            $full_tag = $matches[0];
            $before_name = empty($matches[1]) ? '' : $matches[1];
            $after_name = empty($matches[2]) ? '' : $matches[2];

            // Remove existing content attribute if present
            $attributes = $before_name . $after_name;
            $attributes = preg_replace('#\s*content\s*=\s*["\'][^"\']*["\']#i', '', $attributes);

            // Clean up extra spaces
            $attributes = preg_replace('#\s+#', ' ', trim($attributes));

            // Build new tag with updated content
            $before_attrs = !empty($before_name) ? ' ' . trim($before_name) : '';
            $after_attrs = !empty($attributes) ? ' ' . $attributes : '';
            $encoded_content = Djebel_App_HTML::encodeEntities($content);

            $new_tag = sprintf('<meta%s name="%s"%s content="%s">', 
                $before_attrs, 
                $tag_name, 
                $after_attrs, 
                $encoded_content
            );

            // Replace the old tag with new one
            $head_content = str_replace($full_tag, $new_tag, $head_content);
        } else {
            // Tag doesn't exist, add it before </head>
            $encoded_content = Djebel_App_HTML::encodeEntities($content);
            $new_tag = sprintf('<meta name="%s" content="%s">%s', $tag_name, $encoded_content, "\n");
            $head_content .= $new_tag;
        }

        // Return early if no changes made
        if ($head_content === $original_head_content) {
            return $buff;
        }

        // Reconstruct buffer with modified head content
        $buff = substr($buff, 0, $head_tag_end) . $head_content . substr($buff, $head_end);

        return $buff;
    }

    /**
     * Replaces magic variables in content with their actual values
     * Example: __THEME_URL__, __SITE_URL__, etc.
     * 
     * @param string $buff Content buffer
     * @param array $params Required parameters (theme_url, theme_dir)
     * @return string Modified buffer
     */
    public static function replaceMagicVars($buff, $params = []) 
    {
        if (empty($buff) || (stripos($buff, '__') === false)) {
            return $buff;
        }

        $req_obj = Dj_App_Request::getInstance();

        // quick correct in case somebody uses _uri
        $buff = self::cleanMagicVars($buff);

        $web_path = $req_obj->getWebPath();
        $site_url = $req_obj->getSiteUrl();
        $content_dir_url = Dj_App_Util::getContentDirUrl();
        $site_web_path = Dj_App_Util::removeSlash($web_path);
        $site_content_web_path = Dj_App_Util::removeSlash($web_path) . '/' . Dj_App_Util::getContentDirName();

        // Define magic variables and their values
        $search_magic_vars = [
            '__SITE_URL__' => $site_url,
            '__SITE_WEB_PATH__' => $site_web_path,
            '__SITE_CONTENT_DIR_URL__' => $content_dir_url,
            '__SITE_CONTENT_WEB_PATH__' => $site_content_web_path,
            '__CONTENT_URL__' => $content_dir_url,
        ];

        // allows others to add other magic vars
        $ctx = [];
        $search_magic_vars = Dj_App_Hooks::applyFilter( 'app.page.content.magic_vars', $search_magic_vars, $ctx );

        // Replace magic variables
        $buff = str_ireplace(
            array_keys($search_magic_vars), 
            array_values($search_magic_vars), 
            $buff
        );

        // Process theme URLs separately (needs versioning)
        if (stripos($buff, '__THEME_URL__') !== false) {
            $buff = preg_replace_callback(
                '~(href|srcset|src)\h*=\h*[\'"](.*?__THEME_URL__/([^\'"]+))[\'"]~i',
                function($matches) use ($params) {
                    $full_match = $matches[0];
                    $path = end($matches); // Get the path part after __theme_url__/

                    // Get full server path to check file
                    $file_path = $params['theme_dir'] . '/' . $path;
                    $url = str_ireplace('__THEME_URL__', $params['theme_url'], $matches[2]);

                    if (file_exists($file_path)) {
                        $version = filemtime($file_path);
                        $url = Dj_App_Request::addQueryParam('v', $version, $url);
                    }

                    $full_match = str_replace($matches[2], $url, $full_match);

                    return $full_match;
                },
                $buff
            );
        }

        return $buff;
    }

    /**
     * Cleans up magic variable format
     * Converts variations to the expected format
     * 
     * @param string $buff Content buffer
     * @return string Cleaned buffer
     */
    public static function cleanMagicVars($buff) 
    {
        if (empty($buff)) {
            return $buff;
        }

        // quick check if we need to do anything
        if ((stripos($buff, 'URL_') === false) && (stripos($buff, 'URL-') === false)) {
            return $buff;
        }

        $magic_vars = [
            'SITE_URL',
            'SITE_URI',
            'SITE_WEB_PATH',
            'THEME_URL',
            'THEME_URI',
            'CONTENT_URL',
            'CONTENT_URI',
        ];

        // let's check if those exist in the buffer
        // of they don't exist reduce the params in the regex.
        foreach ($magic_vars as $idx => $magic_var) {
            if (stripos($buff, $magic_var) === false) {
                unset($magic_vars[$idx]);
            }
        }

        if (empty($magic_vars)) {
            return $buff;
        }

        $pattern = '~[_\-]{2,}(' . join('|', $magic_vars) . ')[_\-]{2,}~si';
        $buff = preg_replace($pattern, '__${1}__', $buff);
        $buff = str_ireplace('_URI__', '_URL__', $buff);

        return $buff;
    }

    /**
     * Return the user's home directory.
     * Dj_App_Util::getUserHome()
     * @see https://stackoverflow.com/questions/1894917/how-to-get-the-home-directory-from-a-php-cli-script
     */
    public static function getUserHome() {
        $home = '';
        
        // Try environment variable first (most reliable on Unix-like systems)
        if (!empty(getenv('HOME'))) {
            $home = getenv('HOME');
        }
        // Try $_SERVER superglobal (fallback for some environments)
        elseif (!empty($_SERVER['HOME'])) {
            $home = $_SERVER['HOME'];
        }
        // Windows-specific home directory detection
        elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
            $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        }
        // Try POSIX functions as last resort
        elseif (function_exists('posix_getuid') && function_exists('posix_getpwuid')) {
            $os_uid = posix_getuid();
            $os_user_rec = posix_getpwuid($os_uid);

            if (!empty($os_user_rec['dir'])) {
                $home = $os_user_rec['dir'];
            }
        }

        if (!empty($home)) {
            $home = self::removeSlash($home);
        }

        return $home;
    }

    /**
     * Safe serialize - converts objects to arrays to prevent exploits
     * Dj_App_Util::serialize();
     *
     * @param mixed $data Data to serialize
     * @return string|false Serialized data or false on failure
     */
    public static function serialize($data)
    {
        $data = self::normalizeForSerialization($data);
        $result = serialize($data);
        return $result;
    }

    /**
     * Safe unserialize
     * Dj_App_Util::unserialize();
     *
     * @param string $data Serialized data
     * @return mixed|false Unserialized data or false on failure
     */
    public static function unserialize($data)
    {
        if (empty($data) || !is_string($data)) {
            return false;
        }

        // Quick check on small buffer
        $buffer_size = 64;
        $small_buffer = substr($data, 0, $buffer_size);

        // First char must be valid type, second must be ':'
        $first_char = $small_buffer[0];
        $valid_types = ['b', 'i', 'd', 's', 'a', 'N']; // bool, int, double, string, array, null

        if (!in_array($first_char, $valid_types)) {
            return false;
        }

        if (empty($small_buffer[1]) || $small_buffer[1] != ':') {
            return false;
        }

        // Security: refuse if contains serialized objects (O: or C:)
        if ((stripos($small_buffer, 'O:') !== false) || (stripos($small_buffer, 'C:') !== false)) {
            return false;
        }

        $result = @unserialize($data);

        return $result;
    }

    /**
     * Normalize data for safe serialization
     * Converts objects to arrays to prevent __wakeup/__unserialize exploits
     * Dj_App_Util::normalizeForSerialization();
     *
     * @param mixed $data Data to normalize
     * @return mixed Normalized data (scalars, arrays only - no objects)
     */
    public static function normalizeForSerialization($data)
    {
        if (is_scalar($data)) {
            return $data;
        } else if (is_null($data) || is_resource($data)) {
            return null;
        }

        if (is_array($data)) {
            $normalized = [];

            foreach ($data as $key => $value) {
                $normalized[$key] = self::normalizeForSerialization($value);
            }

            return $normalized;
        }

        if (is_object($data)) { // convert to array
            if (method_exists($data, 'toArray')) {
                $array_data = $data->toArray();
            } else {
                $array_data = (array) $data;
            }

            $array_data = self::normalizeForSerialization($array_data);

            return $array_data;
        }

        return null;
    }
}

// https://stackoverflow.com/questions/22113541/using-additional-data-in-php-exceptions
class Dj_App_Exception extends \Exception
{
    private $_msg = '';
    private $_error_code = '';
    private $_data = [];

    public function __construct($message, $data = [])
    {
        $this->_data = $data;
        parent::__construct($message, 0, null);
    }

    const INJECT_EXCEPTION_IN_DATA = true;
    const DONT_INJECT_EXCEPTION_IN_DATA = false;

    /**
     * @return array|object|Dj_App_Result
     */
    public function getData($inject_exc = self::DONT_INJECT_EXCEPTION_IN_DATA)
    {
        $data = $this->_data;

        if ($inject_exc === self::INJECT_EXCEPTION_IN_DATA) {
            if (is_array($data)) {
                $data['_exception'] = $this->getMessage();
            } elseif (is_object($data)) {
                if (is_a($data, 'Dj_App_Result')) {
                    $data->data('_exception', $this->getMessage());
                } else {
                    $data->_exception = $this->getMessage();
                }
            }
        }

        return $data;
    }

    /**
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->_error_code;
    }

    /**
     * @param string $error_code
     */
    public function setErrorCode(string $error_code): void
    {
        $this->_error_code = $error_code;
    }

    /**
     * @return string
     */
    public function getMsg(): string
    {
        return $this->_msg;
    }
}
