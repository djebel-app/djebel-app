<?php
/**
 * Loads the options. Options can be key, value, auto_load=0
 * they can be loaded from ini file and the db
 */

class Dj_App_Options implements ArrayAccess {
    const SECTION_SEP = '::';

    protected $data = [];
    protected $extra_opts_data = [];

    private function __construct() {}

    public function load()
    {
        if (!is_null($this->data)) {
            return $this->data;
        }

        $config_dir = Dj_App_Util::getCoreConfDir();
        $file = $config_dir . '/app.ini';
        $file = Dj_App_Hooks::applyFilter( 'app.core.filter.config_file', $file );

        $ctx = [
            'file' => $file,
        ];

        $data = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_load_config_file', null, $ctx );

        if (!is_null($data)) {
            $this->data = $data;
            return $this->data;
        }

        $data = []; // this means we've loaded the file or at least tried to load it.

        if (file_exists($file)) {
            $buff = Dj_App_File_Util::read($file);
            $data = $this->parseBuffer($buff);
            $data = empty($data) ? [] : $data;
        }

        $data = Dj_App_Hooks::applyFilter( 'app.core.filter.options', $data );
        $this->data = $data;

        $load_extra_opts = Dj_App_Hooks::applyFilter( 'app.core.options.load_extra_conf_files', false );

        if (!empty($load_extra_opts)) { // let's use glob to load all other options files when when really needed
            $extra_opt_files = glob(Dj_App_Util::getCoreConfDir() . '/*.ini');
            $extra_opt_files = empty($extra_opt_files) ? [] : $extra_opt_files;
            $extra_opt_files = array_diff($extra_opt_files, [ $file ]); // rm the main options file

            foreach ($extra_opt_files as $extra_opt_file) {
                $buff = Dj_App_File_Util::read($extra_opt_file);
                $raw_data = $this->parseBuffer($buff);
                $raw_data = empty($raw_data) ? [] : $raw_data;

                $key = basename($extra_opt_file, '.ini');
                $key = strtolower($key);
                $this->extra_opts_data[$key] = $raw_data;
            }
        }

        return $data;
    }

    /**
     * @param string|array $lines_or_buff
     * @return array
     */
    public function parseBuffer($lines_or_buff) {
        $data = [];

        if (empty($lines_or_buff)) {
            return [];
        }

        if (is_array($lines_or_buff)) {
            $lines = $lines_or_buff;
        } elseif (is_scalar($lines_or_buff)) {
            $lines_or_buff = (string) $lines_or_buff;
            $lines_or_buff = str_replace("\0", '', $lines_or_buff);
            $lines_or_buff = Dj_App_String_Util::normalizeNewLines($lines_or_buff);
            $lines = explode("\n", $lines_or_buff);
        } else {
            return $data;
        }

        $lines = array_filter( $lines );

        // Pre-process lines to add section prefix
        $lines = $this->preprocessLines($lines);

        foreach ($lines as $line) {
            $res = $this->parseLine($line);

            if (empty($res)) {
                continue;
            }

            $data = array_merge_recursive($data, $res);
        }

        return $data;
    }

    /**
     * Pre-process lines to prefix each with its section
     * @param array $lines
     * @return array
     */
    private function preprocessLines($lines) {
        $current_section = '';
        $processed_lines = [];

        foreach ($lines as $line) {
            $line = trim($line);
            $first_char = substr($line, 0, 1);

            // Skip empty lines and comments
            if (empty($line)
                || $first_char == '#'
                || $first_char == ';'
                || ($first_char == '/' && substr($line, 1, 1) == '/')
            ) {
                continue;
            }

            // Check if this is a section header
            if ($first_char == '[' && substr($line, -1) == ']') {
                $current_section = Dj_App_String_Util::trim($line, '[]');
                continue;
            }

            // Prefix line with section if we have one
            if (!empty($current_section)) {
                $line = $current_section . self::SECTION_SEP . $line;
            }

            $processed_lines[] = $line;
        }

        return $processed_lines;
    }

