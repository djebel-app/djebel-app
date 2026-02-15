<?php
/**
 * Loads the options. Options can be key, value, auto_load=0
 * they can be loaded from ini file and the db
 */

class Dj_App_Options implements ArrayAccess, Countable {
    const SECTION_SEP = '::';

    protected $data = null;
    protected $extra_opts_data = [];

    private function __construct() {}

    /**
     * Parse INI file directly using native PHP parser
     * Uses INI_SCANNER_RAW to preserve raw values without type conversion
     *
     * @param string $file Path to INI file
     * @return array Parsed configuration array
     */
    public function parseIniFile($file)
    {
        if (!file_exists($file)) {
            return [];
        }

        // INI_SCANNER_RAW preserves values as strings (no type conversion)
        $data = parse_ini_file($file, true, INI_SCANNER_RAW);

        if (empty($data)) {
            return [];
        }

        $data = $this->processConditionalValues($data);
        $normalized_data = $this->normalizeKeys($data);

        return $normalized_data;
    }

    /**
     * Process conditional values in parsed INI data
     * Finds values starting with @if_env: and evaluates them
     *
     * @param array $data Parsed INI data (flat 2-level array from parse_ini_file)
     * @return array Data with conditional values resolved
     */
    public function processConditionalValues($data)
    {
        $prefix = '@if_env:';

        foreach ($data as $section_key => $section_data) {
            if (!is_array($section_data)) {
                continue;
            }

            foreach ($section_data as $key => $value) {
                if (!is_string($value)) {
                    continue;
                }

                $value = trim($value);

                if (strpos($value, $prefix) !== 0) {
                    continue;
                }

                $data[$section_key][$key] = $this->evaluateEnvCondition($value);
            }
        }

        return $data;
    }

    /**
     * Evaluate a single @if_env:CONDITION:RESULT directive
     *
     * @param string $value The full @if_env:... string
     * @return string The result value if condition matches, empty string otherwise
     */
    public function evaluateEnvCondition($value)
    {
        // @if_env:CONDITION:RESULT
        $parts = explode(':', $value, 3);

        // Need 3 parts: @if_env, condition, result
        $parts_cnt = count($parts);

        if ($parts_cnt < 3) {
            return '';
        }

        $condition = trim($parts[1]);
        $result = trim($parts[2]);

        if (empty($condition)) {
            return '';
        }

        // Check for match operator: VAR=expected
        $eq_pos = strpos($condition, '=');

        if ($eq_pos !== false) {
            $env_var = substr($condition, 0, $eq_pos);
            $expected = substr($condition, $eq_pos + 1);
            $env_val = Dj_App_Env::getEnv($env_var);

            if (empty($env_val)) {
                return '';
            }

            $matched = $this->matchEnvValue($env_val, $expected);

            return $matched ? $result : '';
        }

        // No =, check if env var has an enabled value (1, true, yes, on)
        $env_val = Dj_App_Env::getEnv($condition);

        if (!Dj_App_Util::isEnabled($env_val)) {
            return '';
        }

        return $result;
    }

    /**
     * Match an environment variable value against an expected pattern
     * Supports exact match, starts with (val*), ends with (*val), contains (*val*), pipe OR (val1|val2)
     *
     * @param string $env_val Actual env var value
     * @param string $expected Expected value/pattern
     * @return bool Whether the value matches
     */
    public function matchEnvValue($env_val, $expected)
    {
        // Pipe = OR matching: dev|staging checks each alternative
        $has_pipe = strpos($expected, '|') !== false;

        if ($has_pipe) {
            $alternatives = explode('|', $expected);
            $alternatives = Dj_App_String_Util::trim($alternatives);

            foreach ($alternatives as $alt) {
                $matched = $this->matchEnvValue($env_val, $alt);

                if ($matched) {
                    return true;
                }
            }

            return false;
        }

        $has_leading_star = strpos($expected, '*') === 0;
        $last_char = substr($expected, -1);
        $has_trailing_star = $last_char === '*';

        // No wildcards = exact match
        if (!$has_leading_star && !$has_trailing_star) {
            $matched = ($env_val === $expected);

            return $matched;
        }

        $pattern = trim($expected, '*');

        // *val* = contains
        if ($has_leading_star && $has_trailing_star) {
            $matched = strpos($env_val, $pattern) !== false;

            return $matched;
        }

        // val* = starts with
        if ($has_trailing_star) {
            $matched = strpos($env_val, $pattern) === 0;

            return $matched;
        }

        // *val = ends with
        $pattern_len = strlen($pattern);
        $matched = substr($env_val, -$pattern_len) === $pattern;

        return $matched;
    }

