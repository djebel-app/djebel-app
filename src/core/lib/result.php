<?php

class Dj_App_Result implements \JsonSerializable {
    const OVERRIDE_FLAG = 2;
    const DONT_OVERRIDE_FLAG = 4;
    const CONVERT_DATA_KEYS_TO_LOWER_CASE = 8;
    const CONVERT_DATA_KEYS_TO_UPPER_CASE = 16;

    // I put them as public even though I need them private.
    // reason: private fields don't appear in a JSON output
    public $msg = '';
    public $code = '';
    public $status = false;
    public $data = [];
    private $expected_system_keys_regex = '#^(status|msg|code|data)$#si';

    /**
     * Populates the internal variables from contr params.
     * @param int|string|array|object $inp
     */
    public function __construct($inp = '' ) {
        $json_arr = [];

        if ( ! empty( $inp ) ) {
            if ( is_scalar( $inp ) ) {
                if ( is_bool($inp) || is_numeric($inp)) {
                    $this->status = !empty($inp);
                } elseif ( is_string( $inp ) ) {
                    $json_arr = json_decode( $inp, true );
                }
            } elseif ( is_object( $inp ) ) {
                $json_arr = (array) $inp;
            }  elseif ( is_array( $inp ) ) {
                $json_arr = $inp;
            }

            if ( is_array( $json_arr ) ) {
                foreach ( $json_arr as $key => $value ) {
                    // Some recognized keys' values will go as internal fields
                    // and the rest as data.
                    if (preg_match( $this->expected_system_keys_regex, $key)) {
                        $this->$key = $value;
                    } else {
                        $this->data[$key] = $value;
                    }
                }
            }
        }
    }

    /**
     * Cool method which is nicer than checking for a status value.
     * @return bool
     */
    public function isSuccess() {
        return ! empty( $this->status );
    }

    /**
     * Cool method which is nicer than checking for a status value.
     * @return bool
     */
    public function isError() {
        return ! $this->isSuccess();
    }

    public function status( $new_status = null ) {
        if ( ! is_null( $new_status ) ) {
            $this->status = (bool) $new_status; // we want 0 or 1 and not just random 0, 1 and true or false
        }

        return $this->status;
    }

    /**
     * returns or sets a message
     * @param string $code
     * @return string
     */
    public function code( $code = '' ) {
        if ( ! empty( $code ) ) {
            $code = preg_replace( '#[^\w\d]#si', '_', $code );
            $code = trim( $code, '_- ' );
            $code = strtoupper($code);
            $this->code = $code;
        }

        return $this->code;
    }

    /**
     * Alias to msg
     * @param string $new_message
     * @return string
     */
    public function message( $new_message = null ) {
        return $this->msg($new_message);
    }

    /**
     * returns or sets a message
     * @param string $msg
     * @return string
     */
    public function msg($msg = '') {
        if (!empty($msg)) {
            $this->msg = Dj_App_String_Util::trim( $msg );
        }

        return $this->msg;
    }

    /**
     * Getter and setter
     * @param bool|int $new_status
     * @return bool
     */
    public function success($new_status = null) {
        $this->status($new_status);
        return !empty($this->status);
    }

    /**
     * Getter and setter
     * @param type $new_status
     * @return bool
     */
    public function error($new_status = null) {
        $this->status($new_status);
        return empty($this->status);
    }

    /**
     *
     * @param mixed $key_or_records
     * @param mixed $value
     * @return mixed
     */
    public function data($key = '', $val = null) {
        if (is_array($key)) { // when we pass an array -> override all
            if ( ! empty( $val ) && ( self::OVERRIDE_FLAG & $val ) ) { // full data overriding.
                $this->data = $key;
            } else {
                $this->data = empty($this->data) ? $key : array_replace_recursive($this->data, $key);
            }
        } elseif (!empty($key)) {
            if (!is_null($val)) { // add/update a value
                $this->data[$key] = $val;
            }

            return isset($this->data[$key]) ? $this->data[$key] : '';
        } else { // nothing return all data
            $val = $this->data;
        }

        return $val;
    }

    /**
     * Removes one or more keys from the data array.
     * @param string|array $key
     */
    public function deleteKey( $key = '' ) {
        $key_arr = (array) $key;

        foreach ( $key_arr as $key_to_del ) {
            unset( $this->data[ $key_to_del ] );
        }
    }

    /**
     * Renames a key in case the receiving api exects a given key name.
     *
     * @param string $key
     * @param string $new_key
     */
    public function renameKey( $key, $new_key ) {
        if ( empty( $key ) || empty( $new_key ) ) {
            return;
        }

        $val = $this->data( $key ); // get old val
        $this->deleteKey( $key );
        $this->data( $new_key, $val );
    }

