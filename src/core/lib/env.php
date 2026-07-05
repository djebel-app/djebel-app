<?php

/**
 * Environment related functions.
 */
class Dj_App_Env {
    /**
     * Dj_App_Env::isLinux();
     * @return bool
     */
    static public function isLinux() {
        return preg_match('#linux#si', PHP_OS);
    }

    /**
     * Dj_App_Env::isWindows();
     * @return bool
     */
    static public function isWindows() {
        return preg_match('#win#si', PHP_OS);
    }

    /**
     * Determins if this is a dev environment.
     * Dj_App_Env::isDev();
     * @return bool
     */
    static public function isDev() {
        if (!empty($_SERVER['DESKTOP_SESSION'])) { // vm env
            return true;
        }

        $dj_app_env = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');

        if (empty($dj_app_env)) {
            return false;
        }

        if (in_array($dj_app_env, [ 'dev', 'development'])) {
            return true;
        }

        return false;
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
     * Returns true if the script runs on a windows machine.
     * Dj_App_Env::isCli();
     * @return bool
     */
    static public function isCli() {
        $yes = (stripos(php_sapi_name(), 'cli') !== false)
            || (defined('PHP_SAPI') && PHP_SAPI === 'cli'); // JIC
        return $yes;
    }

    /**
     * Returns true if the script runs on a windows machine.
     * Dj_App_Env::isWebRequest();
     * @return bool
     */
    public static function isWebRequest() {
        return !self::isCli() && !empty($_SERVER['REQUEST_METHOD']) && !empty($_SERVER['REQUEST_URI']);
    }

    public static function isLive() {
        $dj_app_env = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');

        if (empty($dj_app_env)) {
            return true;
        }

        if (in_array($dj_app_env, [ 'live', 'prod', 'production'])) {
            return true;
        }

        return !self::isDev();
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
        $dj_app_env = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');
        $s = stripos( $dj_app_env, 'staging' ) !== false;

        return $s;
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
     * Gets the value of an environment variable.
     * Dj_App_Env::getEnv();
     * @param string $key
     * @return string
     */
    public static function getEnv($key)
    {
        $key_fmt = strtoupper($key);
        $val = getenv($key_fmt);

        // false = not set. A real '0' value must survive — empty() would eat it.
        $val = $val === false ? '' : $val;

        if (!strlen($val) && isset($_SERVER[$key_fmt]) && is_scalar($_SERVER[$key_fmt])) {
            $val = (string) $_SERVER[$key_fmt];
        }

        // clear spaces & some optional quotes that may have been inserted.
        $val = trim($val, " \t\n\r\0\x0B\'\"");

        return $val;
    }

    /**
     * Searches for a value in an environment variable or in a constant or defaults to a value.
     * Accepts CSV fallback keys — 'DJEBEL_APP_ENV,APP_ENV' — first non-empty wins.
     * Dj_App_Env::getEnvConst();
     * @param string $key
     * @param mixed $defalt
     * @return string
     */
    public static function getEnvConst($key, $defalt = '')
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
        $val = strlen($val) ? $val : $defalt;

        // Call replaceSystemVars from Dj_App_Config since it's available during bootstrap
        $val = Dj_App_Config::replaceSystemVars($val);

        return $val;
    }
}
