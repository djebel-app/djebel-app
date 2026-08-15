<?php
/**
 * This is the Djebel bootstrap file. The app is pretty configurable via ENV or const vars, so don't modify this file.
 * @package Djebel
 */

$app_base_dir = Dj_App_Config::cfg('app.sys.app_base_dir', __DIR__); // where djebel is unpacked.
$dj_app_src_dir = Dj_App_Config::cfg('app.sys.app_src_dir', $app_base_dir . '/src');
$dj_app_core_dir = Dj_App_Config::cfg('app.sys.app_core_dir', $dj_app_src_dir . '/core');
$app_lib_dir = Dj_App_Config::cfg('app.sys.app_lib_dir', $dj_app_core_dir . '/lib');

require_once $app_lib_dir . '/env.php';
require_once $app_lib_dir . '/html.php';
require_once $app_lib_dir . '/util.php';
require_once $app_lib_dir . '/options.php';
require_once $app_lib_dir . '/string_util.php';
require_once $app_lib_dir . '/file_util.php';
require_once $app_lib_dir . '/result.php';
require_once $app_lib_dir . '/cache.php';
require_once $app_lib_dir . '/hooks.php';
require_once $app_lib_dir . '/request.php';
require_once $app_lib_dir . '/log.php';

// CLI only — a web request has no argv to parse, so it pays one stripos and no file
// read. Loaded HERE rather than by each tool: every CLI tool needs it, and three of
// them were already requiring the same file by hand (djebel's own tools/pkg.php and
// tools/release.php, plus oterm's release bootstrap).
if (Dj_App_Env::isCli()) {
    require_once $app_lib_dir . '/cli_util.php';
}

$app_conf_dir = Dj_App_Util::getCoreConfDir();
$config_env_file = Dj_App_Config::cfg('env_file', $app_conf_dir . '/.env');

// Env specific conf? One file per env: .env_<env> fully replaces .env when it exists.
$app_env = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');

if (!empty($app_env)) {
    $app_env_fmt = Dj_App_String_Util::formatStringId($app_env);
    $config_env_file_alt = $app_conf_dir . '/.env_' . $app_env_fmt;

    if (file_exists($config_env_file_alt)) {
        $config_env_file = $config_env_file_alt;
    }
}

$env_cfg_data = Dj_App_Config::loadIniFile($config_env_file);
Dj_App_Env::set($env_cfg_data);

// Initialize global error handlers. All three registrations land on ONE sink — PHP just
// hands the error over differently in each (Throwable / errno+message / error_get_last).
// Warnings, notices and deprecations reach the app log ONLY through the error handler;
// it is registered here so it covers the whole request, including plugin loading.
set_exception_handler(['Dj_App_Bootstrap', 'handleError']);
set_error_handler(['Dj_App_Bootstrap', 'handleError'], Dj_App_Bootstrap::ERROR_HANDLER_FLAGS);

// Register the shutdown phase FIRST so it runs before the error sink. This matters
// because rendering the fatal-error page can call Dj_App::exit(), and PHP stops the
// shutdown chain when exit() fires from inside a shutdown function. Running
// runShutdownHooks first ensures deferred work (logging, notifications) gets a chance
// even on fatal errors.
register_shutdown_function(['Dj_App_Hooks', 'runShutdownHooks']);

// Fatal-error renderer runs AFTER runShutdownHooks. Called with no args, so the sink
// reads error_get_last(); idempotent — with no FATAL recorded there this is a no-op, and
// a warning already logged by the error handler is skipped rather than logged twice.
register_shutdown_function(['Dj_App_Bootstrap', 'handleError']);

Dj_App_Util::microtime( 'dj_app_timer' );

require_once $app_lib_dir . '/page.php';
require_once $app_lib_dir . '/plugins.php';

Dj_App_Hooks::doAction( 'app.core.lib.loaded' );

$app_load_options = Dj_App_Config::cfg('app.core.options.load', true);
$options_obj = Dj_App_Options::getInstance();