    /**
     * Normalize keys in parsed data
     * - Expands dot notation: "key.subkey" → ['key' => ['subkey' => value]]
     * - Normalizes keys: dashes to underscores
     *
     * @param array $data
     * @return array
     */
    private function normalizeKeys($data)
    {
        if (!is_array($data)) {
            return $data;
        }

        $normalized_data = [];

        foreach ($data as $key => $value) {
            // Expand dot notation: "SITE.SITE_URL" -> ['site' => ['site_url' => value]]
            $dot_pos = strpos($key, '.');

            if ($dot_pos !== false) {
                $parts = explode('.', $key);
                $nested_array = $value;
                $parts_count = count($parts);

                // Build nested structure from inside out (reverse iteration)
                for ($i = $parts_count - 1; $i >= 0; $i--) {
                    $part_fmt = Dj_App_String_Util::formatKey($parts[$i]);
                    $nested_array = [$part_fmt => $nested_array];
                }

                $normalized_data = array_merge_recursive($normalized_data, $nested_array);
            } else {
                $key_fmt = Dj_App_String_Util::formatKey($key);

                if (is_array($value)) {
                    $normalized_data[$key_fmt] = $this->normalizeKeys($value);
                } else {
                    $normalized_data[$key_fmt] = $value;
                }
            }
        }

        return $normalized_data;
    }

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
            $data = $this->parseIniFile($file);
        }

        $data = Dj_App_Hooks::applyFilter( 'app.core.filter.options', $data );
        $this->data = $data;

        $load_extra_opts = Dj_App_Hooks::applyFilter( 'app.core.options.load_extra_conf_files', false );

        if (!empty($load_extra_opts)) { // let's use glob to load all other options files when when really needed
            $extra_opt_files = glob(Dj_App_Util::getCoreConfDir() . '/*.ini');
            $extra_opt_files = empty($extra_opt_files) ? [] : $extra_opt_files;
            $extra_opt_files = array_diff($extra_opt_files, [ $file ]); // rm the main options file

            foreach ($extra_opt_files as $extra_opt_file) {
                $raw_data = $this->parseIniFile($extra_opt_file);
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

            // Smart merge: convert scalar to array when duplicate key is encountered
            $data = $this->smartMerge($data, $res);
        }

        return $data;
    }

    /**
     * Smart merge that converts scalars to arrays when duplicate keys are encountered
     * @param array $existing
     * @param array $new
     * @return array
     */
    private function smartMerge($existing, $new) {
        foreach ($new as $key => $value) {
            if (!isset($existing[$key])) {
                // Key doesn't exist yet, just set it
                $existing[$key] = $value;
            } else {
                // Key already exists
                $existing_value = $existing[$key];

                if (is_array($value) && is_array($existing_value)) {
                    // Both are arrays, recursively merge
                    $existing[$key] = $this->smartMerge($existing_value, $value);
                } elseif (is_array($existing_value) && !is_array($value)) {
                    // Existing is array, new is scalar - append to array
                    $existing[$key][] = $value;
                } elseif (!is_array($existing_value) && is_array($value)) {
                    // Existing is scalar, new is array - convert scalar to array and merge
                    $existing[$key] = array_merge([$existing_value], $value);
                } else {
                    // Both are scalars - convert to indexed array
                    // This is the duplicate key case: convert first scalar to array
                    $existing[$key] = [$existing_value, $value];
                }
            }
        }

        return $existing;
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
        // Support array of fallback keys directly
        if (is_array($key)) {
            $keys = $key;
            $keys_cnt = count($keys);
        } elseif (is_scalar($key)) {
            // OPTIMIZATION: Check for comma first (after normalizing ; and |)
            // str_replace is fast when nothing matches, so just normalize
            $key = str_replace([';', '|'], ',', $key);

            // Now check if we have multiple keys (contains comma)
            $has_comma = strpos($key, ',');

            if ($has_comma !== false) {
                // Multiple keys - split them
                $keys = explode(',', $key);
                $keys_cnt = count($keys);
            } else {
                // Single key - fast path (array cast is faster than literal)
                $keys = (array) $key;
                $keys_cnt = 1;
            }
        } else {
            return '';
        }

        // Handle multiple fallback keys
        // Example: get('theme.theme,theme.theme_id,site.theme', 'default')
        // Or: get(['theme.theme', 'theme.theme_id', 'site.theme'], 'default')
        if ($keys_cnt > 1) {
            for ($i = 0; $i < $keys_cnt; $i++) {
                $single_key = $keys[$i];
                $val = $this->get($single_key);

                if (!empty($val)) {
                    return $val;
                }
            }

            return $default;
        }

        // Single key - trim and continue with normal logic
        $key = $keys[0];
        $key = Dj_App_String_Util::trim($key);

        $data = $this->data;
        $ctx = [ 'key' => $key, 'default' => $default, ];

        // some plugins might want to override the value
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_option', false, $ctx );

        if ($val !== false) {
            return $val;
        }

        $level1 = '';
        $level2 = '';
        $level3 = '';

        // if key has dots, split into levels
        if (strpos($key, '.') !== false) {
            $parts = explode('.', $key);
            $parts = array_map('Dj_App_String_Util::formatKey', $parts);
            $level1 = array_shift($parts);

            if (count($parts) > 1) {
                $level2 = array_shift($parts);
            }

            if (count($parts) > 1) {
                $level3 = array_shift($parts);
            }

            $key = implode('.', $parts);
        }

        $ctx['key'] = $key;
        $ctx['level1'] = $level1;
        $ctx['level2'] = $level2;
        $ctx['level3'] = $level3;

        // option specific filter again
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.pre_option_' . $key, false, $ctx );

        if ($val !== false) {
            return $val;
        }

        $val = null;

        if (!empty($level3) && isset($data[$level1][$level2][$level3][$key])) {
            $val = $data[$level1][$level2][$level3][$key];
        } elseif (!empty($level2) && isset($data[$level1][$level2][$key])) {
            $val = $data[$level1][$level2][$key];
        } elseif (!empty($level1) && isset($data[$level1][$key])) {
            $val = $data[$level1][$key];
        } elseif (empty($level1) && isset($data[$key])) {
            $val = $data[$key];
        }

        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.option', $val, $ctx );
        $val = Dj_App_Hooks::applyFilter( 'app.core.filter.option_' . $key, $val, $ctx );

        // Check if we have a value (from config OR from filter)
        // Filters can provide values even when key doesn't exist in config
        if (!is_null($val)) {
            return $val;
        }

        // No value from config or filter - use default if provided
        if ($default !== '') {
            return $default;
        }

        // No value, no default - return empty string
        return '';
    }

    /**
     * Get all options within a section as an Options object
     * Example: getSection('plugins.djebel-mailer') returns Options object with all keys in that section
     * Can be used as array or object: $section['key'] or $section->key
     *
     * @param string $section_key Section path with dot notation (e.g., 'plugins.djebel-mailer')
     * @return Dj_App_Options Options object with section data, or empty Options object if section doesn't exist
     */
    public function getSection($section_key)
    {
        $data = $this->data;

        if (empty($section_key)) {
            // Return empty Options object for consistency
            $empty_opt_obj = new static();
            $empty_opt_obj->data = []; // Prevent load() from being triggered on property access
            return $empty_opt_obj;
        }

        // Parse section path: 'plugins.djebel-mailer' → ['plugins', 'djebel-mailer']
        $parts = explode('.', $section_key);

        // Navigate through nested structure
        $current_level = $data;

        foreach ($parts as $part) {
            $part = Dj_App_String_Util::formatKey($part);

            if (!isset($current_level[$part])) {
                // Return empty Options object for consistency
                $empty_opt_obj = new static();
                $empty_opt_obj->data = []; // Prevent load() from being triggered on property access
                return $empty_opt_obj;
            }

            $current_level = $current_level[$part];

            if (!is_array($current_level)) {
                // Return empty Options object for consistency
                $empty_opt_obj = new static();
                $empty_opt_obj->data = []; // Prevent load() from being triggered on property access
                return $empty_opt_obj;
            }
        }

        // Return Options object with section data for chaining/array access
        $section_opt_obj = new static();
        $section_opt_obj->data = $current_level;
        return $section_opt_obj;
    }

    /**
     * Convert Options object to plain array
     * Useful when you need an actual array (e.g., for array_merge)
     *
     * @return array The internal data as array
     */
    public function toArray()
    {
        $data = empty($this->data) ? [] : (array) $this->data;
        return $data;
    }

    /**
     * Get all options data
     * Alias for toArray()
     *
     * @return array All options data
     */
    public function getAll()
    {
        return $this->toArray();
    }

    /**
     * Countable interface - allows count($options_obj)
     * @return int
     */
    public function count(): int {
        return count($this->toArray());
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
     * Returns member data or a key from data.
     *
     * Simple getter - follows 10x PHP patterns:
     * - Arrays become Options objects for chaining
     * - Scalars returned directly
     * - Non-existent keys return empty string (works with empty()!)
     *
     * @param string $name
     * @return mixed
     */
    public function __get($name) {
        $data = $this->data;

        if (!isset($data[$name])) {
            // Return empty Options for unlimited chaining depth
            // Works at any level: $obj->a->b->c->d->e even if none exist
            $empty_obj = new static();
            $empty_obj->data = [];
            return $empty_obj;
        }

        $val = $data[$name];

        // Wrap arrays in Options for chaining: $obj->site->site_url
        if (is_array($val)) {
            $nested_obj = new static();
            $nested_obj->data = $val;
            return $nested_obj;
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
        $data = $this->data;
        return isset($data[$key]);
    }

    /**
     * Convert to string when Options object is used in string context
     * @return string
     */
    public function __toString() {
        $data = $this->data;

        // Empty array means this is an empty Options object (non-existent key)
        if (is_array($data) && count($data) === 0) {
            return '';
        }

        if (is_scalar($data)) {
            return (string) $data;
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

        // remove comment/text after # unless # is escaped
        if ($pos !== false && substr($val, $pos - 1, 1) !== "\\" ) {
            $val = substr($val, 0, $pos);
        }

        // The values may have quotes
        $val = Dj_App_String_Util::trim($val, '\'"');

        // Remove spaces from key
        $key = str_replace(' ', '', $key);

        // Normalize bracket notation to dot notation
        $bracket_pos = strpos($key, '[');

        if ($bracket_pos !== false) {
            // Escape special chars only in bracket content (not the prefix)
            $key_prefix = substr($key, 0, $bracket_pos);
            $bracket_content = substr($key, $bracket_pos);
            $bracket_content = $this->escapeKey($bracket_content);
            $key = $key_prefix . $bracket_content;

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
                    $keys[$i] = $this->unescapeKey($k);
                }

                // Prepend section to keys if present
                if ($section) {
                    array_unshift($keys, $section);
                }

                // Fast explicit level handling - ensure each level is an array
                $level = count($keys);

                // Ensure all parent levels exist
                if (!isset($data[$keys[0]])) {
                    $data[$keys[0]] = [];
                }

                if ($level >= 3) {
                    if (!isset($data[$keys[0]][$keys[1]])) {
                        $data[$keys[0]][$keys[1]] = [];
                    }
                }

                if ($level == 4) {
                    if (!isset($data[$keys[0]][$keys[1]][$keys[2]])) {
                        $data[$keys[0]][$keys[1]][$keys[2]] = [];
                    }
                }

                // Assign value based on level
                if ($level == 2) {
                    $data[$keys[0]][$keys[1]] = $val;
                } elseif ($level == 3) {
                    $data[$keys[0]][$keys[1]][$keys[2]] = $val;
                } elseif ($level == 4) {
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
     * Escape special characters in key for safe parsing
     * Encodes chars that would be stripped by sanitization
     * @param string $key
     * @return string
     */
    private function escapeKey($key)
    {
        // Quick check - skip if no special chars present
        $special_chars = '/#^()+\\$*?.{}';
        $has_special = strpbrk($key, $special_chars) !== false;

        if (!$has_special) {
            return $key;
        }

        $escape_map = [
            '/' => '__SLASH__',
            '#' => '__HASH__',
            '^' => '__CARET__',
            '(' => '__LPAREN__',
            ')' => '__RPAREN__',
            '+' => '__PLUS__',
            '\\' => '__BSLASH__',
            '$' => '__DOLLAR__',
            '*' => '__STAR__',
            '?' => '__QUEST__',
            '.' => '__DOT__',
            '{' => '__LBRACE__',
            '}' => '__RBRACE__',
        ];

        foreach ($escape_map as $char => $placeholder) {
            $has_char = strpos($key, $char) !== false;

            if (!$has_char) {
                continue;
            }

            $key = str_replace($char, $placeholder, $key);
        }

        return $key;
    }

    /**
     * Unescape encoded special characters in key
     * Reverses encoding done during key parsing
     * @param string $key
     * @return string
     */
    private function unescapeKey($key)
    {
        // Quick length check - shortest placeholder is __PLUS__ (8 chars)
        $key_len = strlen($key);

        if ($key_len < 8) {
            $key = Dj_App_String_Util::formatKey($key);

            return $key;
        }

        $has_encoded = strpos($key, '__') !== false;

        if (!$has_encoded) {
            $key = Dj_App_String_Util::formatKey($key);

            return $key;
        }

        $restore_map = [
            '__SLASH__' => '/',
            '__HASH__' => '#',
            '__CARET__' => '^',
            '__LPAREN__' => '(',
            '__RPAREN__' => ')',
            '__PLUS__' => '+',
            '__BSLASH__' => '\\',
            '__DOLLAR__' => '$',
            '__STAR__' => '*',
            '__QUEST__' => '?',
            '__DOT__' => '.',
            '__LBRACE__' => '{',
            '__RBRACE__' => '}',
        ];

        $restore_keys = array_keys($restore_map);
        $restore_vals = array_values($restore_map);
        $key = str_replace($restore_keys, $restore_vals, $key);

        return $key;
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
            $instance->load();
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
