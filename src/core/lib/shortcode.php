<?php

/**
 * Manages shortcodes.
 * [test_shortcode]
 * [test_shortcode attr1="val1" attr2="val2"]
 * [dj_app_remote_content url="test.html"]
 */
class Dj_App_Shortcode {
    private $shortcodes = [];

    /**
     * @return string
     */
    public function installHooks()
    {
        // [djebel_date_year]
        $this->addShortcode('djebel_date_year', [ $this, 'renderSysShortcodeYear' ]);

        // [djebel_nav]
        $this->addShortcode('djebel_nav', [ $this, 'renderNav' ]);

        //
        $this->addShortcode('djebel_content', [ $this, 'renderContent' ]);

        Dj_App_Hooks::addFilter( 'app.page.full_content', [ $this, 'replaceShortCodes'] );
    }

    /**
     * replaces [djebel_nav] shortcode with whatever we have defined in app.ini in the nav
     * @param array $params
     * @return string
     */
    public function renderNav($params = []) {
        $page_obj = Dj_App_Page::getInstance();

        ob_start();
        $page_obj->renderMenu();
        $buff = ob_get_clean();
        return $buff;
    }

    /**
     * replaces [djebel_content] shortcode with whatever we have defined in app.ini in the nav
     * @param array $params
     * @return string
     */
    public function renderContent($params = []) {
        ob_start();
        Dj_App_Hooks::doAction('app.page.content.render', $params);
        $buff = ob_get_clean();
        $buff = $this->replaceShortCodes($buff);
        return $buff;
    }

    /**
     * replaces [year] shortcode with the current year
     * @param array $params
     * @return string
     */
    public function renderSysShortcodeYear($params = []) {
        return date('Y');
    }

    /**
     * Formats a shortcode name and normalizes it.
     * @param string $code
     * @return string
     */
    public function formatShortCode($code)
    {
        $code = preg_replace('#[^\w]+#si', '_', $code);
        $code = preg_replace( '#_+#si', '_', $code );
        $code = strtolower($code);
        $code = trim($code, '_');
        return $code;
    }

    /**
     * Searches for shortcodes and replaces them with their output.
     * @return string
     */
    public function replaceShortCodes($buff)
    {
        if (empty($buff)) {
            return '';
        }

        $shortcodes = $this->getShortcodes();

        if (empty($shortcodes)) {
            return $buff;
        }

        $content_before_body = '';

        // by default we'll only replace shortcodes starting from <body>
        $full_page_replace = Dj_App_Config::cfg('app.core.shortcodes.full_page_replace', false);

        if ($full_page_replace) {
            $content = $buff;
        } else {
            // if we have body we'll start replacing after it.
            $body_start_pos = stripos($buff, '<body');

            if ($body_start_pos !== false) {
                $body_end_tag_pos = strpos($buff, '>', $body_start_pos); // there has to be!
                $content_before_body = substr($buff, 0, $body_end_tag_pos + 1);
                $content = substr($buff, $body_end_tag_pos + 1);
            } else {
                $content = $buff;
            }
        }

        // Normalize shortcode dashes to underscores
        $content = $this->prepareShortcodes($content);

        // do we have at least [ ?
        $square_pos = strpos($content, '[');

        if ($square_pos === false) {
            return $buff;
        }

        // next char after square is not a letter
        $next_char = substr($content, $square_pos + 1, 1);

        if (!ctype_alpha($next_char)) {
            return $buff;
        }

        foreach ($shortcodes as $shortcode => $callback) {
            if (!is_callable($callback)) {
                trigger_error("Invalid callback for shortcode: [$shortcode]", E_USER_WARNING);
                continue;
            }

            // there could be multiple instances of the same shortcode in the content
            // we need to keep the strpos in the loop otherwise we'll have incorrect replacements.
            while (($current_short_code_start_pos = strpos($content, '[' . $shortcode, $square_pos)) !== false) {
                $current_short_code_closing_pos = strpos($content, ']', $current_short_code_start_pos); // find the closing tag

                if ($current_short_code_closing_pos === false) { // none?
                    break;
                }

                $tag_and_params_str = substr($content, $current_short_code_start_pos, $current_short_code_closing_pos - $current_short_code_start_pos + 1);

                $params_str = '';
                $params = [];
                $raw_tag = $tag_and_params_str;
                $raw_tag = trim($raw_tag, '[]'); // no leading or closing []

                if (Dj_App_String_Util::isAlphaNumericExt($raw_tag)) { // skip regex for performance
                    $tag = $raw_tag;
                } else if (preg_match('#^([\w\-]+)\h*(.*)#si', $raw_tag, $matches)) {
                    $tag = $matches[1];
                    $params_str = $matches[2];
                } else {
                    break;
                }

                // parse params
                if (!empty($params_str)) {
                    $params_str = preg_replace('#\h*\=+[\h\'\"]*#si', '=', $params_str);
                    $params_str = preg_replace('#\h+#si', ' ', $params_str);
                    $params_str = trim($params_str);
                    $pairs_str_arr = explode(' ', $params_str);

                    foreach ($pairs_str_arr as $pair_str) {
                        $pair_str = trim($pair_str);
                        $pair = explode('=', $pair_str);

                        if (count($pair) != 2) {
                            continue;
                        }

                        $key = $pair[0];
                        $key = Dj_App_String_Util::trim($key);

                        $val = $pair[1];
                        $val = Dj_App_String_Util::trim($val);
                        $val = trim($val, '"\'');

                        $params[$key] = $val;
                    }
                }

                // capture the output of the callback
                ob_start();
                $result = call_user_func($callback, $params);
                $output = ob_get_clean();

                if (empty($result) && !empty($output)) {
                    $result = $output;
                }

                $content = str_replace($tag_and_params_str, $result, $content);
            }
        }

        $buff = $content_before_body . $content;

        return $buff;
    }

