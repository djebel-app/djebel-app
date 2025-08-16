<?php
/**
 * String related functions
 */
class Dj_App_String_Util
{

    /**
     * Dj_App_String_Util::trim();
     * @param string|array $data
     * @return string|array
     * Ideas gotten from: http://www.jonasjohn.de/snippets/php/trim-array.htm
     */
    public static function trim($data) {
        if ( is_scalar( $data ) ) {
            $data = str_replace( "\0", '', $data );
            return trim( $data );
        } elseif (is_array($data)) {
            return array_map( 'Dj_App_String_Util::trim', $data );
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

        // check if it's alphanumeric before doing anything more resource intensive
        if (Dj_App_String_Util::isAlphaNumericExt($str)) {
            $str = str_replace('-', '_', $str);
        } else {
            $str = Dj_App_String_Util::trim($str);
            $str = preg_replace( '#[^\w\-]+#si', '_', $str );
            $str = str_replace( '-', '_', $str );
            $str = preg_replace( '#_+#si', '_', $str ); // single _
        }

        $str = strtolower($str);
        $str = substr($str, 0, 64);
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
        $filtered = str_replace(['-', '_'], '', $str);
        return ctype_alnum($filtered);
    }

    const ALLOW_DOT = 2;
    const KEEP_CASE = 4;
    const LOWERCASE = 8;
    const UPPERCASE = 32;
    const FORMAT_ALLOW_DOT = 64;
    const FORMAT_CONVERT_TO_DASHES = 128;
    const KEEP_LEADING_DASH = 256;
    const KEEP_TRAILING_DASH = 512;
    const SHORTEN_TO_MAX_SUBDOMAIN_LENGTH = 1024;

    /**
     * Dj_App_String_Util::formatStringId();
     * @param string $str
     * @return string
     * Ideas gotten from: http://www.jonasjohn.de/snippets/php/trim-array.htm
     */
    public static function formatStringId($tag, $flags = 0) {
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
        $cut = 255;

        if ($flags & Dj_App_String_Util::SHORTEN_TO_MAX_SUBDOMAIN_LENGTH) {
            $cut = 63;
        }

        $tag = substr($tag, 0, $cut);

        return $tag;
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
