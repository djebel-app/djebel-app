<?php
/**
 * Loads the options. Options can be key, value, auto_load=0
 * they can be loaded from ini file and the db
 */

class Dj_App_Options implements ArrayAccess {
    protected $data = null;
    protected $extra_opts_data = [];
    protected $current_section = '';

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
            $lines_or_buff = Dj_App_String_Util::normalizeNewLines($lines_or_buff);
            $lines = explode("\n", $lines_or_buff);
        } else {
            return $data;
        }

        $lines = array_filter( $lines );

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
                $nested_options->setData($val);
                return $nested_options;
            }
            
            return $val;
        }
        
        // Fallback to the get() method for other cases
        return $this->get($name);
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
     * Parses a line from INI file supporting sections and array notation
     * @param string $line
     * @return array
     */
    public function parseLine($line) {
        $data = [];

        $line = empty($line) ? '' : trim($line);
        $first_char = substr($line, 0, 1);

        // empty or single line comments - check these first as they're simpler operations
        if (empty($line) 
            || $first_char == '#' 
            || $first_char == ';' 
            || ($first_char == '/' && substr($line, 1, 1) == '/')
        ) {
            return [];
        }

        $line = str_replace("\0", '', $line); // no null bytes

        // Check for section after eliminating empty/comment lines
        if ($first_char == '[' && substr($line, -1) == ']') {
            $section_content = substr($line, 1, -1);
            $section_content = trim($section_content);
            $this->current_section = $section_content;
            return [];
        }

        $eq_pos = strpos($line, '=');

        if ($eq_pos === false) {
            return [];
        }

        $key = substr($line, 0, $eq_pos);
        $val = substr($line, $eq_pos + 1);

        // Handle comments in values
        $pos = strpos($val, '#');

        if ($pos !== false && substr($val, $pos - 1, 1) != "\\" ) {
            $val = substr($val, 0, $pos);
        }

        $val = trim($val, '\'" ');

        // Only run regex if we have special characters
        if (strpos($key, '[') !== false) {
            $key = preg_replace('/[^\w\[\]\.\-\'\"]/si', '', $key);
            
            // Handle array notation with auto-increment: var[] = value
            if (preg_match('/^([\w\-]+)\[\s*\]$/si', $key, $matches)) {
                $main_key = $matches[1];
                $main_key = Dj_App_String_Util::formatKey($main_key);
                
                if (!empty($this->current_section)) {
                    $data[$this->current_section][$main_key] = isset($data[$this->current_section][$main_key]) ? 
                        (array)$data[$this->current_section][$main_key] : [];
                    $data[$this->current_section][$main_key][] = $val;
                } else {
                    $data[$main_key] = isset($data[$main_key]) ? (array)$data[$main_key] : [];
                    $data[$main_key][] = $val;
                }
                
                return $data;
            }
            
            // Handle array notation with index: var[key] = value
            if (preg_match('/^([\w\-]+)\[[\s\'\"]*(\w+)[\s\'\"]*\](?:\[[\s\'\"]*(\w*)[\s\'\"]*\])?$/si', $key, $matches)) {
                $main_key = $matches[1];
                $main_key = Dj_App_String_Util::formatKey($main_key);

                $sub_key = $matches[2];
                $sub_key = Dj_App_String_Util::formatKey($sub_key);

                $third_key = !empty($matches[3]) ? $matches[3] : null;

                if ($third_key !== null) {
                    $third_key = Dj_App_String_Util::formatKey($third_key);
                }
                
                if (!empty($this->current_section)) {
                    if ($third_key !== null) {
                        if (!isset($data[$this->current_section][$main_key])) {
                            $data[$this->current_section][$main_key] = [];
                        }
                        if (!isset($data[$this->current_section][$main_key][$sub_key])) {
                            $data[$this->current_section][$main_key][$sub_key] = [];
                        }
                        $data[$this->current_section][$main_key][$sub_key][$third_key] = $val;
                    } else {
                        if (!isset($data[$this->current_section][$main_key])) {
                            $data[$this->current_section][$main_key] = [];
                        }
                        $data[$this->current_section][$main_key][$sub_key] = $val;
                    }
                } else {
                    if ($third_key !== null) {
                        if (!isset($data[$main_key])) {
                            $data[$main_key] = [];
                        }
                        if (!isset($data[$main_key][$sub_key])) {
                            $data[$main_key][$sub_key] = [];
                        }
                        $data[$main_key][$sub_key][$third_key] = $val;
                    } else {
                        if (!isset($data[$main_key])) {
                            $data[$main_key] = [];
                        }
                        $data[$main_key][$sub_key] = $val;
                    }
                }
                
                return $data;
            }
        } elseif (strpos($key, '.') !== false) {
            $key = preg_replace('/[^\w\.\-]/si', '', $key);
            $parts = explode('.', $key);

            if (count($parts) >= 2) {
                $main_key = array_shift($parts);
                $main_key = Dj_App_String_Util::formatKey($main_key);

                $sub_key = array_shift($parts);
                $sub_key = Dj_App_String_Util::formatKey($sub_key);
                
                if (!empty($this->current_section)) {
                    if (!isset($data[$this->current_section][$main_key])) {
                        $data[$this->current_section][$main_key] = [];
                    }
                    $data[$this->current_section][$main_key][$sub_key] = $val;
                } else {
                    if (!isset($data[$main_key])) {
                        $data[$main_key] = [];
                    }
                    $data[$main_key][$sub_key] = $val;
                }
                
                return $data;
            }
        }

        // Simple key cleanup for non-special keys
        $key = preg_replace('/[^\w\-]/si', '', $key);
        $key = Dj_App_String_Util::formatKey($key);

        // Handle regular keys as scalar values
        if (!empty($this->current_section)) {
            $data[$this->current_section][$key] = $val;
        } else {
            $data[$key] = $val;
        }

        return $data;
    }

    /**
     * This clears the section and data. Useful for testing
     * @return void
     */
    public function clear()
    {
        $this->data = null;
        $this->extra_opts_data = [];
        $this->current_section = '';
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
        $this->current_section = '';
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