if ($app_load_options) {
    $options_obj->load();
    Dj_App_Hooks::doAction( 'app.core.options.loaded' );
}

// Lib loading — two independent app.ini toggles (env/const default via cfg; filter override).
//   [app] load_lib_loader — require the loader class (Dj_App_Lib) so on-demand loadLib() calls work.
//   [app] load_libs        — also eager-load at bootstrap by handing its value to loadLib().
// Either one requires the loader; both default off.
$load_lib_loader = $options_obj->get('app.load_lib_loader', Dj_App_Config::cfg('app.core.load_lib_loader'));
$load_lib_loader = Dj_App_Hooks::applyFilter('app.core.load_lib_loader', $load_lib_loader);

$load_libs = $options_obj->get('app.load_libs', Dj_App_Config::cfg('app.core.load_libs'));
$load_libs = Dj_App_Hooks::applyFilter('app.core.load_libs', $load_libs);

$loader_enabled = Dj_App_Util::isEnabled($load_lib_loader);
$eager_load_libs = !empty($load_libs) && !Dj_App_Util::isDisabled($load_libs);

if ($loader_enabled || $eager_load_libs) {
    require_once $app_lib_dir . '/lib.php';
}

if ($eager_load_libs) {
    Dj_App_Lib::loadLib($load_libs);
}

// should we run?
$run_app = Dj_App_Config::cfg('app.core.run', true);
$headless = Dj_App_Config::cfg('app.core.headless', false);

// @todo should this be a plugin or a core feature?
$app_load_shortcodes = Dj_App_Config::cfg('app.core.shortcodes.load', true);

if ($app_load_shortcodes) {
    require_once $app_lib_dir . '/shortcode.php';
    $shortcode_obj = Dj_App_Shortcode::getInstance();
    $shortcode_obj->installHooks();
    Dj_App_Hooks::doAction( 'app.core.shortcodes.loaded' );
}

// we return after the shortcodes are loaded as there could be plugins that rely on it.
// In headless mode the shutdown phase is still handled by the shutdown function
// registered above (Dj_App_Hooks::runShutdownHooks) — no explicit drain needed here.
if (empty($run_app) || $headless) {
    return;
}

$boostrap_obj = Dj_App_Bootstrap::getInstance();
$boostrap_obj->installHooks();

$req_obj = Dj_App_Request::getInstance();

// if we're called about .css, .js that means it's missing but we don't need to handle them
$app_process_missing_files = Dj_App_Config::cfg('app.core.process_missing_static_files', false);

if (!Dj_App_Util::isEnabled($app_process_missing_files)) {
    // Early 404 check for static assets (images, CSS, JS) to avoid unnecessary processing
    $request_uri = $req_obj->getRequestUrl();

    if (!empty($request_uri)) {
        $path_info = parse_url($request_uri, PHP_URL_PATH);

        if (!empty($path_info)) {
            $extension = Dj_App_File_Util::getExt($path_info);
            $static_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'css', 'js', 'ico', 'woff', 'woff2', 'ttf', 'eot', ];

            if (in_array($extension, $static_extensions)) {
                http_response_code(404);
                header('Content-Type: text/plain');
                echo 'Djebel Error: Page Not Found (404)';
                exit;
            }
        }
    }
}

// Loading system plugins
$sys_plugins_dir = Dj_App_Plugins::getSysPluginsDir();

if (!empty($sys_plugins_dir) && is_dir($sys_plugins_dir)) {
    Dj_App_Hooks::doAction( 'app.core.system_plugins.pre_load' );
    Dj_App_Plugins::loadPlugins(['dir' => $sys_plugins_dir, 'is_system' => true]);
    Dj_App_Hooks::doAction( 'app.core.system_plugins.loaded' );
}

$ctx = [];
$plugin_dirs = [];

