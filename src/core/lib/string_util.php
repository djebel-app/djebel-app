<?php
/**
 * String related functions
 */
class Dj_App_String_Util
{
    /**
     * Dj_App_String_Util::trim();
     * Trims whitespace and optionally extra characters from string or array.
     *
     * @param string|array $data String or array to trim
     * @param string|array $extra_chars Extra characters to trim (string or array of chars)
     * @return string|array Trimmed data
     * Ideas gotten from: http://www.jonasjohn.de/snippets/php/trim-array.htm
     */
    public static function trim($data, $extra_chars = '') {
        if ( is_scalar( $data ) ) {
            $data = str_replace( "\0", '', $data );

            // Build character mask for trim
            $char_mask = " \t\n\r\0\x0B";

            if (!empty($extra_chars)) {
                if (is_array($extra_chars)) {
                    $extra_chars = implode('', $extra_chars);
                }

                $char_mask .= $extra_chars;
            }

            $data = trim( $data, $char_mask );
        } elseif (is_array($data)) {
            if (!empty($extra_chars)) {
                $data = array_map(function($item) use ($extra_chars) {
                    return Dj_App_String_Util::trim($item, $extra_chars);
                }, $data);
            } else {
                $data = array_map('Dj_App_String_Util::trim', $data);
            }
        }

        return $data; // not sure what to do with this thing.
    }

    /**
     * Format a string to camelCase
     * Dj_App_String_Util::toCamelCase();
     */
    public static function toCamelCase($str)
    {
        $str = Dj_App_String_Util::formatKey($str);

        if (empty($str)) {
            return '';
        }

        $str = str_replace('_', ' ', $str);
        $str = ucwords($str);
        $str = lcfirst($str);
        $str = str_replace(' ', '', $str);

        return $str;
    }

    /**
     * Converts text to nicely_formatted_key
     * Dj_App_String_Util::formatKey();
     * @param string $str
     * @return string
     * Ideas gotten from: http://www.jonasjohn.de/snippets/php/trim-array.htm
     */
    public static function formatKey($str)
    {
        if (empty($str)) {
            return '';
        }

        $str = substr($str, 0, 64); // let's work with small strings
        $str = str_replace( ['-', ':', '/', "\0"], '_', $str);

        // check if it's alphanumeric before doing anything more resource intensive
        if (!Dj_App_String_Util::isAlphaNumericExt($str)) {
            $str = Dj_App_String_Util::trim($str);
            $str = preg_replace( '#[^\w]+#si', '_', $str );
            $str = preg_replace( '#_+#si', '_', $str ); // single _
        }

        $str = strtolower($str);
        $str = trim($str, '_');

        return $str;
    }

    /**
     * Checks if a string is alphanumeric and also _ + -
     * Dj_App_String_Util::isAlphaNumericExt();
     * @param $str
     * @return bool
     */
    static public function isAlphaNumericExt($str)
    {
        if (empty($str)) {
            return false;
        }

        $filtered = str_replace(['-', '_'], '', $str);

        return ctype_alnum($filtered);
    }

    const ALLOW_DOT = 2;
    const KEEP_CASE = 2**2;
    const KEEP_DASH = 2**3;
    const LOWERCASE = 2**4;
    const UPPERCASE = 2**5;
    const FORMAT_ALLOW_DOT = 2**6;
    const FORMAT_CONVERT_TO_DASHES = 2**7;
    const KEEP_LEADING_DASH = 2**8;
    const KEEP_TRAILING_DASH = 2**9;
    const SHORTEN_TO_MAX_SUBDOMAIN_LENGTH = 2**10;

