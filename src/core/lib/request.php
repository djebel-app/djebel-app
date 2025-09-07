<?php

class Dj_App_Request {
    const INT = 2;
    const FLOAT = 4;
    const ESC_ATTR = 8;
    const JS_ESC_ATTR = 16;
    const EMPTY_STR = 32; // when int/float nubmers are 0 make it an empty str
    const STRIP_SOME_TAGS = 64;
    const STRIP_ALL_TAGS = 128;
    const SKIP_STRIP_ALL_TAGS = 256;
    const REDIRECT_EXTERNAL_SITE = 2;

    /**
     * @var array
     * @see https://codex.wordpress.org/Function_Reference/wp_kses
     */
    private $allowed_permissive_html_tag_attribs = [
        'id' => [],
        'title' => [],
        'style' => [],
        'class' => [],
    ];

    /**
     * @var array
     * @see https://codex.wordpress.org/Function_Reference/wp_kses
     */
    private $allowed_permissive_html_tags = array(
        'a' => array(
            'href' => [],
            'target' => [],
        ),
        'br' => [],
        'em' => [],
        'i' => [],
        'b' => [],
        'p' => [],
        'img' => [
            'src' => [],
            'data-src' => [],
            'data-srcset' => [],
            'border' => [],
        ],
        'code' => [],
        'blockquote' => [],
        'ins' => [],
        'del' => [],
        'div' => [],
        'pre' => [],
        'span' => [],
        'link' => [ 'rel' => [], 'type' => [], 'media' => [], 'href' => [], ],
        'style' => [],
        'strong' => [],
        'hr' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
    );

    protected $data = null;
    protected $raw_data = [];

    private $request_data = [];

    /**
     * Some sections of the code may pass state so it can be pulled from another spot.
     * @var array
     */
    private $state = [];

    public function __construct() {
        $this->init();
        $this->parseRequest();

        // we want to wait until the system plugins have been loaded to parse request
        Dj_App_Hooks::addAction( 'app.core.plugins.system_loaded', [ $this, 'hookActionsAfterSystemPluginLoaded'] );
    }

    public function hookActionsAfterSystemPluginLoaded()
    {
        $this->parseRequest();
    }

    /**
     * @return array
     * @throws Exception
     */
    public function parseRequest()
    {
        $req_url = $this->getCleanRequestUrl();
        $ctx = [];
        $ctx['req_url'] = $req_url;

        // Detect the web path with multiple fallback methods
        $web_path = $this->getWebPath();
        $ctx['web_path'] = $web_path;

        // We want to skip the web path and then get the segments
        $trimmed_url = $req_url;
        $prefix_position = strpos($trimmed_url, $web_path);
        
        if ($prefix_position !== false) {
            $trimmed_url = substr($req_url, $prefix_position + strlen($web_path));
        }

        $trimmed_url = Dj_App_Util::removeSlash($trimmed_url);
        $trimmed_url = empty($trimmed_url) ? '/' : $trimmed_url;

        // Split the web path into segments
        $segments = explode('/', $trimmed_url);

        // ensure the segments are good alphanumeric strings
        foreach ($segments as $idx => $segment) {
            $segment = urldecode($segment);
            $segment = $this->formatPageSlug($segment);
            $segments[$idx] = $segment;
        }

        $segments = $this->segments($segments);
        $ctx['segments'] = $segments;
        $ctx['trimmed_url'] = $trimmed_url;

        $data = $ctx;
        $data = Dj_App_Hooks::applyFilter('app.core.request.parse', $data, $ctx);
        $this->request_data = $data;

        return $data;
    }

    /**
     * @param string $page
     * @return string
     */
    public function formatPageSlug($page)
    {
        // loop through the page and remove non alpha numeric and dash
        $page = preg_replace('/[^\w\-]/si', '_', $page);
        $page = preg_replace('/_+/si', '_', $page);
        $page = substr($page, 0, 100);
        $page = trim($page, '_');

        return $page;
    }

    /**
     * Gets the web path
     * @param array $ctx Optional context array for filters
     * @return string The web path
     */
    public function getWebPath($ctx = [])
    {
        // Get current web path or detect if not set
        if (empty($this->request_data['web_path'])) {
            $web_path = $this->detectWebPath();
            $this->request_data['web_path'] = $web_path;
        } else {
            $web_path = $this->request_data['web_path'];
        }

        $web_path = Dj_App_Hooks::applyFilter('app.core.request.web_path', $web_path, $ctx);

        return $web_path;
    }

    /**
     * @param string $web_path
     * @return string
     */
    public function contentUrlPrefix($ctx = [])
    {
        $ctx['context'] = 'content_url';
        $content_web_path = $this->getWebPath($ctx);
        $content_dir = Dj_App_Util::getContentDir();
        $content_web_path = $content_web_path . '/' . basename($content_dir);
        $content_web_path = Dj_App_Hooks::applyFilter('app.core.request.content_web_path', $content_web_path, $ctx);

        return $content_web_path;
    }