    /**
     * Extracts data from the params and populates the internal data array.
     * It's useful when storing data from another request
     *
     * @param string|array|obj $json
     * @param int $flag
     */
    public function populateData( $json, $flag = self::DONT_OVERRIDE_FLAG ) {
        if ( empty( $json ) ) {
            return false;
        }

        if ( is_string( $json ) ) {
            $json = json_decode( $json, true );
        } else if ( is_object( $json ) ) {
            $json = (array) $json;
        }

        if ( is_array( $json ) ) {
            foreach ( $json as $key => $value ) {
                if ( isset( $this->data[ $key ] ) && ( $flag & self::DONT_OVERRIDE_FLAG ) ) {
                    continue;
                }

                // In case 'ID' we have 'id' in the data
                if ( is_array( $value ) ) {
                    if ( $flag & self::CONVERT_DATA_KEYS_TO_LOWER_CASE ) {
                        $value = array_change_key_case( $value, CASE_LOWER );
                    }

                    if ( $flag & self::CONVERT_DATA_KEYS_TO_UPPER_CASE ) {
                        $value = array_change_key_case( $value, CASE_UPPER );
                    }
                }

                // In case 'ID' we want to have it as 'id'.
                if ( ! is_numeric( $key ) && ( $flag & self::CONVERT_DATA_KEYS_TO_LOWER_CASE ) ) {
                    $key = strtolower( $key );
                }

                if (preg_match( $this->expected_system_keys_regex, $key)) {
                    $this->$key = $value;
                } else {
                    $this->data[ $key ] = $value;
                }
            }
        }
    }

    /**
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        if (property_exists($this, $name)) {
            $this->$name = $value;
        } else {
            $this->data[$name] = $value;
        }
    }

    /**
     * Returns member data or a key from data. It's easier e.g. $data_res->output
     * @param string $name
     * @return mixed|null
     */
    public function __get($name) {
        if (property_exists($this, $name) && isset($this->$name)) {
            return $this->$name;
        }

        if (isset($this->data[$name])) {
            return $this->data[$name];
        }

        return null;
    }

    /**
     * Checks if a data property exists
     * @param string $key Property key
     */
    public function __isset($key) {
        return !is_null($this->__get($key));
    }

    /**
     * When we have a is_X -> this automagically checks for a field in data that has a boolean value
     * e.g. calling $res_obj->is_disposable_email() -> checks if $this->data['disposable_email'] ->
     * e.g. calling $res_obj->isDisposableEmail() -> checks if $this->data['disposable_email'] ->
     * @param string $name
     * @param $arguments
     * @return void|mixed
     */
    public function __call($name, $arguments) {
        $first_chars = substr($name, 0, 2);

        if (strcasecmp($first_chars, 'is') == 0) {
            $key_fmt = $this->convertFuncNameToDataKey($name);

            if (isset($this->data[$key_fmt])) {
                return $this->data[$key_fmt];
            }

            if (isset($this->data[ 'is_' . $key_fmt])) {
                return $this->data[ 'is_' . $key_fmt];
            }

            // Now. Let's check if there's a class field
            if (isset($this->$key_fmt)) {
                return $this->$key_fmt;
            }

            $key_fmt = 'is_' . $key_fmt;

            if (isset($this->$key_fmt)) {
                return $this->$key_fmt;
            }

            return false;
        }
    }

    /**
     * Converts a test to snake_case.
     * @param string $name
     * @return string
     */
    public function convertFuncNameToDataKey($name) {
        $key_fmt = $name;
        $key_fmt = str_replace(['-', '_', ' ', '\t'], '_', $key_fmt);
        $key_fmt = preg_replace('#^is[\-\_]*#si', '', $key_fmt);

        // let's put an underscore before each uppercase letter
        $key_fmt = preg_replace('#([A-Z])#s', '_${1}', $key_fmt);
        $key_fmt = trim($key_fmt, '_'); // rm trailing
        $key_fmt = preg_replace('#\_+#s', '_', $key_fmt); // single
        $key_fmt = strtolower($key_fmt);

        return $key_fmt;
    }

    /**
     * In case this is used in a string context it should return something meaningful.
     * @return string
     */
    public function __toString() {
        return $this->status() ? 'Success: ' . $this->msg() : 'Error: ' . $this->msg();
    }

    /**
     * Removes data
     */
    public function clearData() {
        $this->data = [];
    }

    /**
     * This gets called when the object is about to be serialized.
     * For some odd reason the private members also end up into the JSON?!?
     * We'll have to remove them manually as they are not useful.
     * @see https://stackoverflow.com/questions/7005860/php-json-encode-class-private-members
     * 
     * JsonSerializable interface method - suppress deprecation notice for return type compatibility
     * PHP 8.1+ expects mixed return type, but we need to maintain PHP 7.x compatibility
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        $vars = get_object_vars($this);
        unset($vars['expected_system_keys_regex']);
        return $vars;
    }
}
