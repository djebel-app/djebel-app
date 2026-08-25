<?php

/**
 * Environment related functions.
 */
class Dj_App_Env {
    // The environment NAME, after the config has spoken. A listener answers for every app at
    // once — by host, by install dir, by whatever a fleet decides — instead of each install
    // carrying the answer in its own .env.
    const FILTER_ENV_NAME = 'app.core.env.filter.name';

    // Resolved once per request. Every predicate below asks the same question, and answering
    // it means an env scan plus a hook dispatch — paid four times over when each asked alone.
    // Null until asked; set() clears it, because that is where the answer can change.
    private static $env_name = null;

    /**
     * The environment this install is running as — 'dev', 'staging', 'live', or whatever a
     * site calls its own. Empty when nothing has declared one.
     *
     * Read the predicates below rather than comparing this by hand: 'live', 'prod' and
     * 'production' all mean the same thing, and only isLive() knows that.
     *
     * @return string Lowercased; empty when undeclared
     */
    public static function getAppEnv()
    {
        if (!is_null(Dj_App_Env::$env_name)) {
            return Dj_App_Env::$env_name;
        }

        $env_name = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');

        // A desktop session means a workstation, and a workstation is a dev box. It is only a
        // DEFAULT now: an install that declares an environment, and a listener that overrides
        // one, both outrank it — where before it beat everything and could not be argued with.
        if (empty($env_name) && !empty($_SERVER['DESKTOP_SESSION'])) {
            $env_name = 'dev';
        }

        // Hooks load after this file, so an env question asked during bootstrap has no seam to
        // pass through. Such an answer is deliberately NOT remembered either — the value taken
        // before the filter existed would otherwise outlive it for the whole request.
        $has_hooks = class_exists('Dj_App_Hooks');

        if ($has_hooks) {
            $env_name = Dj_App_Hooks::applyFilter(Dj_App_Env::FILTER_ENV_NAME, $env_name);
        }

        $env_name = Dj_App_String_Util::formatStringId($env_name);

        if ($has_hooks) {
            Dj_App_Env::$env_name = $env_name;
        }

        return $env_name;
    }

    /**
     * Dj_App_Env::isLinux();
     * @return bool
     */
    static public function isLinux() {
        $is_linux = PHP_OS_FAMILY == 'Linux';

        return $is_linux;
    }

    /**
     * Dj_App_Env::isWindows();
     * @return bool
     */
    static public function isWindows() {
        // PHP_OS_FAMILY (php 7.2+) is exact — matching 'win' inside PHP_OS also hits Darwin (macOS).
        $is_windows = PHP_OS_FAMILY == 'Windows';

        return $is_windows;
    }

    /**
     * Determines if this is a dev environment.
     * Dj_App_Env::isDev();
     * @return bool
     */
    static public function isDev() {
        $dj_app_env = Dj_App_Env::getAppEnv();

        if (empty($dj_app_env)) {
            return false;
        }

        $dev_names = [ 'dev', 'development', ];
        $is_dev = in_array($dj_app_env, $dev_names);

        return $is_dev;
    }