    /**
     * Get a more reliable web path by considering multiple detection methods
     * @return string
     */
    public function detectWebPath()
    {
        // Method 1: Simple filter to tell what the web path is
        $web_path = Dj_App_Hooks::applyFilter('app.core.request.detect_web_path', '', []);

        if (!empty($web_path)) {
            return $web_path;
        }

        // Method 2: Simple prefix detection when SCRIPT_NAME is stripped
        $request_uri = empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI'];
        $script_name = empty($_SERVER['SCRIPT_NAME']) ? '' : $_SERVER['SCRIPT_NAME'];

        // Check if REQUEST_URI ends with SCRIPT_NAME (stripped prefix scenario)
        if (!empty($request_uri) && !empty($script_name)) {
            $script_pos = strpos($request_uri, $script_name);
            
            if (($script_pos !== false) && ($script_pos + strlen($script_name) === strlen($request_uri))) {
                $web_path = substr($request_uri, 0, $script_pos);
                $web_path = Dj_App_Util::removeSlash($web_path);
                
                if (!empty($web_path)) {
                    return $web_path;
                }
            }
        }

        // Method 3: Traditional detection from SCRIPT_NAME or PHP_SELF
        if (!empty($script_name)) {
            $web_path = dirname($script_name);
            $web_path = Dj_App_Util::removeSlash($web_path);

            if (!empty($web_path) && $web_path != '.') {
                return $web_path;
            }
        }
        
        $php_self = empty($_SERVER['PHP_SELF']) ? '' : $_SERVER['PHP_SELF'];

        if (!empty($php_self)) {
            $web_path = dirname($php_self);
            $web_path = Dj_App_Util::removeSlash($web_path);

            if (!empty($web_path) && $web_path != '.') {
                return $web_path;
            }
        }

        // Method 4: Fallback to document root detection
        $document_root = empty($_SERVER['DOCUMENT_ROOT']) ? '' : $_SERVER['DOCUMENT_ROOT'];
        
        if (!empty($request_uri) && !empty($document_root)) {
            $current_script_path = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
            
            if (!empty($current_script_path)) {
                $relative_path = str_replace($document_root, '', $current_script_path);
                $relative_path = dirname($relative_path);
                $relative_path = Dj_App_Util::removeSlash($relative_path);
                $relative_path = rtrim($relative_path, '.');

                if (!empty($relative_path)) {
                    return $relative_path;
                }
            }
        }

        return '/';
    }

    /**
     * Returns all segments or an empty array if no segments are available.
     * @return array
     */
    public function segments($segments = null) {
        if (!is_null($segments)) {
            $segments = empty($segments) ? [] : $segments;
            $segments = array_filter($segments); // rm empty elements
            $segments = array_values($segments); // reorder indexes to start from 0
            $this->request_data['segments'] = $segments;
        }

        $segments = empty($this->request_data['segments']) ? [] : $this->request_data['segments'];
        $segments = Dj_App_Hooks::applyFilter( 'app.core.request.segments', $segments );

        return $segments;
    }