    /**
     * meta.default.title
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     * @throws Exception
     */
    public function get($key, $default = '')
    {
        // Use current data if available, otherwise load
        $data = $this->data;

        if (is_null($data)) {
            $data = $this->load();
        }

        $ctx = [ 'key' => $key, 'default' => $default, ];

        // some plugins might want to override the value
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_option', false, $ctx );

        if ($val !== false) {
            return $val;
        }

        $section = '';
        $sub_section = '';

        // if section is empty but key has section.key then we'll take that as a section
        if (strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $section = array_shift($parts);
            $section = Dj_App_String_Util::formatKey($section);

            if (count($parts) > 1) { // we've taken the main section. Do we have sub section?
                $sub_section = array_shift($parts);
                $sub_section = Dj_App_String_Util::formatKey($sub_section);
            }

            $key = implode('.', $parts);
        }

        $ctx['key'] = $key;
        $ctx['section'] = $section;
        $ctx['sub_section'] = $sub_section;

        // option specific filter again
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_option_' . $key, false, $ctx );

        if ($val !== false) {
            return $val;
        }

        $val = '';

        if (!empty($sub_section) && isset($data[$section][$sub_section][$key])) {
            $val = $data[$section][$sub_section][$key];
        } elseif (!empty($section) && isset($data[$section][$key])) {
            $val = $data[$section][$key];
        } elseif (isset($data[$key])) {
            $val = $data[$key];
        }

        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.option', $val, $ctx );
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.option_' . $key, $val, $ctx );

        // Return default if value is empty and default is provided
        if (empty($val) && $default !== '') {
            return $default;
        }

        return $val;
    }

    /**
     * Convenience method to check if an option is enabled
     * @param string $key
     * @param mixed $default
     * @return bool
     */
    public function isEnabled($key, $default = false)
    {
        $val = $this->get($key, $default);
        return Dj_App_Util::isEnabled($val);
    }

    /**
     * Convenience method to check if an option is disabled
     * @param string $key
     * @param mixed $default
     * @return bool
     */
    public function isDisabled($key, $default = true)
    {
        $val = $this->get($key, $default);
        return Dj_App_Util::isDisabled($val);
    }

    /**
     * Returns member data or a key from data. It's easier e.g. $data_res->output
     * Supports nested access by returning the options object itself
     * @param string $name
     * @return mixed|null|Dj_App_Options
     */
    public function __get($name) {
        // Use current data - don't call load() to avoid overriding test data
        $data = $this->data;

        // Check if the name exists as a direct key in the data
        if (isset($data[$name])) {
            $val = $data[$name];

            // If the value is an array, return a new options instance for nested access
            if (is_array($val)) {
                $nested_options = new static();
                $nested_options->data = $val;
                return $nested_options;
            }

            return $val;
        }

        // Fallback to the get() method for other cases
        $val = $this->get($name);

        if (empty($val)) {
            $empty_options = new static();
            $empty_options->data = [];
            return $empty_options;
        }

        return $val;
    }

    public function __set($key, $val) {
        $ctx = [ 'key' => $key, 'val' => $val, ];
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_set_option', $val, $ctx );
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_set_option_' . $key, $val, $ctx );
        $this->data[$key] = $val;
    }

    /**
     * Checks if a data property exists
     * @param string $key Property key
     */
    public function __isset($key) {
        return !is_null($this->__get($key));
    }

    /**
     * Convert to string when Options object is used in string context
     * @return string
     */
    public function __toString() {
        if (empty($this->data)) {
            return '';
        }

        if (is_scalar($this->data)) {
            return (string) $this->data;
        }

        return '';
    }

