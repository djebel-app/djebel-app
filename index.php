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

$app_conf_dir = Dj_App_Util::getCoreConfDir();
$config_env_file = Dj_App_Config::cfg('env_file', $app_conf_dir . '/.env');

$app_env = Dj_App_Config::cfg('env'); // env specific conf?

if (!empty($app_env)) {
    $app_env_fmt = Dj_App_String_Util::formatStringId($app_env);
    $config_env_file_alt = $app_conf_dir . '/.env_' . $app_env_fmt;

    if (file_exists($config_env_file)) {
        $config_env_file = $config_env_file_alt;
    }
}

$env_cfg_data = Dj_App_Config::loadIniFile($config_env_file);

// Initialize global error handlers
set_exception_handler(['Dj_App_Bootstrap', 'handleException']);
register_shutdown_function(['Dj_App_Bootstrap', 'handleFatalError']);

Dj_App_Util::time( 'dj_app_timer' );

require_once $app_lib_dir . '/page.php';
require_once $app_lib_dir . '/plugins.php';

Dj_App_Hooks::doAction( 'app.core.lib.loaded' );

$app_load_options = Dj_App_Config::cfg('app.core.options.load', true);
$options_obj = Dj_App_Options::getInstance();

if ($app_load_options) {
    $options_obj->load();
    Dj_App_Hooks::doAction( 'app.core.options.loaded' );
}

// @todo should this be a plugin or a core feature?
$app_load_shortcodes = Dj_App_Config::cfg('app.core.shortcodes.load', true);

if ($app_load_shortcodes) {
    require_once $app_lib_dir . '/shortcode.php';
    $shortcode_obj = Dj_App_Shortcode::getInstance();
    $shortcode_obj->installHooks();
    Dj_App_Hooks::doAction( 'app.core.shortcodes.loaded' );
}

// should we run?
$run_app = Dj_App_Config::cfg('app.core.run', true);

if (empty($run_app)) {
    return;
}

$boostrap_obj = Dj_App_Bootstrap::getInstance();
$boostrap_obj->installHooks();

$req_obj = Dj_App_Request::getInstance();

$app_load_admin = false;
$app_load_admin = Dj_App_Env::getEnvConst('DJEBEL_APP_ADMIN_LOAD_ADMIN');

if (Dj_App_Util::isEnabled($app_load_admin)) {
    $app_load_admin = true;
} else {
    $app_load_admin = Dj_App_Config::cfg('app.core.load_admin', false);
}

if (empty($app_load_admin)) {
    $app_load_admin = Dj_App_Hooks::applyFilter('app.core.admin.load_admin', $app_load_admin);
}

if ($app_load_admin) {
    if ($req_obj->isAdminArea()) {
        $admin_dir = Dj_App_Util::getAdminDir();
        $core_plugins_dir = Dj_App_Plugins::getCorePluginsDir();
        Dj_App_Plugins::loadPlugins($core_plugins_dir, [ 'is_core' => true, 'ctx' => 'admin', ]);
    }
}

// Loading system plugins
$sys_plugins_dir = Dj_App_Plugins::getSysPluginsDir();

if (!empty($sys_plugins_dir) && is_dir($sys_plugins_dir)) {
    Dj_App_Hooks::doAction( 'app.core.system_plugins.pre_load' );
    Dj_App_Plugins::loadPlugins($sys_plugins_dir, [ 'is_system' => true, ]);
    Dj_App_Hooks::doAction( 'app.core.system_plugins.loaded' );
}

$ctx = [];

/*if (is_file($dj_app_core_dir . '/vendor/autoload.php')) {
    require_once $dj_app_core_dir . '/vendor/autoload.php';
}*/

$plugin_dirs = [];

// these are plugins that run for all sites on the server.
$app_core_shared_plugins_dir = Dj_App_Plugins::getSharedPluginsDir();
$load_core_shared_plugins = Dj_App_Hooks::applyFilter( 'app.core.plugins.load_shared_plugins', !empty($app_core_shared_plugins_dir) );

if (Dj_App_Util::isEnabled($load_core_shared_plugins) && !empty($app_core_shared_plugins_dir)) {
    $plugin_dirs[] = $app_core_shared_plugins_dir;
}

// Add non-public plugins if enabled
$load_non_public_plugins = Dj_App_Config::cfg('app.core.plugins.load_non_public_plugins', 0);

if (Dj_App_Util::isEnabled($load_non_public_plugins)) {
    $plugin_dirs[] = Dj_App_Plugins::getNonPublicPluginsDir();
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
        $plugin_load_res_obj = Dj_App_Plugins::loadPlugins($plugin_dir);
    }

    Dj_App_Hooks::doAction( 'app.core.plugins.loaded', $ctx );
}

Dj_App_Hooks::doAction( 'app.core.init' );

// Output headers via system hook
Dj_App_Hooks::doAction('app.page.output_http_headers');

// in case we want to block admin access
$load_admin_env = Dj_App_Env::getEnvConst('DJEBEL_APP_ADMIN_LOAD_ADMIN');
$load_admin = false;
$load_admin = Dj_App_Util::isEnabled($load_admin_env) ? true : false;
$load_admin = Dj_App_Hooks::applyFilter('app.core.admin.load_admin', $load_admin);