    /**
     * adds a callback for a shortcode
     * @return void
     */
    public function addShortcode($tag, $callback = null)
    {
        $shortcodes = $this->getShortcodes();

        if (!is_callable($callback)) {
            trigger_error("Invalid callback for shortcode: [$tag]", E_USER_WARNING);
            return;
        }

        $tag = $this->formatShortCode($tag);
        $shortcodes[$tag] = $callback;

        $this->setShortcodes($shortcodes);
    }

    public function removeShortcode($tag)
    {
        $shortcodes = $this->getShortcodes();
        unset($shortcodes[$tag]);
        $this->setShortcodes($shortcodes);
    }

    /**
     * @param string $tag
     * @return void
     */
    public function replaceShortcode($tag)
    {

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

    public function getShortcodes(): array
    {
        return $this->shortcodes;
    }

    public function setShortcodes(array $shortcodes): void
    {
        $this->shortcodes = $shortcodes;
    }

    /**
     * Prepares shortcodes in content by:
     * - normalizing dashes to underscores
     * - converting chars to lowercase
     * Processes character by character to avoid regex
     * @param string $buff
     * @return string
     */
    public function prepareShortcodes($buff)
    {
        if (empty($buff)) {
            return '';
        }

        $square_pos = strpos($buff, '[');

        if ($square_pos === false) {
            return $buff;
        }

        $len = strlen($buff);
        $result = substr($buff, 0, $square_pos); // Copy everything before first [
        $i = $square_pos;

        while ($i < $len) {
            // Find next shortcode start
            if ($i > $square_pos) {
                $square_pos = strpos($buff, '[', $i);
                if ($square_pos === false) {
                    // No more shortcodes, append rest of buffer and break
                    $result .= substr($buff, $i);
                    break;
                }

                // Append everything between last position and new shortcode
                $result .= substr($buff, $i, $square_pos - $i);
                $i = $square_pos;
            }

            // Find closing bracket
            $closing_pos = strpos($buff, ']', $i);

            if ($closing_pos === false) { // No closing bracket, append rest and break
                $result .= substr($buff, $i);
                break;
            }

            // Process shortcode content
            $in_shortcode_name = true;

            for ($j = $i; $j <= $closing_pos; $j++) {
                $char = $buff[$j];

                // Stop normalizing when we hit any whitespace (params start)
                if ($in_shortcode_name && ctype_space($char)) {
                    $in_shortcode_name = false;
                }

                if ($in_shortcode_name) {
                    if ($char === '-') {
                        $result .= '_';
                    } else {
                        $result .= strtolower($char);
                    }
                } else {
                    $result .= $char;
                }
            }

            $i = $closing_pos + 1;
        }

        return $result;
    }
}