// these are plugins that run for all sites on the server.
$app_core_shared_plugins_dir = Dj_App_Plugins::getSharedPluginsDir();
$load_core_shared_plugins = Dj_App_Hooks::applyFilter( 'app.core.plugins.load_shared_plugins', !empty($app_core_shared_plugins_dir) );

if (Dj_App_Util::isEnabled($load_core_shared_plugins) && !empty($app_core_shared_plugins_dir)) {
    $plugin_dirs[] = $app_core_shared_plugins_dir;
}

// Add non-public plugins if enabled
$app_non_public_plugins_dir = Dj_App_Plugins::getNonPublicPluginsDir();
$load_non_public_plugins = Dj_App_Config::cfg('app.core.plugins.load_non_public_plugins', is_dir($app_non_public_plugins_dir));

if (Dj_App_Util::isEnabled($load_non_public_plugins)) {
    if (!empty($app_non_public_plugins_dir) && is_dir($app_non_public_plugins_dir)) {
        $plugin_dirs[] = $app_non_public_plugins_dir;
    }
}

$load_plugins = Dj_App_Hooks::applyFilter( 'app.core.plugins.load_plugins', true );

if ($load_plugins) {
    $plugin_dirs[] = Dj_App_Plugins::getPluginsDir();
}

// in case somebody wants to load more plugins
$plugin_dirs = Dj_App_Hooks::applyFilter( 'app.core.plugins.plugin_dirs', $plugin_dirs );

if (!empty($plugin_dirs)) {
    $ctx['plugin_dirs'] = $plugin_dirs;
    Dj_App_Hooks::doAction( 'app.core.plugins.before_load_plugins', $ctx );

    foreach ($plugin_dirs as $plugin_dir) {
        $plugin_load_res_obj = Dj_App_Plugins::loadPlugins(['dir' => $plugin_dir]);
    }

    Dj_App_Hooks::doAction( 'app.core.plugins.loaded', $ctx );
}

try {
    Dj_App_Hooks::doAction( 'app.core.init' );

    $req_obj = Dj_App_Request::getInstance();

    // Hold the WHOLE response in ONE unlimited buffer — no chunk size, so PHP never
    // auto-flushes it — and leave it open for finishRequest() to measure and flush at
    // shutdown. php.ini's output_buffering is size-limited: a page bigger than the chunk
    // flushes itself on the way out and leaves nothing to count, so the body length read
    // 0 and the response went out unframed. Buffering every path (theme, content, a
    // plugin echoing directly) is what makes that count the real body size.
    //
    // A plugin that STREAMS (a downloader) turns this off so its output goes straight to
    // the client instead of being held whole in memory. With no buffer there is nothing
    // to measure, so finishRequest() emits no Content-Length — correct for a stream.
    $buffer_output_env = Dj_App_Config::cfg('app.core.buffer_output', true);
    $buffer_output = Dj_App_Util::isDisabled($buffer_output_env) ? false : true;
    $buffer_output = Dj_App_Hooks::applyFilter('app.core.buffer_output', $buffer_output);

    if ($buffer_output) {
        ob_start();
    }

    $load_theme_env = Dj_App_Config::cfg('app.core.theme.load_theme', true);
    $load_theme = Dj_App_Util::isDisabled($load_theme_env) ? false : true;

    // Check if theme loading is disabled in app.ini [theme] section
    if ($load_theme) {
        $load_theme_opt = $options_obj->get('theme.load_theme');

        if (Dj_App_Util::isDisabled($load_theme_opt)) {
            $load_theme = false;
        }
    }

    // No theme configured in options = don't load theme
    if ($load_theme) {
        $theme_id = $options_obj->get('theme.theme,theme.theme_id,site.theme_id,site.theme');

        if (empty($theme_id)) {
            $load_theme = false;
        }
    }

    $load_theme = Dj_App_Hooks::applyFilter('app.core.theme.load_theme', $load_theme);

    if ($load_theme) {
        require_once $app_lib_dir . '/themes.php';
        $themes_obj = Dj_App_Themes::getInstance();
        $themes_obj->installHooks();
        $themes_obj->loadTheme();
    } else {
        ob_start();
        Dj_App_Hooks::doAction( 'app.core.theme.theme_not_loaded' );
        Dj_App_Hooks::doAction( 'app.page.content.render' );
        $content = ob_get_clean();
        $content = Dj_App_Hooks::applyFilter( 'app.page.content', $content );
        $content = trim($content);

        $content = Dj_App_Hooks::applyFilter( 'app.page.full_content', $content );

        $req_obj->setContent($content);
    }
} finally {
    $req_obj->outputContent();
    $exec_time = Dj_App_Util::microtime( 'dj_app_timer' ); // move this to shutdown
}