    /**
     * Determines if the site is accessed by a developer from a known IP.
     * Dj_App_Env::isDevIP();
     * @todo create another function to get the IP because the server may be behind a proxy.
     * @return bool
     */
    static public function isDevIP() {
        if (empty($_SERVER['REMOTE_ADDR'])) {
            return false;
        }

        $dev_ips = Dj_App_Env::getEnvConst('DJEBEL_APP_DEV_IPS');

        if (empty($dev_ips)) {
            return false;
        }

        $ips = Dj_App_String_Util::splitOnSeparators($dev_ips);

        if (in_array($_SERVER['REMOTE_ADDR'], $ips)) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the script runs from the command line (CLI).
     * Dj_App_Env::isCli();
     * @return bool
     */
    static public function isCli() {
        // PHP_SAPI is a core constant — always defined, and read without a function call.
        // Matched EXACTLY: 'cli-server' is the built-in web server, which serves real HTTP
        // requests, so a substring match would hand it the command-line answer.
        $is_cli = PHP_SAPI == 'cli';

        return $is_cli;
    }

    /**
     * Returns true if the script runs as a web request.
     * Dj_App_Env::isWebRequest();
     * @return bool
     */
    public static function isWebRequest() {
        // The two superglobal reads are free language constructs; isCli() is a call into
        // php_sapi_name(), so it only runs once the cheap pair has failed to reject.
        if (empty($_SERVER['REQUEST_METHOD']) || empty($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $is_cli = Dj_App_Env::isCli();
        $is_web_request = empty($is_cli);

        return $is_web_request;
    }

    public static function isLive() {
        $dj_app_env = Dj_App_Env::getAppEnv();

        // Undeclared answers LIVE on purpose: an install that never said what it is gets the
        // careful treatment, not the permissive one.
        if (empty($dj_app_env)) {
            return true;
        }

        $live_names = [ 'live', 'prod', 'production', ];

        if (in_array($dj_app_env, $live_names)) {
            return true;
        }

        // Anything else named — staging included — is live in the sense this asks about: not
        // a developer's machine. isWorkEnv() is the one that separates staging from production.
        $is_dev = Dj_App_Env::isDev();
        $is_live = empty($is_dev);

        return $is_live;
    }

    /**
     * Dj_App_Env::isInRunningUnitTests();
     * https://stackoverflow.com/questions/10253240/how-to-determine-if-phpunit-tests-are-running
     * @return bool
     */
    public static function isInRunningUnitTests() {
        if (PHP_SAPI != 'cli') {
            return false;
        }

        if ( defined('PHPUNIT_COMPOSER_INSTALL') || defined('__PHPUNIT_PHAR__') ) {
            return true;
        }

        if (strpos($_SERVER['argv'][0], 'phpunit') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the code runs on a staging server by checking the ENV
     * Dj_App_Env::isStaging();
     * @return bool
     */
    static public function isStaging() {
        $dj_app_env = Dj_App_Env::getAppEnv();
        $is_staging = strpos($dj_app_env, 'staging') !== false;

        return $is_staging;
    }

    /**
     * Returns true if the script runs on a dev or staging env
     * Dj_App_Env::isWorkEnv();
     * @return bool
     */
    static public function isWorkEnv() {
        if ( Dj_App_Env::isDev() ) {
            return true;
        }

        if ( Dj_App_Env::isStaging() ) {
            return true;
        }

        return false;
    }

    /**
     * Gets the value of an environment variable. Accepts ALTERNATIVE names too —
     * an array or a CSV list ('DJEBEL_APP_ENV,APP_ENV') — the first SET value wins.
     * Dj_App_Env::getEnv();
     * @param string|array $key
     * @return string
     */
    public static function getEnv($key)
    {
        if (empty($key)) {
            return '';
        }

        // One or many: the splitter hands back one element for a plain name,
        // several for an array or CSV of alternatives — the first SET value wins.
        $keys = Dj_App_String_Util::splitOnSeparators($key);
        $val = '';

        foreach ($keys as $one_key) {
            $key_fmt = strtoupper($one_key);
            $env_value = getenv($key_fmt);

            // false = not set. A real '0' value must survive — empty() would eat it.
            $val = $env_value === false ? '' : $env_value;

            if (!strlen($val) && isset($_SERVER[$key_fmt]) && is_scalar($_SERVER[$key_fmt])) {
                $val = (string) $_SERVER[$key_fmt];
            }

            // clear spaces & some optional quotes that may have been inserted.
            $val = trim($val, " \t\n\r\0\x0B\'\"");

            if (strlen($val)) {
                break;
            }
        }

        return $val;
    }

    /**
     * Sets one or multiple env vars. First arg can be an array of key => value pairs.
     * Keys are uppercased to match getEnv() lookups. Omitting the value (or passing
     * null) REMOVES the variable.
     * Dj_App_Env::set();
     * @param string|array $key
     * @param string|null $val
     * @return bool
     */
    public static function set($key, $val = null)
    {
        if (empty($key)) {
            return false;
        }

        if (!is_array($key)) {
            $key = [ $key => $val, ];
        }

        // Any of these may BE the environment key, so the resolved name is dropped rather than
        // compared against — a suite that flips the env between cases would otherwise keep
        // answering with the one the first test happened to settle.
        Dj_App_Env::$env_name = null;

        $env_vars = array_change_key_case($key, CASE_UPPER);

        foreach ($env_vars as $env_key => $env_val) {
            // null = REMOVE the variable (putenv without '='); '' still sets an
            // empty value. Same convention as the dot-env setter.
            if (is_null($env_val)) {
                putenv($env_key);
                continue;
            }

            putenv($env_key . '=' . $env_val);
        }

        return true;
    }

    /**
     * Searches for a value in an environment variable or in a constant or defaults to a value.
     * Accepts CSV fallback keys — 'DJEBEL_APP_ENV,APP_ENV' — first non-empty wins.
     * Dj_App_Env::getEnvConst();
     * @param string $key
     * @param mixed $default
     * @return string
     */
    public static function getEnvConst($key, $default = '')
    {
        $keys = [ $key, ];

        if (strpos($key, ',') !== false) {
            $keys = Dj_App_String_Util::splitOnSeparators($key);
        }

        $val = '';

        foreach ($keys as $one_key) {
            $val = Dj_App_Env::getEnv($one_key);

            if (!strlen($val)) {
                $key_fmt = strtoupper($one_key);
                $val = defined($key_fmt) ? constant($key_fmt) : '';
                $val = (string) $val;
            }

            if (strlen($val)) {
                break;
            }
        }

        // strlen, not empty() — a legit '0' value must NOT fall through to the default.
        $val = strlen($val) ? $val : $default;

        // Call replaceSystemVars from Dj_App_Config since it's available during bootstrap
        $val = Dj_App_Config::replaceSystemVars($val);

        return $val;
    }
}