    /**
     * Dj_App_String_Util::formatStringId();
     * @param string $str
     * @return string
     * Ideas gotten from: http://www.jonasjohn.de/snippets/php/trim-array.htm
     */
    public static function formatStringId($tag, $flags = 0) {
        if (is_null($tag) || $tag === '' || $tag === false) {
            return false; // early return for empty values
        }

        $max_len = 255;

        if (!is_scalar($tag)) {
            $tag = serialize($tag);
            $tag = substr($tag, 0, $max_len);
        } else if (is_numeric($tag)) { // ctype_alnum(): Argument of type int will be interpreted as string in the future
            $tag = (string) $tag; // php complains about ctype_alnum when it receives numbers that will be treated as strings
        }

        // Fast path: if already alphanumeric extended (a-z0-9_-) and no flags, just lowercase
        if (empty($flags) && self::isAlphaNumericExt($tag)) {
            // with no flag lowercase is the default option.
            // We're doing this as one of the tests failed.
            $tag = strtolower($tag);
            return $tag;
        }

        // checking for an empty str because the value could be null
        // we don't want the null thing serialized
        $tag = is_null($tag) || $tag == '' || $tag === false ? '' : $tag; // 0 is ok

        if ( !is_scalar( $tag ) ) {
            $tag = serialize($tag);
        }

        $tag = strip_tags($tag);
        $tag = trim($tag);

        $extra_allowed_chars = [];

        if ($flags & Dj_App_String_Util::FORMAT_CONVERT_TO_DASHES) { // subdomain?
            $extra_allowed_chars[] = '-';
        } else {
            $extra_allowed_chars[] = '_';
            $extra_allowed_chars[] = '-';
        }

        if ($flags & Dj_App_String_Util::ALLOW_DOT) {
            $extra_allowed_chars[] = '.';
        } else {
            $tag = str_replace('.', '_', $tag);
        }

        $extra_allowed_chars_q = array_map('preg_quote', $extra_allowed_chars);

        if ($flags & Dj_App_String_Util::KEEP_CASE) {
            // ok use it as is
        } elseif ($flags & Dj_App_String_Util::UPPERCASE) {
            $tag = strtoupper($tag);
        } else {
            $tag = strtolower($tag);
        }

        // Let's prefix the chars just in case so they are not that special like '.'
        $tag = preg_replace('/[^\w' . join('', $extra_allowed_chars_q) . ']/si', '_', $tag);

        // special chars near each other get collapsed e.g. -_- but not when dot is used as it breaks subdomains
//		if (!($flags & Dj_App_String_Util::ALLOW_DOT)) {
//			$tag = preg_replace('/[' . join('', $extra_allowed_chars_q) . ']+/si', '_', $tag);
//		}

        foreach ($extra_allowed_chars as $char) {
            $char_q = preg_quote($char);
            $tag = preg_replace('/[' . $char_q . ']+/si', $char, $tag); // singlfy
        }

        if ($flags & Dj_App_String_Util::FORMAT_CONVERT_TO_DASHES) { // subdomain?
            $tag = preg_replace('#[\-\_]+#si', '-', $tag);
            $tag = preg_replace('#[\-\_]+\.#si', '.', $tag); // a dash after the dot
            $tag = preg_replace('#\.[\-\_]+#si', '.', $tag); // a dash before the dot
        }

        if (($flags & Dj_App_String_Util::KEEP_LEADING_DASH) == 0) { // subdomain prefix?
            $tag = ltrim( $tag, '-_' );
        }

        if (($flags & Dj_App_String_Util::KEEP_TRAILING_DASH) == 0) { // subdomain prefix?
            $tag = rtrim( $tag, '-_' );
        }

        $tag = trim($tag, join('', $extra_allowed_chars));

        if ($flags & Dj_App_String_Util::SHORTEN_TO_MAX_SUBDOMAIN_LENGTH) {
            $max_len = 63;
        }

        $tag = substr($tag, 0, $max_len);

        return $tag;
    }

    /**
     * Format a string to a URL-friendly slug (converts underscores to dashes)
     * Dj_App_String_Util::formatSlug();
     * @param string $str
     * @param int $flags
     * @return string
     */
    public static function formatSlug($str, $flags = 0) {
        // Default to converting underscores to dashes for URL-friendly slugs
        if (($flags & self::FORMAT_CONVERT_TO_DASHES) == 0) {
            $flags |= self::FORMAT_CONVERT_TO_DASHES;
        }
        
        // For formatSlug, always convert dots to dashes (default behavior)
        // Remove ALLOW_DOT flag and let formatStringId handle dots as underscores
        $flags &= ~self::ALLOW_DOT;
        
        // Use formatStringId with the modified flags
        $result = self::formatStringId($str, $flags);
        
        return $result;
    }

    /**
     * Dj_App_String_Util::jsonEncode();
     *
     * @param array|object $thing
     * @param int $opts
     * @return string
     */
    public static function jsonEncode( $thing, $opts = 1 ) {
        $res = json_encode( $thing, defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT : 0 );

        // With php7+ encoding can/will fail if contents are not utf8 encoded.
        if ( empty( $res ) ) {
            $thing = Dj_App_String_Util::encodeUTF8( $thing );
            $res = json_encode( $thing, defined( 'JSON_PRETTY_PRINT' ) ? JSON_PRETTY_PRINT : 0 );
        }

        return $res;
    }

    /**
     * Dj_App_String_Util::jsonDecode();
     * @param mixed $thing
     * @param int $opts
     * @return array
     */
    public static function jsonDecode( $thing, $opts = 0 ) {
        $res = [];

        if ( is_scalar( $thing ) ) {
            $first_char = substr($thing, 0, 1);

            if ($first_char == '[' || $first_char == '{') {
                $opts = empty($opts) ? true : false; // array by default
                $res = json_decode($thing, $opts);
                $res = empty($res) ? [] : $res;
            }
        } else {
            $res = (array) $thing;
        }

        return $res;
    }

    /**
     * This is needed when doing JSON encode as decoding may not work with php 7+
     * Dj_App_String_Util::encodeUTF8();
     * @param mixed $d
     * @return mixed
     * @see http://stackoverflow.com/questions/19361282/why-would-json-encode-returns-an-empty-string
     */
    public static function encodeUTF8($d) {
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                $d[$k] = Dj_App_String_Util::encodeUTF8($v);
            }
        } elseif (is_object($d)) {
            foreach ($d as $k => $v) {
                $d->$k = Dj_App_String_Util::encodeUTF8($v);
            }
        } elseif (is_scalar($d)) {
            $d = utf8_encode($d);
        }

        return $d;
    }
}