class Dj_App_Config {
    const APP_ENV = 'env';
    const APP_BASE_DIR = 'base_dir';
    const APP_CONFIG_DIR = 'config_dir';
    const APP_CONFIG_ALT_DIR = 'config_alt_dir';
    const APP_CORE_DIR = 'core_dir';
    const APP_LIB_DIR = 'lib_dir';

    /**
     * Gets/sets cfg/env vars. If the original value is not found we'll set the fallback.
     * There could be serialized env values, but we'll not handle that here.
     * Dj_App_Config::cfg();
     * @param string $key
     * @param mixed $val
     * @param array $attribs
     * @return string
     */
    public static function cfg($key, $fallback_val = '', $attribs = [])
    {
        try {
            $key_fmt = $key;
            $key_fmt = preg_replace('#[^\w]+#si', '_', $key_fmt);
            $key_fmt = preg_replace('#\_+#si', '_', $key_fmt);
            $key_fmt = strtoupper($key_fmt);
            $key_fmt = trim($key_fmt, '_');

            if (empty($key_fmt)) {
                return $fallback_val;
            }

            $app_key_fmt = 'DJEBEL_' . $key_fmt;

            // First try with original key
            $val = getenv($key);

            if ($val === false) {
                // Try formatted key if original wasn't found
                $val = getenv($key_fmt);

                if ($val === false) {
                    // Try app-prefixed key if formatted wasn't found
                    $val = getenv($app_key_fmt);
                }
            }

            // At this point:
            // - $val === false means the env var wasn't found in any form
            // - $val === '' means it exists but is empty
            // - otherwise it has the actual value (including '0')
            if ($val !== false) { // was found in the env
                if (!empty($attribs['override'])) {
                    $val = $fallback_val;
                }
                return $val;
            }

            if (defined($key_fmt)) { // check const
                $val = constant($key_fmt);
            }

            if (strlen($val) == 0 && defined($app_key_fmt)) { // check app const
                $val = constant($app_key_fmt);
            }

            if (strlen($val) == 0) {
                $val = $fallback_val;
            }
        } finally {
            $val = self::replaceSystemVars($val);

            if (class_exists('Dj_App_Hooks')) { // maybe too early
                $val = Dj_App_Hooks::applyFilter( 'app.core.cfg', $val, [ 'key' => $key_fmt ] );
            }

            // We need to set this always so the consts are defined between requests
            if (!empty($val)) {
                putenv($key . '=' . $val);
            } elseif (!empty($attribs['override'])) {
                putenv($key); // rm env
            }
        }

        return $val;
    }

    /**
     * Load an ini file and return the parsed data. Applying values to the
     * environment is the caller's job — Dj_App_Env::setEnvVars() owns env vars.
     * Dj_App_Config::loadIniFile()
     * @param string $file
     * @return array
     */
    public static function loadIniFile($file) {
        $data = [];

        if (empty($file) || !file_exists($file)) {
            return $data;
        }

        // using INI_SCANNER_RAW because so we have any special chars like | preserved in the values.
        $env_vars = parse_ini_file($file, false, INI_SCANNER_RAW);

        // A malformed ini file makes parse_ini_file return false (e.g. a '#'
        // pseudo-comment with parens — '#' is NOT an ini comment); never leak that —
        // this method always returns an array.
        if (empty($env_vars)) {
            return $data;
        }

        return $env_vars;
    }

