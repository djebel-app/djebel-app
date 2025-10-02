<?php

class Dj_App {
    const NAME = 'Djebel';
    const VERSION = '0.0.1';
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
     * $time_ms = Dj_App_Util::time();
     * sprintf( "%.02f", abs( $time_ms - Dj_App_Util::time() ) );
     *
     * Usage 2:
     * Dj_App_Util::time( 'setup_vhost' );
     * ......
     * $time_delta = Dj_App_Util::time( 'setup_vhost' );
     *
     * if you don't want the time formatted (to 2 decimals) pass 0 as 2nd param.
     * $time_delta = Dj_App_Util::time( 'setup_vhost', 0 );
     * sprintf( "%.02f", $time_delta );
     *
     * @param string $marker optional
     * @return float|string
     */
    public static function time($marker = '', $fmt = 1, $precision = 6)
    {
        if (!is_scalar($marker)) {
            $marker = serialize($marker);
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
        $private_dir = Dj_App_Env::getEnvConst('DJEBEL_APP_PRIVATE_DIR');
        $dir = empty($private_dir) ? Dj_App_Util::getSiteRootDir() . '/.ht_djebel' : $private_dir;
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

        $dir .= '/data';

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

        $dir .= '/cache';

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

        $dir .= '/tmp';

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
     * Dj_App_Util::getContentUri();
     * @return string
     */
    public static function getContentUri()
    {
        $dir = 'http://domain/dj-content';
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
     * Dj_App_Util::extractMetaInfo
     * @param string $buff
     * @return Dj_App_Result
     */
    public static function extractMetaInfo($buff)
    {
        $res_obj = new Dj_App_Result();
        Dj_App_Util::time( __METHOD__ );

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
                $value = Dj_App_String_Util::trim($value, '[]');

                if (strpos($value, ',') !== false) {
                    $items = explode(',', $value);
                    $items = Dj_App_String_Util::trim($items);
                    $meta[$key] = array_filter($items);
                } else {
                    $meta[$key] = $value;
                }
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
            $res_obj->exec_time = Dj_App_Util::time( __METHOD__ );
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
        $msg = __($msg, 'qs_site_app');

        $id = 'app';
        $cls = $extra = $inline_css = $extra_attribs = '';

        $msg = is_scalar($msg) ? $msg : join("\n<br/>", $msg);
        $icon = 'exclamation-sign';

        if ( $status === 2 || $status == self::MSG_NOTICE) { // notice
            $cls = 'app_info alert alert-info';
        } elseif ( $status === 6 ) { // dismissable notice
            $cls = 'app_info alert alert-danger alert-dismissable';
            $extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false"><span aria-hidden="true">&times;</span><span class="__sr-only">Close</span></button>';
            //$extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false">X</button>';
        } elseif ( $status === 4 ) { // dismissable notice
            $cls = 'app_info alert alert-info alert-dismissable';
            $extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false"><span aria-hidden="true">&times;</span><span class="__sr-only">Close</span></button>';
            //$extra = ' <button type="button" class="close" data-dismiss="alert" aria-hidden="false">X</button>';
        } elseif ( $status == 0 || $status === false || $status === self::MSG_ERROR ) {
            $cls = 'app_error alert alert-danger';
            $icon = 'remove';
        } elseif ( $status == 1 || $status === true || $status === self::MSG_SUCCESS ) {
            $cls = 'app_success alert alert-success';
            $icon = 'ok';
        }

        if (is_array($use_inline_css)) {
            $extra_attribs = self::array2data_attr($use_inline_css);
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
     * 
     * Usage:
     * // Set data
     * Dj_App_Util::data('key', 'value');
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

        // Set mode
        if (func_num_args() === 2) {
            self::$registry[$key] = $val;
            return $val;
        }

        // Get mode
        return isset(self::$registry[$key]) ? self::$registry[$key] : '';
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
        if (empty($val) || !is_scalar($val)) {
            return false;
        }

        if ($val === true) {
            return true;
        }

        if (is_numeric($val)) {
            $val = (int) $val;
            return !empty($val);
        }

        // Handle string values - trim only if it's a string
        if (is_string($val)) {
            $val = trim($val);
        }

        $true_vals = [
            'yes',
            'true',
            'on',
            'enabled',
        ];

        foreach ($true_vals as $true_val) {
            if (strcasecmp($val, $true_val) == 0) {
                return true;
            }
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
        // Handle boolean false first
        if ($val === false) {
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
            $val = (int) $val;
            return empty($val);
        }

        // Handle string values - trim only if it's a string
        $val = is_string($val) ? trim($val) : $val;

        $disabled_vals = [
            'false',
            'no',
            'off',
            'disabled',
        ];

        foreach ($disabled_vals as $disabled_val) {
            if (strcasecmp($val, $disabled_val) == 0) {
                return true;
            }
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

        // Define magic variables and their values
        $search_magic_vars = [
            '__SITE_URL__' => $req_obj->getSiteUrl(),
            '__SITE_CONTENT_DIR_URL__' => $req_obj->getContentDirUrl(),
            '__SITE_WEB_PATH__' => $web_path,
            '__SITE_CONTENT_WEB_PATH__' => rtrim($web_path, '/') . '/' . Dj_App_Util::getContentDirName(),
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

// If a plugin didn't define this. We'll define it here.
if (!function_exists('__')) {
    /**
     * Translate a string.
     * @param string $text
     * @param string $domain
     * @return string
     */
    function __($text, $domain = 'default') {
        return $text;
    }
}