    /**
     * Parses a line from INI file supporting sections and array notation
     * @param string $line
     * @return array
     */
    public function parseLine($line) {
        $data = [];

        // Extract section prefix if present
        $section = '';
        $prefix_pos = strpos($line, self::SECTION_SEP);

        if ($prefix_pos !== false) {
            $section = substr($line, 0, $prefix_pos);
            $sep_len = strlen(self::SECTION_SEP);
            $line = substr($line, $prefix_pos + $sep_len);
        }

        $eq_pos = strpos($line, '=');

        if ($eq_pos === false) {
            return [];
        }

        $key = substr($line, 0, $eq_pos);
        $val = substr($line, $eq_pos + 1);

        // Handle comments in values
        $pos = strpos($val, '#');

        if ($pos !== false && substr($val, $pos - 1, 1) !== "\\" ) {
            $val = substr($val, 0, $pos);
        }

        $val = Dj_App_String_Util::trim($val, '\'"');

        // Remove spaces from key
        $key = str_replace(' ', '', $key);

        // Normalize bracket notation to dot notation
        $bracket_pos = strpos($key, '[');

        if ($bracket_pos !== false) {
            // Handle slashes in bracket keys first
            $has_slash = strpos($key, '/');

            if ($has_slash !== false) {
                $key = str_replace('/', '__SLASH__', $key);
            }

            // Check for auto-increment: var[]
            $empty_bracket_pos = strpos($key, '[]');

            if ($empty_bracket_pos !== false) {
                // Auto-increment: var[] = value
                $main_key = substr($key, 0, $empty_bracket_pos);
                $main_key = Dj_App_String_Util::formatKey($main_key);

                if ($section) {
                    $data[$section][$main_key] = empty($data[$section][$main_key]) ? [] : (array) $data[$section][$main_key];
                    $data[$section][$main_key][] = $val;
                } else {
                    $data[$main_key] = empty($data[$main_key]) ? [] : (array) $data[$main_key];
                    $data[$main_key][] = $val;
                }

                return $data;
            }

            // Convert bracket notation to dot notation: ][, [, ] all become dots
            // site2[id] → site2.id. → site2.id (after trim)
            // site2[id][name] → site2[id.name] → site2.id.name. → site2.id.name (after trim)
            $key = str_replace(['][', '[', ']'], '.', $key);
            $key = Dj_App_String_Util::trim($key, '.[]');
        }

        // Dot notation: site.title
        $dot_pos = strpos($key, '.');

        if ($dot_pos !== false) {
            // Sanitize key: remove invalid characters, keep only word chars, dots, and hyphens
            $key = preg_replace('/[^\w\.\-]/si', '', $key);
            $keys = explode('.', $key);

            if (count($keys) >= 2) {
                foreach ($keys as $i => $k) {
                    if (strpos($k, '__SLASH__') !== false) {
                        $keys[$i] = str_replace('__SLASH__', '/', $k);
                    } else {
                        $keys[$i] = Dj_App_String_Util::formatKey($k);
                    }
                }

                // Prepend section to keys if present
                if ($section) {
                    array_unshift($keys, $section);
                }

                // Fast explicit level handling - ensure each level is an array
                $level = count($keys);

                if ($level == 2) {
                    if (empty($data[$keys[0]]) || !is_array($data[$keys[0]])) {
                        $data[$keys[0]] = [];
                    }
                    $data[$keys[0]][$keys[1]] = $val;
                } elseif ($level == 3) {
                    if (empty($data[$keys[0]]) || !is_array($data[$keys[0]])) {
                        $data[$keys[0]] = [];
                    }
                    if (empty($data[$keys[0]][$keys[1]]) || !is_array($data[$keys[0]][$keys[1]])) {
                        $data[$keys[0]][$keys[1]] = [];
                    }
                    $data[$keys[0]][$keys[1]][$keys[2]] = $val;
                } elseif ($level == 4) {
                    if (empty($data[$keys[0]]) || !is_array($data[$keys[0]])) {
                        $data[$keys[0]] = [];
                    }
                    if (empty($data[$keys[0]][$keys[1]]) || !is_array($data[$keys[0]][$keys[1]])) {
                        $data[$keys[0]][$keys[1]] = [];
                    }
                    if (empty($data[$keys[0]][$keys[1]][$keys[2]]) || !is_array($data[$keys[0]][$keys[1]][$keys[2]])) {
                        $data[$keys[0]][$keys[1]][$keys[2]] = [];
                    }
                    $data[$keys[0]][$keys[1]][$keys[2]][$keys[3]] = $val;
                }

                return $data;
            }
        }

        $key = Dj_App_String_Util::formatKey($key);

        if ($section) {
            $data[$section][$key] = $val;
        } else {
            $data[$key] = $val;
        }

        return $data;
    }

    /**
     * This clears the data. Useful for testing
     * @return void
     */
    public function clear()
    {
        $this->data = null;
        $this->extra_opts_data = [];
    }

    /**
     * Set data directly for testing purposes
     * @param array $data
     * @return void
     */
    public function setData($data)
    {
        $this->data = $data;
        $this->extra_opts_data = [];
    }

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

    // Simple ArrayAccess implementation
    public function offsetSet($offset, $value): void {
        $this->data[$offset] = $value;
    }

    public function offsetExists($offset): bool {
        return isset($this->data[$offset]);
    }

    public function offsetUnset($offset): void {
        unset($this->data[$offset]);
    }

    /**
     * ArrayAccess interface method - suppress deprecation notice for return type compatibility
     * PHP 8.1+ expects mixed return type, but we need to maintain PHP 7.x compatibility
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset) {
        return $this->__get($offset);
    }
}