    /**
     * Replaces system variables in configuration values
     *
     * @param mixed $val
     * @param array $options
     * @return mixed
     */
    public static function replaceSystemVars($val, $options = []) {
        if (empty($val) || !is_scalar($val)) {
            return $val;
        }

        // Check if this contains any system variables
        if (strpos($val, '{') === false) {
            return $val;
        }

        $replace_vars = [];

        // Handle {home} and {user_home} variables
        if (stripos($val, 'home}') !== false) {
            $home = self::getUserHome();
            $replace_vars['{home}'] = $home;
            $replace_vars['{user_home}'] = $home;
        }

        $val = str_ireplace(array_keys($replace_vars), array_values($replace_vars), $val);

        return $val;
    }

    /**
     * Get user home directory for system variable replacement
     * This is a simplified version that works during bootstrap
     * 
     * @return string
     */
    private static function getUserHome() {
        $home = '';
        
        // Try environment variable first
        if (!empty(getenv('HOME'))) {
            $home = getenv('HOME');
        }
        // Try $_SERVER superglobal
        elseif (!empty($_SERVER['HOME'])) {
            $home = $_SERVER['HOME'];
        }
        // Windows-specific home directory detection
        elseif (!empty($_SERVER['HOMEDRIVE']) && !empty($_SERVER['HOMEPATH'])) {
            $home = $_SERVER['HOMEDRIVE'] . $_SERVER['HOMEPATH'];
        }

        if (!empty($home)) {
            $home = rtrim($home, '/\\');
        }

        return $home;
    }
}

class Dj_App_Bootstrap {

    /**
     * Singleton pattern i.e. we have only one instance of this obj
     *
     * @staticvar static $instance
     * @return static
     */
    public static function getInstance() {
        static $instance = null;

        // This will make the calling class to be instantiated.
        // no need each sub class to define this method.
        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }

    // Diagnostics the error handler collects. E_ERROR / E_PARSE / E_CORE_ERROR /
    // E_COMPILE_ERROR are absent ON PURPOSE — PHP never hands a fatal to a userland
    // handler, so listing them would be dead flags; those arrive on the shutdown call.
    const ERROR_HANDLER_FLAGS = E_WARNING | E_NOTICE | E_USER_ERROR | E_USER_WARNING
        | E_USER_NOTICE | E_RECOVERABLE_ERROR | E_DEPRECATED | E_USER_DEPRECATED;

