<?php

/**
 *
 */
class Dj_App_Cache {

    /**
     * @var array
     */
    public function get($key)
    {

    }

    /**
     * @var array
     */
    public function set($key, $val, $extra_opts = [])
    {
        $ttl = isset($extra_opts['ttl']) ? $extra_opts['ttl'] : 24 * 60 * 60;
        $val = is_scalar($val) ? $val : serialize($val);
    }

    /**
     * Singleton pattern i.e. we have only one instance of this obj
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
}

