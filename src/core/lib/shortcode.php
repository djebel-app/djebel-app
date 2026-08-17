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

        // [djebel_page_nav]
        $this->addShortcode('djebel_page_nav', [ $this, 'renderNav' ]);

        //
        $this->addShortcode('djebel_page_content', [ $this, 'renderContent' ]);
        $this->addShortcode('djebel_page_footer', [ $this, 'renderPageFooter' ]);

        Dj_App_Hooks::addFilter( 'app.page.full_content', [ $this, 'replaceShortCodes'] );
    }

    /**
     * replaces [djebel_page_nav] shortcode with whatever the site config defines for the nav
     * @todo add ids and position?
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
     * Replaces [djebel_page_content] shortcode with page content
     * First checks if content was loaded by plugin (site_content), then falls back to theme
     *
     * @param array $params
     * @return string
     */
    public function renderContent($params = [])
    {
        $page_obj = Dj_App_Page::getInstance();

        // Check if content already loaded by plugin (e.g., site_content)
        if ($page_obj->hasContent()) {
            $buff = $page_obj->getContent();
        } else {
            // Fall back to theme's content rendering
            ob_start();
            Dj_App_Hooks::doAction('app.page.content.render', $params);
            $buff = ob_get_clean();
        }

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
     * replaces [djebel_page_footer] shortcode with some footer text
     * @param array $params
     * @return string
     */
    public function renderPageFooter($params = []) {
        ob_start();
        $page_obj = Dj_App_Page::getInstance();
        $djebel_url_esc = dj_esc_url(Dj_App::SITE_URL);
        $powered_by = "Powered by <a href='$djebel_url_esc' target='_blank'>Djebel</a>";
        $powered_by = Dj_App_Hooks::applyFilter('app.page.render.footer.powered_by', $powered_by);
        ?>
        <div>
            &copy; <?php echo date('Y'); ?>
            All rights reserved.
            <?php echo $page_obj->esc_site_title; ?>
            <?php echo $powered_by; ?>
        </div>
        <?php
        $buff = ob_get_clean();
        $buff = Dj_App_Hooks::applyFilter('app.page.render.footer', $buff, $params);
        return $buff;
    }

    /**
     * Formats a shortcode name and normalizes it.
     * @param string $code
     * @return string
     */
    public function formatShortCode($code)
    {
        static $extra_allowed_chars = [ '_', ];

        $code = Dj_App_String_Util::sanitizeAlphaNumericExt($code, $extra_allowed_chars);
        $code = Dj_App_String_Util::singlefy($code, '_');
        $code = Dj_App_String_Util::trim($code, '_');
        $code = strtolower($code);
        return $code;
    }

    /**
     * Answers whether the content carries at least one REGISTERED shortcode, in either
     * separator form — a page may write [my-tag] for a tag registered as my_tag.
     *
     * This is the guard that decides whether the buffer gets rewritten at all, so it is
     * built to cost far less than the rewriting it prevents: a single strpos rejects the
     * overwhelming majority of pages, and only then is the registry walked.
     *
     * @param string $content
     * @return bool
     */
    public function hasShortcode($content)
    {
        if (empty($content)) {
            return false;
        }

        // No bracket anywhere — the cheapest possible answer, and the common one.
        if (strpos($content, '[') === false) {
            return false;
        }

        $shortcodes = $this->getShortcodes();

        foreach ($shortcodes as $shortcode => $callback) {
            if (stripos($content, '[' . $shortcode) !== false) {
                return true;
            }

            // Names are stored underscored, so the dash spelling a page may use has to be
            // searched for on its own. A name without underscores yields the same string,
            // so skip the duplicate scan.
            $dash_form = str_replace('_', '-', $shortcode);

            if ($dash_form == $shortcode) {
                continue;
            }

            if (stripos($content, '[' . $dash_form) !== false) {
                return true;
            }
        }

        return false;
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

        // CHEAPEST CHECK FIRST. Both passes below walk the ENTIRE buffer, so a page that
        // carries no registered shortcode must pay for neither — and, more importantly,
        // must not have its other bracketed text rewritten underneath it. Page markup
        // legitimately contains brackets that are not shortcodes (a CSS/JS attribute
        // selector, an array index), and normalizing those corrupts them.
        if (!$this->hasShortcode($content)) {
            return $buff;
        }

        // Escape shortcode brackets inside <pre> and <code> blocks before processing
        $content = $this->escapeShortcodesInCodeBlocks($content);

        // Normalize the dash form of registered shortcode names to underscores
        $content = $this->prepareShortcodes($content);

        // Escaping above can consume the first bracket, so the loop's start offset is read
        // from the REWRITTEN content rather than carried over from before the passes.
        $square_pos = strpos($content, '[');

        if ($square_pos === false) {
            return $buff;
        }

        // OFF by default: the same tag with the same params yields the same output, so it is
        // rendered once and reused for every occurrence — N identical tags cost one call, not
        // N. Turn it on ([app] shortcodes.process_all) only for a shortcode that must run per
        // occurrence (a counter, a random pick), and pay N calls for it.
        // Resolved here, past the guards, so a page that bails above never pays the lookup.
        $options_obj = Dj_App_Options::getInstance();
        $process_all_default = Dj_App_Config::cfg('app.core.shortcodes.process_all', false);
        $process_all = $options_obj->isEnabled('app.shortcodes.process_all', $process_all_default);

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
                    $params = $this->parseShortcodeParams($params_str);
                }

                // capture the output of the callback
                ob_start();
                $result = call_user_func($callback, $params);
                $output = ob_get_clean();

                if (empty($result) && !empty($output)) {
                    $result = $output;
                }

                if ($process_all) {
                    // Only the occurrence just found is consumed, so the next pass calls the
                    // callback again for the next one.
                    $tag_len = strlen($tag_and_params_str);
                    $content = substr_replace($content, $result, $current_short_code_start_pos, $tag_len);
                } else {
                    // Same tag and same params produce the same output, so one call covers
                    // every occurrence and the next strpos finds none of them left.
                    $content = str_replace($tag_and_params_str, $result, $content);
                }
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

    public function getShortcodes()
    {
        return $this->shortcodes;
    }

    public function setShortcodes($shortcodes)
    {
        $this->shortcodes = $shortcodes;
    }

    /**
     * Parses shortcode parameters respecting quoted values.
     * Handles: key="value with spaces" key2=simple_value
     *
     * @param string $params_str Raw parameter string
     * @return array Parsed key-value pairs
     */
    public function parseShortcodeParams($params_str)
    {
        $i = 0;
        $params = [];

        if (empty($params_str) || !is_scalar($params_str)) {
            return $params;
        }

        $len = strlen($params_str);

        while ($i < $len) {
            // Skip whitespace
            while ($i < $len && ctype_space($params_str[$i])) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            // Extract key
            $key_start = $i;

            while ($i < $len && $params_str[$i] !== '=' && !ctype_space($params_str[$i])) {
                $i++;
            }

            $key = substr($params_str, $key_start, $i - $key_start);

            // Skip whitespace and equals
            while ($i < $len && (ctype_space($params_str[$i]) || $params_str[$i] === '=')) {
                $i++;
            }

            if ($i >= $len) {
                break;
            }

            // Extract value (handle quotes)
            if ($params_str[$i] === '"' || $params_str[$i] === "'") {
                $quote_char = $params_str[$i];
                $i++; // Skip opening quote
                $val_start = $i;

                // Find closing quote
                while ($i < $len && $params_str[$i] !== $quote_char) {
                    $i++;
                }

                $val = substr($params_str, $val_start, $i - $val_start);
                $i++; // Skip closing quote
            } else {
                // Unquoted value: read until space
                $val_start = $i;

                while ($i < $len && !ctype_space($params_str[$i])) {
                    $i++;
                }

                $val = substr($params_str, $val_start, $i - $val_start);
            }

            // Store parameter
            if (!empty($key)) {
                $params[$key] = $val;
            }
        }

        return $params;
    }

    /**
     * Escapes shortcode-like brackets inside <pre> and <code> blocks
     * so the shortcode engine doesn't process them.
     * Uses Dj_App_String_Util::escapeShortcodeBrackets() which converts [ to &#91;
     * only when followed by a letter (actual shortcode pattern).
     *
     * @param string $content
     * @return string
     */
    public function escapeShortcodesInCodeBlocks($content)
    {
        if (empty($content)) {
            return '';
        }

        // Quick check — no brackets means nothing to escape
        if (strpos($content, '[') === false) {
            return $content;
        }

        // Quick check — no code blocks means nothing to do
        if ((stripos($content, '<code') === false) && (stripos($content, '<pre') === false)) {
            return $content;
        }

        // Process <pre> first (catches <pre><code>...</code></pre> too),
        // then standalone <code> outside <pre>
        $code_tags = [ 'pre', 'code', ];

        foreach ($code_tags as $tag) {
            $content = $this->escapeShortcodesInTag($content, $tag);
        }

        return $content;
    }

    /**
     * Escapes shortcode brackets inside all instances of a specific HTML tag.
     * Walks through the content with strpos, finds each open/close tag pair,
     * and escapes brackets in the inner content.
     *
     * @param string $content
     * @param string $tag HTML tag name (e.g. 'pre', 'code')
     * @return string
     */
    public function escapeShortcodesInTag($content, $tag)
    {
        $open_tag = '<' . $tag;
        $close_tag = '</' . $tag . '>';
        $close_tag_len = strlen($close_tag);
        $search_pos = 0;

        while (($open_pos = stripos($content, $open_tag, $search_pos)) !== false) {
            // Find end of opening tag (handles attributes like <pre class="...">)
            $open_tag_end = strpos($content, '>', $open_pos);

            if ($open_tag_end === false) {
                break;
            }

            $content_start = $open_tag_end + 1;

            // Find closing tag
            $close_pos = stripos($content, $close_tag, $content_start);

            if ($close_pos === false) {
                break;
            }

            $inner_len = $close_pos - $content_start;
            $inner = substr($content, $content_start, $inner_len);

            // Only escape if there are brackets inside
            if (strpos($inner, '[') === false) {
                $search_pos = $close_pos + $close_tag_len;
                continue;
            }

            $escaped = Dj_App_String_Util::escapeShortcodeBrackets($inner);

            // Only rebuild the string if something actually changed
            if ($escaped === $inner) {
                $search_pos = $close_pos + $close_tag_len;
                continue;
            }

            $before = substr($content, 0, $content_start);
            $after = substr($content, $close_pos);
            $content = $before . $escaped . $after;

            // Adjust search position for the length difference
            $len_diff = strlen($escaped) - $inner_len;
            $search_pos = $close_pos + $close_tag_len + $len_diff;
        }

        return $content;
    }

    /**
     * Rewrites each written shortcode name to the key it was registered under, so a page
     * may spell a tag with '-' or '_' interchangeably. Walks bracket to bracket with
     * strpos rather than scanning the buffer, and normalizes through formatShortCode() —
     * the same function registration uses, so both sides can never disagree.
     *
     * Only a bracket run whose name is a REGISTERED shortcode is rewritten. Every other
     * run is copied byte-for-byte: page markup legitimately contains brackets that are
     * not shortcodes — a CSS/JS attribute selector, an array index — and normalizing
     * those silently corrupts the page.
     *
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

        // With nothing registered there is no name to normalize toward, so no run qualifies.
        $shortcodes = $this->getShortcodes();

        if (empty($shortcodes)) {
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

            $run_len = $closing_pos - $i + 1;
            $bracket_run = substr($buff, $i, $run_len);

            // Split the run into its name and whatever params follow the first whitespace.
            $inner_len = $closing_pos - $i - 1;
            $inner = substr($buff, $i + 1, $inner_len);
            $name_len = strcspn($inner, " \t\r\n");
            $name = substr($inner, 0, $name_len);
            $params_str = substr($inner, $name_len);

            // ONE normalizer decides what a written name resolves to, and it is the same
            // one that maps a name at registration — so '-' and '_' alias each other in
            // both directions and repeats collapse identically on both sides. Normalizing
            // here by any other rule is how [double--dash] became a name no registration
            // could ever produce.
            $name = $this->formatShortCode($name);

            // Not a registered shortcode — copy the run verbatim and move on.
            if (empty($shortcodes[$name])) {
                $result .= $bracket_run;
                $i = $closing_pos + 1;

                continue;
            }

            $result .= '[' . $name . $params_str . ']';
            $i = $closing_pos + 1;
        }

        return $result;
    }
}