    /**
     * Magic method to handle dynamic method calls.
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws Exception
     */
    public function __call($name, $arguments) {
        if ((strpos($name, 'segment') !== false)
            && preg_match('/^segments?(\d+)$/', $name, $matches)
        ) {
            $index = (int)$matches[1] - 1; // Convert to zero-based index
            $segments = $this->segments();

            // duplicate of __get
            $ctx = [];
            $ctx['index'] = $index;
            $val = empty($segments[$index]) ? '': $segments[$index];
            $val = Dj_App_Hooks::applyFilter( 'app.core.request.get_segment', $val, $ctx );
            return $val;
        }

        throw new Exception("Method [$name] does not exist");
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

    /**
     * if a key exists in the request
     * @param $key
     * @return bool
     */
    public function has( $key) {
        return isset($this->data[$key]);
    }

    /**
     * if a key exists in the request
     * @param $key
     * @return bool
     */
    public function hasPostKey($key) {
        return isset($_POST[$key]);
    }

    /**
     * Gets a request variable mostly unchanged. Only spaces are removed.
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getRaw( $key, $default = '') {
        $val = !empty($this->raw_data[$key]) ? $this->raw_data[$key] : $default;
        $val = Dj_App_String_Util::trim($val);
        return $val;
    }

    /**
     * @param string $key
     * @param mixed $val
     * @return mixed
     */
    public function set(string $key, $val) {
        if (is_null($val)) {
            unset($this->data[$key]);
        } else {
            if ($key == 'redirect_to') {
                $val = $this->parseCustomRedirectString($val);
            }

            $this->data[$key] = $val;
        }

        return $val;
    }

    /**
     * Checks a request val and compares it to a value or a list of a values separated by comma, ; or |
     * $req_obj->getAndCompare('action', 'lostpassword,lp')
     * @param string $key
     * @param mixed $checked_val
     * @param mixed $default
     * @return bool
     */
    public function getAndCompare($key = '', $checked_val = '', $default = '') {
        $val = $this->get($key, $default);

        // Both empty
        if (empty($checked_val) && empty($val)) {
            return true;
        }

        // different. no need to check other cases
        if (!empty($checked_val) && empty($val)) {
            return false;
        }

        if (strcasecmp($val, $checked_val) == 0) {
            return true;
        }

        $sep = $this->getSep($checked_val);

        $vals = explode($sep, $checked_val);
        $vals = array_map('trim', $vals);
        $vals = array_filter($vals);
        $vals = array_unique($vals);

        if (in_array($val, $vals)) {
            return true;
        }

        return false;
    }

    /**
     * $req_obj->getSeparators();
     * @param string $key
     * @return mixed
     */
    public function getSeparators() {
        return [ ',', ';', '|', ];
    }

    /**
     * $req_obj->getSep();
     * @param string $key
     * @return mixed
     */
    public function getSep($checked_val = '') {
        foreach ($this->getSeparators() as $loop_sep) {
            if (strpos($checked_val, $loop_sep) !== false) {
                return $loop_sep;
            }
        }

        return '';
    }

    /**
     * $req_obj = Dj_App_Request::getInstance();
     * $req_obj->get();
     * check for multiple fields and return when once matches.
     * ->get('contact_form_msg|msg|message')
     * @param string $key
     * @return mixed
     */
    public function get( $key = '', $default = '', $force_type = 1 ) {
        if (empty($key)) {
            return $this->data;
        }

        $key = trim( $key );
        $sep = $this->getSep($key);

        // ->get('contact_form_msg|msg|message')
        if (!empty($sep)) { // checking for multiple keys
            $separtors = $this->getSeparators();
            $separtors = array_map('trim', $separtors);
            $separtors = array_unique($separtors);
            $separtors_esc = array_map('preg_quote', $separtors);
            $separtors_esc_str = join('', $separtors_esc); // we'll put them in a regex group [;\|]

            // We split on all of them because there could be multiple separators.
            // the getSep returned the first one that matched.
            $multiple_keys = preg_split("/[$separtors_esc_str]+/si", $key);
            $multiple_keys = array_map('trim', $multiple_keys);
            $multiple_keys = array_unique($multiple_keys);

            foreach ($multiple_keys as $loop_key) {
                $loop_val = $this->get($loop_key); // recursion!

                if (!empty($loop_val)) {
                    return $loop_val;
                }
            }

            // nothing found for the multiple keys so don't check below.
            return $default;
        }

        $val = !empty($this->data[$key]) ? $this->data[$key] : $default;

        if ( $force_type & self::INT ) {
            $val = intval($val);

            if ( $val == 0 && $force_type & self::EMPTY_STR ) {
                $val = "";
            }
        }

        if ( $force_type & self::FLOAT ) {
            $val = floatval($val);

            if ( $val == 0 && $force_type & self::EMPTY_STR ) {
                $val = "";
            }
        }

        if ( $force_type & self::ESC_ATTR ) {
            $val = esc_attr($val);
        }

        if ( $force_type & self::JS_ESC_ATTR ) {
            $val = esc_js($val);
        }

        if ( $force_type & self::STRIP_SOME_TAGS ) {
            $allowed_tags = [];

            // Let's merge the tags with the default allowed attribs
            foreach ($this->allowed_permissive_html_tags as $tag => $allowed_attribs) {
                $allowed_attribs = array_replace_recursive($allowed_attribs, $this->allowed_permissive_html_tag_attribs);
                $allowed_tags[$tag] = $allowed_attribs;
            }

            $val = wp_kses($val, $allowed_tags);
        }

        // Sanitizing a var
        if ( $force_type & self::STRIP_ALL_TAGS ) {
            $val = wp_kses($val, []);
        }

        $val = is_scalar($val) ? trim($val) : $val;

        // passing email via request param converts + to spaces. Sometimes I am too busy to encode the +
        if (strpos($key, 'email') !== false) {
            $val = str_replace( ' ', '+', $val );
        }

        return $val;
    }

    /**
     * WP puts slashes in the values so we need to remove them.
     * @param array $data
     */
    public function init( $data = null ) {
        // see https://codex.wordpress.org/Function_Reference/stripslashes_deep
        if ( is_null( $this->data ) ) {
            $data = empty( $data ) ? $_REQUEST : $data;
            $this->raw_data = $data;

            if (function_exists('stripslashes_deep')) {
                $data = stripslashes_deep($data);
            }

            $data = Dj_App_Hooks::applyFilter( 'app.core.request.pre_sanitize_data', $data );
            $data = $this->sanitizeData( $data );
            $data = Dj_App_Hooks::applyFilter( 'app.core.request.post_sanitize_data', $data );
            $this->data = $data;
        }
    }

    /**
     * Add or get a cookie.
     * @param $key
     * @param $val
     * @return void
     */
    public function cookie( $key, $val = null ) {
        if (is_null($val)) {
            $val = empty($_COOKIE[$key]) ? '' : $_COOKIE[$key];
            return $val;
        }

        $_COOKIE[$key] = $val;
    }

    /**
     *
     * @param string|array $data
     * @return string|array
     * @throws Exception
     */
    public function sanitizeData( $data = null ) {
        $use_wp_kees = function_exists('wp_kses');

        if ( is_scalar( $data ) ) {
            // rm null char jic
            $data = str_replace( chr(0), '', $data );

            //$data = wp_strip_all_tags( $data ); // this really cleans stuff
            //$data = sanitize_text_field( $data ); // <- this breaks urls passed as url params such as next_link
            //$data = wp_kses_data( $data ); // <- this encodes & -> &amp; and breaks the links with params.
            $allowed_tags = [];

            // Let's merge the tags with the default allowed attribs
            foreach ($this->allowed_permissive_html_tags as $tag => $allowed_attribs) {
                $allowed_attribs = array_replace_recursive($allowed_attribs, $this->allowed_permissive_html_tag_attribs);
                $allowed_tags[$tag] = $allowed_attribs;
            }

            // wp_kses() breaks URLs and adds &amp; stuff
            if ($use_wp_kees) {
                $data = wp_kses($data, $allowed_tags);

                if (strpos($data, '&amp;') !== false) {
                    $data = str_replace('&amp;', '&', $data);
                }
            } else {
                $data = strip_tags($data, join('', array_keys($allowed_tags)));
            }

            $data = trim( $data );
        } elseif ( is_array( $data ) ) {
            $data = array_map( array( $this, 'sanitizeData' ), $data );
        } elseif (is_null($data)) { // maybe it's run from the command line
            $data = '';
        } else {
            throw new Exception( "Invalid data type passed for sanitization" );
        }

        return $data;
    }

    /**
     *
     * @param array/void $params
     * @return bool
     */
    public function validate($params = []) {
        return !empty($_POST);
    }

    /**
     * Checks if current request is HEAD
     * Uses early returns and first char check for efficiency
     * 
     * @return bool True if HEAD request
     */
    public function isHead() 
    {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $req_method = $_SERVER['REQUEST_METHOD'];
        $req_char = substr($req_method, 0, 1);

        if (strcasecmp($req_char, 'h') != 0) {
            return false;
        }

        return strcasecmp($req_method, 'head') == 0;
    }

    /**
     *
     * @param void
     * @return bool
     */
    public function isGet() {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $req_method = $_SERVER['REQUEST_METHOD'];
        $req_char = substr($req_method, 0, 1);

        if (strcasecmp($req_char, 'g') != 0) {
            return false;
        }

        return strcasecmp($req_method, 'get') == 0;
    }

    /**
     * If post field is passed we want request to be POST and that field to have been passed.
     * @param string $post_field
     * @return bool
     */
    public function isPost($post_field = '') {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $req_method = $_SERVER['REQUEST_METHOD'];
        $req_char = substr($req_method, 0, 1);

        if (strcasecmp($req_char, 'p') != 0) {
            return false;
        }

        if (!empty($post_field)) {
            if (!$this->hasPostKey($post_field)) {
                return false;
            }
        }

        if (strcasecmp($req_method, 'post') == 0) {
            return true;
        }

        return false;
    }


    const REDIRECT_FORCE = 1;
    const REDIRECT_DEFAULT = 0;

    /**
     * Do a redirect without asking too many questions or doing too many regular redirect loop checks. Use wisely.
     * Redirects and exits. Outputs HTML code if there was some output
     * @param string $url
     * @param array $params
     * @return void
     */
    public function redirectNow($url, $params = []) {
        if (defined('WP_CLI') || empty($url)) {
            // don't do anything if WP-CLI is running.
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) { // wp cron
            return;
        }

        // Don't do anything for ajax requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $loading_text = __("Loading ...", 'qs_site_app');
        $loading_text_esc_as_attrib = Djebel_App_HTML::encodeEntities($loading_text);
        $url_esc = Djebel_App_HTML::encodeEntities($url); // esc_attr breaks stuff the wp_safe redir does this

        // there's some html output. Add JS redirect and html redirect the same time.
        if ( headers_sent() ) { // if we encode it twice data won't be transferred.
            echo sprintf('<meta http-equiv="refresh" content="0;URL=\'%s\'" />', $url_esc);

            // we want any previous content to be removed, so it doesn't confuse the user
            // for some reason the escaped url doesn't work. When the app redirects apache returns 404
            echo <<<CLEAR_AND_REDIRECT_HTML
<script type='text/javascript'>
	document.body.innerHTML = ''; // Clear the body
	let loadingMessage = document.createElement('h3');
	loadingMessage.innerText = '$loading_text_esc_as_attrib';
    loadingMessage.style.backgroundColor = '#FFFDD0'; // Set yellow background
    loadingMessage.style.paddingLeft = '10px'; // Set 10px left padding
	loadingMessage.style.paddingRight = '10px'; // Set 10px right padding
	document.body.appendChild(loadingMessage);
	window.parent.location = '$url_esc';
</script>

<noscript>
	<h3><a href="$url_esc">Continue</a></h3>
</noscript>

CLEAR_AND_REDIRECT_HTML;
        } else {
            $redir_code = 302;
            header("Location: $url", 302, $redir_code);
        }

        exit;
    }

    /**
     * Smart redirect method. Sends header redirect or HTTP meta redirect.
     * @param string $url
     * @return void
     */
    public function redirect($url = '', $force = self::REDIRECT_DEFAULT) {
        if (defined('WP_CLI') || empty($url)) {
            // don't do anything if WP-CLI is running.
            return;
        }

        if (defined('DOING_CRON') && DOING_CRON) { // wp cron
            return;
        }

        // Don't do anything for ajax requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $local_ips = [ '::1', '127.0.0.1' ];

        if ($force == self::REDIRECT_DEFAULT
            && (!empty($_SERVER['REMOTE_ADDR'])
                && !in_array($_SERVER['REMOTE_ADDR'], $local_ips)
                && $_SERVER['REMOTE_ADDR'] == $_SERVER['SERVER_ADDR'])
        ) { // internal req or dev machine
            return;
        }

        $url = Dj_App_Hooks::applyFilter( 'app.request.redirect_url', $url );
        $req_url = $this->getRequestUrl();
        $future_redirect_url = $url;
        $future_redirect_url_web_path = parse_url($future_redirect_url, PHP_URL_PATH);

        // On that page already. This happens when we redirect but the browser keeps the POST data with 307 redir.
        if ($req_url == $future_redirect_url_web_path) {
            return;
        }

        $this->redirectNow($url);
    }

    /**
     * @return string
     */
    public function getRequestUrl() {
        static $req_url = '';

        if (!empty($req_url)) { // let's save some ns
            return $req_url;
        }

        $req_url = empty($_SERVER['REQUEST_URI']) ? '' : $_SERVER['REQUEST_URI'];

        return $req_url;
    }

    /**
     * returns URL without any request params e.g. ?x=y
     * @param string
     * @return string
     */
    public function getCleanRequestUrl($url = '') {
        $clean_url = empty($url) ? $this->getRequestUrl() : $url;
        $clean_url = strip_tags($clean_url);
        $clean_url = Dj_App_String_Util::trim($clean_url);

        if (empty($clean_url)) {
            return '';
        }

        $question_mark_pos = strpos($clean_url, '?');

        if ($question_mark_pos !== false) {
            $clean_url = substr($clean_url, 0, $question_mark_pos);
        }

        return $clean_url;
    }

    /**
     * Quick method for checking if we're on a given page.
     * Supports regex pipe to check multiple pages.
     * @param string $page
     * @param string $req_url
     * @return string
     */
    public function requestUrlMatches($page, $req_url = '') {
        $req_url = empty($req_url) ? $this->getRequestUrl() : $req_url;

        if (stripos($req_url, $page) !== false) {
            return true;
        }

        $page = str_replace('|', '__PIPE_ESC__', $page);
        $regex = '#' . preg_quote($page, '#') . '#si';
        $regex = str_replace('__PIPE_ESC__', '|', $regex);
        $match = preg_match($regex, $req_url);
        return $match;
    }

    /**
     * Quick method for checking if we're on a given page.
     * @return string
     */
    public function getHost() {
        $host = '';

        if (!empty($_SERVER['SERVER_NAME'])) {
            $host = $_SERVER['SERVER_NAME'];
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $host = $_SERVER['HTTP_HOST'];
        } else {
            // should we check all headers?
        }

        $host = strip_tags($host);
        $host = trim($host);

        return $host;
    }

    /**
     * Get user agent.
     * @return string
     */
    public function getUserAgent() {
        $user_agent = '';

        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $user_agent = $_SERVER['HTTP_USER_AGENT'];
            $user_agent = strip_tags($user_agent);
            $user_agent = trim($user_agent);
        }

        return $user_agent;
    }