if ($req_obj->isAdminArea()) {
    if ($app_load_admin) {
        Dj_App_Hooks::doAction('app.core.admin.init');
        //require_once $dj_app_sys_dir . '/admin/index.php';
        //Dj_App_Hooks::doAction('app.core.admin.post_init');
    } else {
        Dj_App_Util::die("Resource not available.", "Error", ['code' => 503,]);
    }
} else {
    $load_theme_env = Dj_App_Env::getEnvConst('DJEBEL_APP_THEME_LOAD_THEME');
    $load_theme = Dj_App_Util::isDisabled($load_theme_env) ? false : true;
    $load_theme = Dj_App_Hooks::applyFilter('app.core.theme.load_theme', $load_theme);

    if ($load_theme) {
        require_once $app_lib_dir . '/themes.php';
        $themes_obj = Dj_App_Themes::getInstance();
        $themes_obj->installThemeHooks();
        $themes_obj->loadCurrentTheme();
        Dj_App_Hooks::doAction( 'app.core.theme.theme_loaded' );
    } else {
        // this is a code duplication from themes.php until we refactor it.
        ob_start();
        Dj_App_Hooks::doAction( 'app.core.theme.theme_not_loaded' );
        // we have to call this so it's rendered by whatever plugin is handling it
        Dj_App_Hooks::doAction( 'app.page.content.render' );
        $content = ob_get_clean();
        $content = Dj_App_Hooks::applyFilter( 'app.page.content', $content );
        $content = trim($content);

        $content = Dj_App_Hooks::applyFilter( 'app.page.full_content', $content );

        echo $content;
    }
}

$exec_time = Dj_App_Util::time( 'dj_app_timer' ); // move this to shutdown

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

    const INI_LOAD_SET_ENV = 2;

    /**
     * Load an ini file and set the env vars.
     * Dj_App_Config::loadIniFile()
     * @param $file
     * @return array
     */
    public static function loadIniFile($file, $flags = self::INI_LOAD_SET_ENV) {
        $data = [];

        if (empty($file) || !file_exists($file)) {
            return $data;
        }

        $env_vars = parse_ini_file($file, false, INI_SCANNER_TYPED);
        $env_vars = array_change_key_case($env_vars, CASE_UPPER);

        if ($flags & self::INI_LOAD_SET_ENV) {
            foreach ($env_vars as $key => $val) {
                putenv($key . '=' . $val);
            }
        }
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

    /**
     * Handle uncaught exceptions
     */
    public static function handleException($exception) {
        if (!$exception instanceof Throwable) {
            return;
        }
        
        $is_dev = Dj_App_Config::cfg('app.debug', false);
        $log_errors = Dj_App_Config::cfg('app.error_logging', true);
        $error_log_file = Dj_App_Config::cfg('app.error_log_file', DJEBEL_APP_BASE_DIR . '/error.log');
        
        // Log the exception
        if ($log_errors && !empty($error_log_file)) {
            $timestamp = date('Y-m-d H:i:s');
            $log_entry = "[$timestamp] Exception: " . $exception->getMessage() . 
                        " in " . $exception->getFile() . " on line " . $exception->getLine() . 
                        "\nStack trace:\n" . $exception->getTraceAsString() . 
                        "\n" . str_repeat('-', 80) . "\n";
            error_log($log_entry, 3, $error_log_file);
        }
        
        $content = '<h1 class="djebel-app-error-title">Uncaught Exception</h1>';
        $content .= '<div class="djebel-app-error-message">' . htmlspecialchars($exception->getMessage()) . '</div>';
        
        if ($is_dev) {
            $content .= '<div class="djebel-app-error-details">';
            $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">Exception:</div><div class="djebel-app-detail-value">' . htmlspecialchars(get_class($exception)) . '</div></div>';
            $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">File:</div><div class="djebel-app-detail-value">' . htmlspecialchars($exception->getFile()) . '</div></div>';
            $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">Line:</div><div class="djebel-app-detail-value">' . $exception->getLine() . '</div></div>';
            $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">Code:</div><div class="djebel-app-detail-value">' . $exception->getCode() . '</div></div>';
            $content .= '</div>';
            $content .= '<div class="djebel-app-trace">' . htmlspecialchars($exception->getTraceAsString()) . '</div>';
        }
        
        $content .= '<div class="djebel-app-back-link"><a href="javascript:history.back()">← Go Back</a></div>';
        
        $options = [
            'status_code' => 500,
        ];
        
        Djebel_App_HTML::renderPage($content, 'Error - Djebel CMS', $options);
    }

    /**
     * Handle fatal errors
     */
    public static function handleFatalError() {
        $error = error_get_last();
        
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $is_dev = Dj_App_Config::cfg('app.debug', false);
            
            $content = '<h1 class="djebel-app-error-title">Fatal Error</h1>';
            $content .= '<div class="djebel-app-error-message">' . htmlspecialchars($error['message']) . '</div>';
            
            if ($is_dev) {
                $content .= '<div class="djebel-app-error-details">';
                $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">File:</div><div class="djebel-app-detail-value">' . htmlspecialchars($error['file']) . '</div></div>';
                $content .= '<div class="djebel-app-detail-item"><div class="djebel-app-detail-label">Line:</div><div class="djebel-app-detail-value">' . $error['line'] . '</div></div>';
                $content .= '</div>';
            }
            
            $content .= '<div class="djebel-app-back-link"><a href="javascript:history.back()">← Go Back</a></div>';
            
            $options = [
                'status_code' => 500,
            ];
            
            Djebel_App_HTML::renderPage($content, 'Fatal Error - Djebel CMS', $options);
        }
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