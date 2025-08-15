<?php

use Doctrine\DBAL\DriverManager;

class Dj_App_Db {
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

    /**
     * ->get('db_host');
     * @param string $field
     * @param string $default
     * @return string
     */
    public function get($field, $default = '')
    {
        $env_val = getenv($field);

        $db_prefix = Dj_App_Config::cfg('db_prefix', 'dj_');

        if (empty($env_val)) {
            if (strpos($field, $db_prefix) === 0) { // starts with
                $fmt_field = $field;
            } else {
                $fmt_field = $db_prefix . $field;
            }

            $fmt_field = strtoupper($fmt_field);
            $env_val = getenv($fmt_field);
        }

        if (empty($env_val)) {
            $val = defined($fmt_field) ? constant($fmt_field) : $default;
        } else {
            $val = $env_val;
        }

        $val = Dj_App_Hooks::applyFilter( 'app.core.db.field', $val, $field );

        return $val;
    }

    private $supported_drivers = ['mysql', 'pgsql', 'sqlite'];

    public function getConnection()
    {
        try {
            $pdo = null;
            $db_driver = $this->get('db_driver');

            if (!in_array($db_driver, $this->supported_drivers)) {
                throw new QS_App_WP5_Db_Exception("Unsupported database driver: $db_driver");
            }

            $db_host = $this->get('db_host', '127.0.0.1');
            $db_name = $this->get('db_name');
            $db_user = $this->get('db_user');
            $db_pass = $this->get('db_pass');
            $db_port = $this->get('db_port');
            $db_table_prefix = $this->get('db_table_prefix');

            $db_name_suffix = '';

            if (!empty($db_name)) {
                $db_name_suffix = ";dbname=$db_name";
            }

            if ($db_driver === 'mysql') {
                $pdo = new PDO("mysql:host=$db_host{$db_name_suffix}", $db_user, $db_pass);
            } elseif ($db_driver === 'pgsql') {
                $pdo = new PDO("pgsql:host=$db_host;port=$db_port;user=$db_user;password=$db_pass{$db_name_suffix}");
            } elseif ($db_driver === 'sqlite') {
                $sqlite_db_path = Dj_App_Util::getContentDir() . '/.ht_site_db.sqlite';
                $sqlite_db_path = Dj_App_Hooks::applyFilter( 'app.core.db.sqlite.db_file', $sqlite_db_path );
                $pdo = new PDO("sqlite:$sqlite_db_path");
            }
        } catch (PDOException $e) {
            // @todo log this
            Dj_App_Util::die( "Database Connection Error", "Database Error");
        }

        return $pdo;
    }
}

class QS_App_WP5_Db_Exception extends Dj_App_Exception {}