    /**
     * Efficiently checks if the current request is AJAX
     * Checks both standard XMLHttpRequest and custom headers
     * 
     * @return bool True if AJAX request
     */
    public function isAjax() 
    {
        static $is_ajax = null;

        if (!is_null($is_ajax)) {
            return $is_ajax;
        }

        $is_ajax = false;

        // Standard XMLHttpRequest check
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $first_char = substr($_SERVER['HTTP_X_REQUESTED_WITH'], 0, 1);
            $first_char_lc = strtolower($first_char);

            if ($first_char_lc == 'x' && strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'], 'xmlhttprequest') == 0) {
                $is_ajax = true;
                return true;
            }
        }

        // Check for common AJAX request indicators
        $ajax_indicators = [
            'HTTP_X_AJAX',           // Custom header some frameworks use
            'HTTP_X_PJAX',           // PJAX requests
            'HTTP_X_FETCH',          // Fetch API requests
        ];

        foreach ($ajax_indicators as $header) {
            if (!empty($_SERVER[$header])) {
                $is_ajax = true;
                return true;
            }
        }

        // somehow WP was loaded?
        if (defined( 'DOING_AJAX' ) && DOING_AJAX) {
            return true;
        }

        return false;
    }

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

        if ((strpos($name, 'segment') !== false)
            && preg_match('/^segments?(\d+)$/', $name, $matches)
        ) {
            $index = (int)$matches[1] - 1; // Convert to zero-based index
            $index = abs($index);
            $index = $index <= 0 ? 0 : $index;

            $ctx = [];
            $ctx['index'] = $index;
            $segments = $this->segments();
            $val = empty($segments[$index]) ? '': $segments[$index];
            $val = Dj_App_Hooks::applyFilter( 'app.core.request.get_segment', $val, $ctx );
            return $val;
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
     * Sends JSON and exits
     * @param array $struct
     * @param array $params
     * @return void
     */
    public function json($struct = [], $params = []) {
        $default_struct = [
            'status' => false,
            'msg' => '',
            'data' => [],
        ];

        $struct = array_replace_recursive($default_struct, (array) $struct);
        $struct['status'] = (bool) $struct['status'];

        // Different header is required for ajax and jsonp
        // see https://gist.github.com/cowboy/1200708
        $callback = !empty($_REQUEST['callback']) ? preg_replace('/[^\w\$]/si', '', $_REQUEST['callback']) : false;

        if (!headers_sent()) {
            $this->sendCORS();
            $app_env = Dj_App_Config::cfg('env'); // env specific conf?

            // starts with prod
            if (empty($app_env) || strcasecmp($app_env, 'live') == 0 || (strpos($app_env, 'prod') == 0)) { // debugger doesn't start when it's app/js content type
                header( 'Content-Type: ' . ( $callback ? 'application/javascript' : 'application/json' ) . ';charset=UTF-8' );
            }
        }

        $struct['status'] = !empty($struct['status']); // force bool

        if (empty($struct['code'])) {
            unset($struct['code']);
        }

        echo ($callback ? $callback . '(' : '') . Dj_App_String_Util::jsonEncode($struct) . ($callback ? ')' : '');
        exit;
    }

    /**
     * Sends secure CORS headers for cross-origin requests
     * 
     * @see https://developer.mozilla.org/en/HTTP_access_control
     * @see https://fetch.spec.whatwg.org/#http-cors-protocol
     */
    public function sendCORS() 
    {
        if (headers_sent()) {
            return;
        }

        $ctx = [];
        $headers = [];

        // Allow from specific origin
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $headers = [
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Headers' => 'X-Requested-With, Content-Type, Authorization',
                'Access-Control-Max-Age' => '86400', // cache for 1 day
            ];

            $http_origin = $_SERVER['HTTP_ORIGIN'];
            $http_origin = strip_tags($http_origin);
            $http_origin = trim($http_origin);
            $http_origin = Dj_App_Hooks::applyFilter('app.request.cors.origin', $http_origin);
            $host = $this->getSiteHost();

            $ctx['host'] = $host;
            $ctx['http_origin'] = $http_origin;

            $allow_origin = !empty($http_origin) && substr($http_origin, -strlen($host)) == $host;
            $allow_origin = Dj_App_Hooks::applyFilter('app.request.cors.allow_origin', $allow_origin, $ctx);

            // check if the origin ends with the host, then allow it
            if ($allow_origin) {
                $headers['Access-Control-Allow-Origin'] = $http_origin;
            }
        }

        $is_options_request = $this->isOptionsMethod();

        // Access-Control headers are received during OPTIONS requests
        if ($is_options_request) {
            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                $allow_methods = ['GET', 'POST', 'OPTIONS', 'HEAD'];
                $allow_methods = Dj_App_Hooks::applyFilter('app.request.cors.allow_methods', $allow_methods, $ctx);
                $ctx['allow_methods'] = $allow_methods;
                $allow_methods_csv = join(', ', $allow_methods);
                $headers['Access-Control-Allow-Methods'] = $allow_methods_csv;
            }

            if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
                $allow_headers = Dj_App_Hooks::applyFilter('app.request.cors.allow_headers', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'], $ctx );
                $headers['Access-Control-Allow-Headers'] = $allow_headers;
            }
        }

        // Allow plugins to modify headers
        $headers = Dj_App_Hooks::applyFilter('app.request.cors.headers', $headers);

        // Send headers
        foreach ($headers as $name => $value) {
            header("$name: $value");
        }

        // Exit for OPTIONS requests, no need to render the whole page. the browser just tests things.
        if ($is_options_request) {
            exit(0);
        }
    }

    /**
     * Dj_App_Request::addQueryParam();
     * @param string|array $key
     * @param string $val
     * @param string $url
     * @return string
     */
    public static function addQueryParam($key, $val, $url = '') {
        $keys = [];

        if (is_array($key)) { // the user just passed the array with key/value pairs
            if (empty($url) && !empty($val)) {
                $url = $val;
            }
            $keys = $key;
        } elseif (is_scalar($key)) {
            $keys = [ $key => $val ];
        } else {
            return '';
        }

        if (empty($url)) {
            $url = static::getInstance()->getRequestUrl();
        }

        $url = strip_tags( $url );
        $url = trim( $url );
        $url = urldecode( $url );

        // Remove any existing keys from the url
        foreach ($keys as $query_key => $query_val) {
            if (strpos($url, $query_key) === false) {
                continue;
            }

            $url = preg_replace('#([?&])' . preg_quote($query_key, '#') . '=[^&]*#si', '${1}', $url);
            $url = trim($url, '?&');
        }

        $sep = strpos($url, '?') === false ? '?' : '&';
        $url .= $sep . http_build_query( $keys );

        // if there are fields with empty values e.g. ?debug we'll remove the = sign
        $url = str_replace('=&', '&', $url);
        $url = trim($url, '=?&');

        // in some weird cases we end up with ?& so let's remove the &
        if (strpos($url, '?&') !== false) {
            $url = str_replace('?&', '?', $url);
        }

        return $url;
    }

    /**
     * Dj_App_Request::removeQueryParam();
     * @param string|array $key
     * @param string $url
     * @return string
     */
    public static function removeQueryParam($key, $url = '') {
        $keys = (array) $key;

        if (empty($url) && !empty($_SERVER['REQUEST_URI'])) {
            $url = $_SERVER['REQUEST_URI'];
        }

        $url = strip_tags( $url );
        $url = trim( $url );
        $url = urldecode( $url );

        // Remove any existing keys from the url
        foreach ($keys as $query_key) {
            if (strpos($url, $query_key) === false) {
                continue;
            }

            $url = preg_replace('#([?&])' . preg_quote($query_key, '#') . '(=[^&]*|[^&]*)#si', '${1}', $url);
            $url = trim($url, '?&');
        }

        return $url;
    }

    /**
     * @var array
     */
    private $headers = [];
    
    /**
     * @var array
     */
    private $default_headers = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-XSS-Protection' => '1; mode=block',
        'Content-Type' => 'text/html; charset=UTF-8',
    ];

    /**
     * @return string
     */
    public function getRequestProtocol() {
        if (!empty($_SERVER['SERVER_PROTOCOL'])) {
            return $_SERVER['SERVER_PROTOCOL'];
        }

        return 'HTTP/1.1';
    }

    /**
     * Serves 404 and exits
     * @param void
     * @return void
     */
    public function servePageNotFound() {
        header( $this->getRequestProtocol() . ' 404 Not Found', true, 404 );
        die( "404 Not Found" );
    }

    /**
     * @param string $str
     * @return string
     */
    public function encode($str) {
        $str = Djebel_App_HTML::encodeEntities($str);
        return $str;
    }

    /**
     * @param string $str
     * @return string
     */
    public function decode($str) {
        $str = Djebel_App_HTML::decodeEntities($str);
        return $str;
    }

    /**
     * Checks if current request is OPTIONS
     * Uses early returns and first char check for efficiency
     * Common in CORS preflight requests
     * 
     * @return bool True if OPTIONS request
     */
    public function isOptionsMethod()
    {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $req_method = $_SERVER['REQUEST_METHOD'];
        $req_char = substr($req_method, 0, 1);

        if (strcasecmp($req_char, 'o') != 0) {
            return false;
        }

        return strcasecmp($req_method, 'options') == 0;
    }

    /**
     * Checks if current request is HEAD method
     * Uses early returns and first char check for efficiency
     * 
     * @return bool True if HEAD request
     */
    public function isHeadMethod()
    {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $req_method = $_SERVER['REQUEST_METHOD'];
        $req_char = substr($req_method, 0, 1);

        if (strcasecmp($req_char, 'h') != 0) {
            return false;
        }

        return strcasecmp($req_method, 'head') == 0;
    }

    /**
     * Gets the site URL from options or server name
     * Includes protocol and port, removes www
     * 
     * @return string Clean site URL
     */
    public function getSiteUrl()
    {
        static $site_url = null;

        if (!is_null($site_url)) {
            return $site_url;
        }

        $site_url = Dj_App_Hooks::applyFilter('app.core.site.site_url', '', []);

        if (!empty($site_url)) {
            return $site_url;
        }

        $options_obj = Dj_App_Options::getInstance();
        $site_url = $options_obj->site->site_url;
        $site_url = Dj_App_Hooks::applyFilter('app.core.options.site_url', $site_url, []);

        // If we have a complete URL from options, use it
        if (!empty($site_url)) {
            return $site_url;
        }

        // Otherwise, build URL from hostname + protocol + port
        $hostname = '';
        $host_headers = [
            'HTTP_HOST',              // Standard HTTP host header (most reliable)
            'HTTP_X_FORWARDED_HOST',  // Proxy forwarded hostname (may include internal ports)
            'SERVER_NAME',            // Server hostname (fallback)
        ];
        
        foreach ($host_headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            
            $hostname = $_SERVER[$header];
            break;
        }

        if (empty($hostname)) {
            return '';
        }

        // Convert hostname to lowercase
        $hostname = strtolower($hostname);

        // Remove www prefix if present
        $www_pos = strpos($hostname, 'www.');

        if ($www_pos === 0) {
            $hostname = substr($hostname, 4);
        }

        // Detect HTTPS - check proxy headers first
        $is_https = false;
        
        $https_checks = [
            ['header' => 'HTTP_X_FORWARDED_PROTO', 'value' => 'https'],
            ['header' => 'HTTP_X_FORWARDED_SSL', 'value' => 'on'],
            ['header' => 'HTTP_X_ARR_SSL', 'value' => null], // Azure - just needs to exist
            ['header' => 'HTTP_X_FORWARDED_PORT', 'value' => '443'],
            ['header' => 'HTTPS', 'value' => 'on'],
            ['header' => 'SERVER_PORT', 'value' => '443'],
        ];
        
        foreach ($https_checks as $check) {
            if (empty($_SERVER[$check['header']])) {
                continue;
            }
            
            if (is_null($check['value'])) { // Header just needs to exist
                $is_https = true;
                break;
            }

            if (strcasecmp($_SERVER[$check['header']], $check['value']) == 0) {
                $is_https = true;
                break;
            }
        }
        
        // Special case for Cloudflare CF_VISITOR JSON
        if (!$is_https && !empty($_SERVER['HTTP_CF_VISITOR'])) {
            $cf_visitor = json_decode($_SERVER['HTTP_CF_VISITOR'], true);
            
            if (!empty($cf_visitor['scheme']) && $cf_visitor['scheme'] === 'https') {
                $is_https = true;
            }
        }

        // Add protocol
        $protocol = $is_https ? 'https://' : 'http://';

        // Port detection - use colon in host headers as hint
        $port = '';
        $detected_port = '';
        
        // Check if HTTP_HOST or HTTP_X_FORWARDED_HOST contains port (colon as hint)
        $host_with_port_headers = ['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'];
        
        foreach ($host_with_port_headers as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }
            
            // Skip if host doesn't contain colon (no port)
            if (strpos($_SERVER[$header], ':') === false) {
                continue;
            }
            
            $host_parts = explode(':', $_SERVER[$header]);
            
            if (count($host_parts) === 2 && is_numeric($host_parts[1])) {
                $hostname = $host_parts[0]; // Clean hostname without port
                $detected_port = $host_parts[1];
                break;
            }
        }
        
        // If no colon found in host headers, check explicit port headers as fallback
        if (empty($detected_port)) {
            $port_headers = ['HTTP_X_FORWARDED_PORT',];
            
            foreach ($port_headers as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                
                $detected_port = $_SERVER[$header];
                break;
            }
        }

        // server may be behind a proxy and we don't want to append the internal port.
        if (empty($detected_port) && !empty($_SERVER['SERVER_PORT']) && !empty($options_obj->site_use_port)) {
            $detected_port = $_SERVER['SERVER_PORT'];
        }

        if (!empty($detected_port)
            && $detected_port != '80' 
            && $detected_port != '443'
        ) {
            $port = ':' . $detected_port;
        }

        $site_url = $protocol . $hostname . $port;

        // the site url should contain the web path too
        $req_obj = Dj_App_Request::getInstance();
        $web_path = $req_obj->getWebPath();

        if (strlen($web_path)) {
            $site_url .= $web_path;
        }

        return $site_url;
    }

    /**
     * Gets the content directory URL (site URL + web path + content dir)
     * @return string Content directory URL
     */
    public function getContentDirUrl()
    {
        static $content_dir_url = null;

        if (!is_null($content_dir_url)) {
            return $content_dir_url;
        }

        $site_url = Dj_App_Util::removeSlash($this->getSiteUrl());
        $content_dir_name = Dj_App_Util::getContentDirName();

        $url_parts = [$site_url];
        $url_parts[] = $content_dir_name;
        $content_dir_url = implode('/', $url_parts);

        return $content_dir_url;
    }

    /**
     * Adds a header to the collection (skips if already exists)
     * 
     * @param string $name Header name
     * @param string $value Header value
     * @return void
     */
    public function addHeader($name, $value)
    {
        if (empty($name)) {
            return;
        }

        $name = trim($name);
        $value = trim($value);
        
        // Check if header already exists (case insensitive)
        foreach ($this->headers as $header_name => $header_value) {
            if (strcasecmp($header_name, $name) == 0) {
                return; // Skip if already exists
            }
        }
        
        $this->headers[$name] = $value;
    }

    /**
     * Removes a header from the collection (case insensitive)
     * 
     * @param string $name Header name to remove
     * @return void
     */
    public function removeHeader($name)
    {
        if (empty($name)) {
            return;
        }

        $name = trim($name);
        
        // Find header with case insensitive search
        foreach ($this->headers as $header_name => $header_value) {
            if (strcasecmp($header_name, $name) == 0) {
                unset($this->headers[$header_name]);
                break;
            }
        }
    }

    /**
     * Alias for removeHeader
     * 
     * @param string $name Header name to delete
     * @return void
     */
    public function deleteHeader($name)
    {
        $this->removeHeader($name);
    }

    /**
     * Clears all custom headers from the collection
     * 
     * @return void
     */
    public function clearHeaders()
    {
        $this->headers = [];
    }

    /**
     * Sets/replaces a header in the collection (case insensitive)
     * 
     * @param string $name Header name
     * @param string $value Header value
     * @return void
     */
    public function setHeader($name, $value)
    {
        if (empty($name)) {
            return;
        }

        $name = trim($name);
        $value = trim($value);
        
        // Find existing header with case insensitive search
        $existing_key = null;

        foreach ($this->headers as $header_name => $header_value) {
            if (strcasecmp($header_name, $name) == 0) {
                $existing_key = $header_name;
                break;
            }
        }
        
        // Remove existing header if found
        if (!is_null($existing_key)) {
            unset($this->headers[$existing_key]);
        }

        // Add the new header
        $this->headers[$name] = $value;
    }

    /**
     * Alias for setHeader
     * 
     * @param string $name Header name
     * @param string $value Header value
     * @return void
     */
    public function replaceHeader($name, $value)
    {
        $this->setHeader($name, $value);
    }

    /**
     * Gets all headers with filter support
     * 
     * @return array Array of headers
     */
    public function getHeaders()
    {
        // Start with default headers
        $headers = $this->getDefaultHeaders();

        // Merge with custom headers (custom headers take precedence)
        $headers = array_merge($headers, $this->headers);
        $headers = Dj_App_Hooks::applyFilter('app.request.headers', $headers);
        
        return $headers;
    }

    private $response_status_code = 200;

    /**
     * Sets the HTTP response code
     * 
     * @param int $code HTTP response code
     * @return void
     */
    public function setResponseCode($code)
    {
        $code = intval($code);

        if ($code > 0 && $code !== 200) {
            $this->response_status_code = $code;
        }
    }

    /**
     * Gets the HTTP response code
     * 
     * @return int HTTP response code (defaults to 200)
     */
    public function getResponseCode()
    {
        $status_code = $this->response_status_code;
        return $status_code;
    }

    /**
     * Outputs all headers to the browser
     * 
     * @return void
     */
    public function outputHeaders()
    {
        if (headers_sent()) {
            return;
        }

        $response_code = $this->getResponseCode();

        if ($response_code > 0) {
            http_response_code($response_code);
        }

        $headers = $this->getHeaders();
        $replace_headers = true;

        foreach ($headers as $name => $value) {
            header("$name: $value", $replace_headers);
        }

        $this->clearHeaders(); // just in case
        $this->sendCORS();
    }

    /**
     * Gets the site host from site URL. Removes protocol, port and trailing slashes.
     * @return string Clean site host
     */
    public function getSiteHost($site_url = '')
    {
        static $site_host = null;

        if (!is_null($site_host)) {
            return $site_host;
        }

        $site_url = empty($site_url) ? $this->getSiteUrl() : $site_url;

        if (empty($site_url)) {
            return '';
        }

        $site_host = parse_url($site_url, PHP_URL_HOST);

        if (empty($site_host)) {
            return '';
        }

        return $site_host;
    }

    /**
     * @return array
     */
    public function getDefaultHeaders(): array
    {
        return $this->default_headers;
    }

    /**
     * @param array $default_headers
     */
    public function setDefaultHeaders(array $default_headers): void
    {
        $this->default_headers = $default_headers;
    }
}