    // The types that END the request, so they get the error page as well as a log line.
    const FATAL_ERROR_TYPES = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, ];

    /**
     * Logs any PHP diagnostic, and renders the 500 page for the ones that end the request.
     *
     * A warning, notice or deprecation is logged and the request carries on. An uncaught
     * exception or a FATAL is logged AND gets the error page.
     *
     * Takes the error in whichever form the caller holds it: a Throwable, or a PHP errno
     * followed by the message / file / line. With NO arguments it reads error_get_last()
     * and acts only on a FATAL there — anything else has already been logged on its way
     * through, so it is skipped rather than recorded twice.
     * Dj_App_Bootstrap::handleError($error, $err_str, $err_file, $err_line);
     * @param Throwable|int $error Throwable, or a PHP errno (0 reads error_get_last())
     * @param string $err_str
     * @param string $err_file
     * @param int $err_line
     * @return bool false, so PHP's normal handling still runs and whatever logs today
     *              keeps logging.
     */
    public static function handleError($error = 0, $err_str = '', $err_file = '', $err_line = 0) {
        $exception = $error instanceof Throwable ? $error : null;
        $err_no = empty($exception) ? $error : 0;

        // Shutdown call: no args, so take whatever PHP recorded last. Only a FATAL is new
        // here — a warning already arrived through the error-handler registration and was
        // logged there, so re-reading it would log every notice twice.
        if (empty($exception) && empty($err_no)) {
            $last_error = error_get_last();

            if (empty($last_error['type']) || !in_array($last_error['type'], self::FATAL_ERROR_TYPES)) {
                return false;
            }

            $err_no = $last_error['type'];
            $err_str = $last_error['message'];
            $err_file = $last_error['file'];
            $err_line = $last_error['line'];
        }

        $is_fatal = !empty($err_no) && in_array($err_no, self::FATAL_ERROR_TYPES);
        $ends_request = !empty($exception) || $is_fatal;

        // Honor the active error_reporting level for the recoverable diagnostics — one the
        // site chose not to report must not reach the log either. Anything that ends the
        // request is always worth recording.
        if (!$ends_request) {
            $reporting_level = error_reporting();

            if (empty($reporting_level) || !($reporting_level & $err_no)) {
                return false;
            }
        }

        // The logger owns the entry format; it labels an array from the errno under 'type'.
        $log_payload = $exception;

        if (empty($exception)) {
            $log_payload = [
                'type' => $err_no,
                'message' => $err_str,
                'file' => $err_file,
                'line' => $err_line,
            ];
        }

        $log_res = Dj_App_Log::logAppError($log_payload);

        // A warning / notice is logged and the request carries on.
        if (!$ends_request) {
            return false;
        }

        $render_params = [
            'exception' => $exception,
            'message' => empty($exception) ? $err_str : $exception->getMessage(),
            'file' => empty($exception) ? $err_file : $exception->getFile(),
            'line' => empty($exception) ? $err_line : $exception->getLine(),
            'log_ok' => $log_res,
        ];

        Dj_App_Bootstrap::renderErrorPage($render_params);

        return false;
    }

    /**
     * The 500 page shared by every request-ending failure. Drains partial output first so
     * the page renders alone, and never lets its own rendering fail silently.
     * Dj_App_Bootstrap::renderErrorPage($params);
     * @param array $params exception (Throwable|null), message, file, line, log_ok
     * @return bool
     */
    public static function renderErrorPage($params = []) {
        $exception = empty($params['exception']) ? null : $params['exception'];
        $is_dev = Dj_App_Config::cfg('app.debug', false);
        $log_errors = Dj_App_Config::cfg('app.error_logging', true);

        // Discard any partially rendered output so the error page renders alone.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $title = empty($exception) ? 'Fatal Error' : 'Uncaught Exception';

        // The raw message can leak internals (SQL, dirs) — the public page shows a
        // generic line; the real message is in the log and on the dev page.
        $display_msg = empty($is_dev) ? 'Something went wrong.' : $params['message'];
        $display_msg_esc = dj_esc_html($display_msg);
        $title_esc = dj_esc_html($title);

        $content = sprintf("<h1 class='djebel-app-error-title'>%s</h1>\n", $title_esc);
        $content .= sprintf("<div class='djebel-app-error-message'>%s</div>\n", $display_msg_esc);

        if (!Dj_App_Util::isDisabled($log_errors) && empty($params['log_ok'])) {
            $content .= "<div class='djebel-app-error-message'>Log Error: Log log dir/file is no writable </div>\n";
        }

        if ($is_dev) {
            // label => value; every row renders through the ONE format below. A fatal
            // carries no class or code, so those rows only exist for an exception.
            $detail_rows = [];

            if (!empty($exception)) {
                $detail_rows['Exception'] = get_class($exception);
            }

            $detail_rows['File'] = $params['file'];
            $detail_rows['Line'] = $params['line'];

            // getCode() is mixed — a custom exception can return a string, so it is
            // escaped like every other value here.
            if (!empty($exception)) {
                $detail_rows['Code'] = $exception->getCode();
            }

            $content .= "<div class='djebel-app-error-details'>\n";

            foreach ($detail_rows as $detail_label => $detail_value) {
                $detail_label_esc = dj_esc_html($detail_label);
                $detail_value_esc = dj_esc_html($detail_value);

                $content .= sprintf(
                    "<div class='djebel-app-detail-item'><div class='djebel-app-detail-label'>%s:</div><div class='djebel-app-detail-value'>%s</div></div>\n",
                    $detail_label_esc,
                    $detail_value_esc
                );
            }

            $content .= "</div>\n";

            if (!empty($exception)) {
                $trace_esc = dj_esc_html($exception->getTraceAsString());

                $content .= sprintf("<div class='djebel-app-trace'>%s</div>\n", $trace_esc);
            }
        }

        $content .= "<div class='djebel-app-back-link'><a href='javascript:history.back()'>← Go Back</a></div>\n";

        $page_title = empty($exception) ? 'Fatal Error - ' . Dj_App::NAME : 'Error - DjebelApp';
        $echo_prefix = empty($exception) ? 'Djebel Fatal Error: ' : 'Djebel Error: ';

        $options = [
            'status_code' => 500,
        ];

        // Last resort: the error page itself must never fail silently.
        try {
            Dj_App_HTML::renderPage($content, $page_title, $options);
        } catch (Throwable $render_exception) {
            echo $echo_prefix . $display_msg_esc;
        }

        return true;
    }

    public function installHooks()
    {
        $render_gen = Dj_App_Config::cfg('app.core.output.render_generator', true);

        if ($render_gen) {
            Dj_App_Hooks::addAction( 'app.page.html.head', [ $this, 'injectGenerator' ], 100 );
        }

        Dj_App_Hooks::addFilter( 'app.page.full_content', 'Dj_App_Util::injectBodyClasses', 100 );
        Dj_App_Hooks::addFilter( 'app.page.full_content', 'Dj_App_Util::autoInjectSysHookContent', 125 );

        // Output headers via system hook
        $req_obj = Dj_App_Request::getInstance();

        Dj_App_Hooks::addAction( 'app.page.output_http_headers', [ $req_obj, 'outputHeaders'] );

        // run this again in case new headers were added by the functions.php
        Dj_App_Hooks::addAction( 'app.core.theme.functions_loaded', [ $req_obj, 'outputHeaders'] );

        // Conditional plugin loading filter
        Dj_App_Hooks::addFilter( 'app.plugin.options', [ $this, 'filterConditionalPlugins' ], 10, 2 );
    }

    /**
     * Filter for conditional plugin loading based on URL patterns
     * @param array $plugins_options
     * @param array $ctx
     * @return array
     */
    public function filterConditionalPlugins($plugins_options, $ctx)
    {
        $req_obj = Dj_App_Request::getInstance();
        $current_url = $req_obj->getCleanRequestUrl();

        if (empty($ctx['plugin_id']) || empty($current_url)) {
            return $plugins_options;
        }

        $plugin_id = $ctx['plugin_id'];

        if (!empty($plugins_options[$plugin_id]['load_if_url'])) {
            $load_if_url = $plugins_options[$plugin_id]['load_if_url'];
        } else {
            $load_if_url = Dj_App_Config::cfg("plugins.{$plugin_id}.load_if_url");
        }

        if (empty($load_if_url)) {
            return $plugins_options;
        }

        $patterns = explode('|', $load_if_url);
        $patterns = Dj_App_String_Util::trim($patterns);
        $matched = false;

        // If the url matches we'll load it otherwise no.
        foreach ($patterns as $pattern) {
            if (strpos($current_url, $pattern) !== false) {
                $matched = true;
                break;
            }
        }

        $plugins_options[$plugin_id]['active'] = $matched ? true : false;

        return $plugins_options;
    }

    /**
     * Directly outputs the generator meta tag.
     * @return void
     */
    public function injectGenerator()
    {
        $generator = sprintf( '<meta name="generator" content="%s" />' . "\n", Dj_App::NAME );
        echo $generator;
    }
}