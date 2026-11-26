<?php

/**
 *
 */
class Dj_App_Themes {
    private $data = [];

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
        $val = $this->__get($key);
        $is_set = !is_null($val);

        return $is_set;
    }

    /**
     * @return string
     */
    public function getThemesDir()
    {
        $dir = Dj_App_Util::getContentDir() . '/themes';
        $dir = Dj_App_Hooks::applyFilter( 'app.themes.themes_dir', $dir );
        return $dir;
    }

    /**
     * Get current theme based on config and filters
     * Checks the site config for 'theme' or 'theme_id' in its [site] and [theme] sections
     *
     * @param array $ctx Context with page info for conditional theme switching
     * @return string
     */
    public function getCurrentTheme($ctx = [])
    {
        $options_obj = Dj_App_Options::getInstance();
        $current_theme = $options_obj->get('theme.theme,theme.theme_id,site.theme_id,site.theme');
        $current_theme = $this->formatId($current_theme);

        // Build context for filter if not provided
        if (empty($ctx)) {
            $page_obj = Dj_App_Page::getInstance();
            $ctx = [
                'page' => $page_obj->page,
                'full_page' => $page_obj->full_page,
            ];
        }

        $current_theme = Dj_App_Hooks::applyFilter('app.themes.current_theme', $current_theme, $ctx);

        return $current_theme;
    }

    /**
     * Loads the active theme and hands the finished page to the request.
     * @param array $inp_params Optional. 'theme' forces a theme id instead of resolving one.
     * @return Dj_App_Result what the load settled on, under `theme_info`: theme, theme_dir,
     *                       theme_url, functions_loaded, header_loaded, footer_loaded and
     *                       render_mode (the header/footer sandwich, the theme's main file, or
     *                       error_page when djebel's own page answered the page's error).
     */
    public function loadTheme($inp_params = [])
    {
        $req_obj = Dj_App_Request::getInstance();
        $res_obj = new Dj_App_Result();

        // Declared with every key up front, so a run that dies partway still describes itself
        // and no branch has to remember to add its own key.
        $theme_info = [
            'theme' => '',
            'theme_dir' => '',
            'theme_url' => '',
            'functions_loaded' => false,
            'header_loaded' => false,
            'footer_loaded' => false,
            'render_mode' => '',
        ];

        try {
            $page_obj = Dj_App_Page::getInstance();
            $options_obj = Dj_App_Options::getInstance();

            $ctx = [];
            $ctx['page'] = $page_obj->page;
            $ctx['full_page'] = $page_obj->full_page;

            $site_section = $options_obj->site;
            $current_theme = empty($inp_params['theme']) ? $this->getCurrentTheme($ctx) : $inp_params['theme'];
            $current_theme = $this->formatId($current_theme);
            $this->current_theme = $current_theme;
            $theme_info['theme'] = $current_theme;
            $theme_load_main_file = true;

            if (isset($site_section['theme_load_main_file'])) {
                $theme_load_main_file = !empty($site_section['theme_load_main_file']);
            }

            $themes_dir = $this->getThemesDir();
            $current_theme_dir = $themes_dir . '/' . $current_theme;
            $this->current_theme_dir = $current_theme_dir;
            $current_theme_dir = Dj_App_Hooks::applyFilter('app.themes.current_theme_dir', $current_theme_dir, $ctx);

            $this->current_theme_dir = $current_theme_dir;
            $theme_info['theme_dir'] = $current_theme_dir;

            // Build full URL: site_url/dj-content/themes/theme-name
            $content_dir_url = Dj_App_Util::getContentDirUrl();
            $this->current_theme_url = $content_dir_url . '/themes/' . $current_theme;
            $theme_info['theme_url'] = $this->current_theme_url;
            $default_theme_file = $current_theme_dir . '/index.php';

            // should we load theme's functions file?
            $load_theme_func_file = $options_obj->isEnabled('site.theme_load_functions');
            $load_theme_func_file = Dj_App_Config::cfg('app.core.theme.load_theme_functions', $load_theme_func_file);
            $load_theme_func_file = Dj_App_Hooks::applyFilter('app.core.theme.load_theme_functions', $load_theme_func_file, $ctx);

            // we're loading it early so that the theme can schedule its hooks
            if ($load_theme_func_file) {
                $theme_func_file = $current_theme_dir . '/functions.php';
                $theme_func_file = Dj_App_Hooks::applyFilter('app.core.theme.functions_file', $theme_func_file, $ctx);

                if (file_exists($theme_func_file)) {
                    Dj_App_Hooks::doAction('app.core.theme.functions_before_load');
                    include_once $theme_func_file;
                    $theme_info['functions_loaded'] = true;
                    Dj_App_Hooks::doAction('app.core.theme.functions_loaded');
                }
            }

            Dj_App_Hooks::doAction('app.core.theme.setup', $ctx);

            // Header
            ob_start();
            $theme_header_file = $current_theme_dir . '/header.php';
            $load_theme_header_file = Dj_App_Config::cfg('app.core.theme.load_theme_header', $theme_load_main_file ? false : true);
            $load_theme_header_file = Dj_App_Hooks::applyFilter('app.core.theme.load_theme_header', $load_theme_header_file);

            $header_loaded = false;

            if ($load_theme_header_file && file_exists($theme_header_file)) {
                include_once $theme_header_file;
                $header_loaded = true;
                $theme_info['header_loaded'] = true;
            }

            $header_buff = ob_get_clean();
            $header_buff = Dj_App_Hooks::applyFilter('app.page.header_buffer', $header_buff);
            // Header

            // Footer
            ob_start();
            $footer_loaded = false;
            $theme_footer_file = $current_theme_dir . '/footer.php';
            $load_theme_footer_file = Dj_App_Config::cfg('app.core.theme.load_theme_footer', $theme_load_main_file ? false : true);
            $load_theme_footer_file = Dj_App_Hooks::applyFilter('app.core.theme.load_theme_footer', $load_theme_footer_file);

            if ($load_theme_footer_file && file_exists($theme_footer_file)) {
                include_once $theme_footer_file;
                $footer_loaded = true;
                $theme_info['footer_loaded'] = true;
            }

            $footer_buff = ob_get_clean();
            $footer_buff = Dj_App_Hooks::applyFilter('app.page.footer_buffer', $footer_buff);
            // Footer

            $ctx['theme'] = $current_theme;
            $ctx['theme_dir'] = $current_theme_dir;
            $ctx['header_loaded'] = $header_loaded;
            $ctx['footer_loaded'] = $footer_loaded;

            $full_page_content = '';

            if (!empty($header_buff) && !empty($footer_buff)) {
                $page_content_buff = '';

                // check if the render content hook was called. The theme could just define the two blocks
                // and we'll do a content sandwich :)
                if (!Dj_App_Hooks::hasRun('app.page.content.render')) {
                    $page_content_buff = Dj_App_Hooks::captureHookOutput('app.page.content.render', $ctx);
                }

                $page_content_buff = Dj_App_Hooks::applyFilter('app.page.content', $page_content_buff, $ctx);
                $full_page_content = $header_buff . $page_content_buff . $footer_buff;
                $theme_info['render_mode'] = 'sandwich';
            } else {
                if (!file_exists($default_theme_file)) {
                    Dj_App_Util::die("Theme file not found: [theme:$current_theme]", ['code' => 404,]);
                }

                ob_start();
                include_once $default_theme_file;
                $full_page_content = ob_get_clean();
                $theme_info['render_mode'] = 'main_file';
            }

            $full_page_content = Dj_App_Hooks::applyFilter('app.page.full_content', $full_page_content);
            $error = $page_obj->getError();

            // Nothing took the error: no plugin content and no theme page for it. The theme's
            // normal page for this address must not go out under an error code, so djebel's own
            // page answers instead, and the theme's render is thrown away. Checked after the filter,
            // since a theme's [djebel_page_content] renders the page there: before it, a missing
            // page is not known yet.
            if (!empty($error) && !$page_obj->hasContent()) {
                $error_msg_esc = Dj_App_HTML::encodeEntities($error['msg']);

                $render_options = [
                    'status_code' => $error['code'],
                    'return' => 1,
                ];

                $full_page_content = Dj_App_HTML::renderPage($error_msg_esc, $error['title'], $render_options);
                $theme_info['render_mode'] = 'error_page';
            }

            $full_page_content = Dj_App_String_Util::trim($full_page_content);

            $req_obj->setContent($full_page_content);
            $res_obj->status(true);
        } finally {
            $res_obj->theme_info = $theme_info;
            Dj_App_Hooks::doAction( 'app.core.theme.theme_loaded', $ctx );
        }

        return $res_obj;
    }

    public function installHooks()
    {
        Dj_App_Hooks::addAction( 'app.page.content.render', [ $this, 'renderPageContent' ] );
        Dj_App_Hooks::addFilter( 'app.page.full_content', [ $this, 'autoCorrectAssetLinks' ] );
    }

    public function autoCorrectAssetLinks($buff, $ctx = [])
    {
        $params = [];
        $params['theme_dir'] = $this->current_theme_dir;
        $params['theme_url'] = $this->current_theme_url;
        $buff =  Dj_App_Util::replaceMagicVars($buff, $params);

        return $buff;
    }

    /**
     * @param array $ctx
     * @return void
     */
    public function renderPageContent($ctx = [])
    {
        $current_theme_dir = $this->current_theme_dir;

        if (empty($current_theme_dir)) {
            return;
        }

        $page_obj = Dj_App_Page::getInstance();

        // Check if content already loaded by plugin (e.g., site_content)
        $content = $page_obj->getContent();

        if (!empty($content)) {
            // Find template to wrap content
            $full_page = $page_obj->get('full_page');
            $page_fmt = $page_obj->formatFullPageSlug($full_page);

            $template_ctx = [
                'theme_dir' => $current_theme_dir,
            ];

            $template_result = $this->findTemplate($page_fmt, $template_ctx);

            if (!empty($template_result['file'])) {
                include $template_result['file'];
            } else {
                echo $content;
            }

            return;
        }

        $options_obj = Dj_App_Options::getInstance();

        $pages_dir = $current_theme_dir . '/pages';
        $pages_dir = Dj_App_Hooks::applyFilter('app.themes.current_theme.pages_dir', $pages_dir, $ctx);

        $ctx['pages_dir'] = $pages_dir;
        $ctx['theme_dir'] = $current_theme_dir;

        $error = $page_obj->getError();

        // A page marked as an error shows only the theme's page for that error, never the page
        // this address would normally get: a blog template for a missing post would answer
        // "found" with an empty listing.
        if (!empty($error)) {
            $this->renderErrorPageContent($ctx);

            return;
        }

        $single_page = !empty($options_obj->get('theme.single_page')) || !is_dir($pages_dir);
        $single_page = Dj_App_Hooks::applyFilter('app.themes.single_page', $single_page, $ctx);

        // the main theme file handles the content
        if ($single_page) {
            return;
        }

        $page = $page_obj->full_page;
        $page = empty($page) ? '' : $page; // this can be product or product/prod1

        $site_section = $options_obj->site;
        $default_page = 'home';

        if (empty($page)) {
            $page = $options_obj->get('site.front_page');

            if (empty($page)) {
                $page = $default_page;
            }
        }

        $page_fmt = $page;
        $page_fmt = $page_obj->formatFullPageSlug($page_fmt);
        $page_fmt = Dj_App_Hooks::applyFilter('app.themes.current_page', $page_fmt, $ctx);
        $page_fmt = $page_obj->formatFullPageSlug($page_fmt); // jic

        // @todo allow filterable $pages_dir so we can use for multi-lang
        $page_file_candiates = [
            $file = $pages_dir . "/$page_fmt.php",
        ];

        $file = '';
        $page_file_candiates = Dj_App_Hooks::applyFilter('app.themes.current_theme_page_file_candidates', $page_file_candiates, $ctx);

        // Remove duplicate candidates while preserving order
        $page_file_candiates = array_unique($page_file_candiates);

        foreach ($page_file_candiates as $loop_file) {
            // If path doesn't start with /, treat as relative and prepend pages_dir
            if (Dj_App_String_Util::getFirstChar($loop_file) != '/') {
                $loop_file = $pages_dir . '/' . $loop_file;
            }

            $loop_file = Dj_App_Hooks::applyFilter('app.themes.current_theme.page_content_file', $loop_file, $ctx);

            if (empty($loop_file)) {
                continue;
            }

            if (file_exists($loop_file)) {
                $file = $loop_file;
                break;
            }
        }

        if (empty($file) || !file_exists($file)) {
            // test if this is a dir so access e.g. /bg/
            $dir = $current_theme_dir . "/pages/$page_fmt";

            if (is_dir($dir)) {
                $page = empty($site_section['front_page']) ? $default_page : $site_section['front_page'];
                $page_fmt = $page_obj->formatFullPageSlug($page);
                $file = $dir . "/$page_fmt.php";
            }

            if (!file_exists($file)) {
                // Fallback to templates system (traverses path hierarchy). The theme's index.php
                // counts only for the home page: it is already rendering, so including it again
                // shows an empty page that answers "found" for any address.
                $template_ctx = $ctx;
                $template_ctx['index_fallback'] = $page_obj->isHomePage();
                $template_result = $this->findTemplate($page_fmt, $template_ctx);

                if (!empty($template_result['file'])) {
                    $file = $template_result['file'];
                }
            }

            if (!file_exists($file)) {
                // Allow plugins to intercept and provide content for missing pages
                $not_found_ctx = $ctx;
                $not_found_ctx['page'] = $page;
                $not_found_ctx['page_fmt'] = $page_fmt;
                $not_found_ctx['candidates_tried'] = $page_file_candiates;

                $file = Dj_App_Hooks::applyFilter('app.core.theme.page_file_not_found', '', $not_found_ctx);

                // Marked, not ended here: the theme is mid-render, so ending the request now would
                // send its half-built page with djebel's page behind it.
                if (empty($file) || !file_exists($file)) {
                    $error_params = [
                        'code' => 404,
                    ];

                    $page_obj->setError($error_params);
                    $this->renderErrorPageContent($ctx);

                    return;
                }
            }
        }

        $local_ctx = $ctx;
        $local_ctx['page'] = $page;

        $buff = $this->renderPageFile($file, $local_ctx);

        echo $buff;
    }

    /**
     * Renders one page file and makes the result filterable: PHP runs, any other file is read as
     * it is, and app.page.content gets the result, so a plugin such as markdown can convert it.
     * @param string $file
     * @param array $ctx the render context; file and ext are added
     * @return string
     */
    public function renderPageFile($file, $ctx = [])
    {
        $ext = Dj_App_File_Util::getExt($file);

        $ctx['file'] = $file;
        $ctx['ext'] = $ext;

        if ($ext == 'php') {
            ob_start();
            include_once $file;
            $buff = ob_get_clean();
        } else {
            $buff = file_get_contents($file);

            // An unreadable file renders as an empty body, never as the filters' false.
            if ($buff === false) {
                $buff = '';
            }
        }

        $buff = Dj_App_Hooks::applyFilter('app.page.content', $buff, $ctx);

        return $buff;
    }

    /**
     * Renders the theme's own page for the page's error: `pages/{code}.php`, or the file the
     * app.themes.error_page_file filter names. It becomes the page content, which is how the theme
     * load knows to keep it. With no such file nothing is rendered, and the theme load answers
     * with djebel's own page instead.
     * @param array $ctx the render context; pages_dir is required
     * @return bool true when the theme had a page for the error
     */
    public function renderErrorPageContent($ctx = [])
    {
        if (empty($ctx['pages_dir'])) {
            return false;
        }

        $page_obj = Dj_App_Page::getInstance();
        $error = $page_obj->getError();

        if (empty($error)) {
            return false;
        }

        $error_ctx = $ctx;
        $error_ctx['code'] = $error['code'];

        $error_page_file = $ctx['pages_dir'] . '/' . $error['code'] . '.php';
        $error_page_file = Dj_App_Hooks::applyFilter('app.themes.error_page_file', $error_page_file, $error_ctx);

        if (empty($error_page_file) || !file_exists($error_page_file)) {
            return false;
        }

        $buff = $this->renderPageFile($error_page_file, $error_ctx);
        $page_obj->setContent($buff);

        echo $buff;

        return true;
    }

    private $template_cache = [];

    /**
     * Find theme template for a page
     *
     * Priority order:
     *   1. {templates_dir}/{page_fmt}.php (exact match)
     *   2. {templates_dir}/{parent}.php (traverse up path hierarchy)
     *   3. {templates_dir}/home.php (for home page)
     *   4. {theme_dir}/index.php (fallback, unless $ctx['index_fallback'] turns it off)
     *
     * Example: page_fmt = "services/web-monitoring/wordpress"
     *   Checks: services/web-monitoring/wordpress.php
     *           services/web-monitoring.php
     *           services.php
     *           index.php (fallback)
     *
     * @param string $page_fmt Page path (e.g., "contact" or "docs/intro"), empty for home
     * @param array $ctx Context with theme_dir key, and index_fallback: false for a caller asking
     *                   whether the page has a template of its own (default true)
     * @return array ['file' => path, 'ext' => extension] or empty array if none found
     */
    public function findTemplate($page_fmt, $ctx = [])
    {
        $theme_dir = empty($ctx['theme_dir']) ? $this->current_theme_dir : $ctx['theme_dir'];

        if (empty($theme_dir)) {
            return [];
        }

        $use_index_fallback = true;

        if (isset($ctx['index_fallback'])) {
            $use_index_fallback = !empty($ctx['index_fallback']);
        }

        $fallback_key = $use_index_fallback ? 'index' : 'no_index';
        $cache_key = $theme_dir . '|' . $page_fmt . '|' . $fallback_key;

        if (isset($this->template_cache[$cache_key])) {
            return $this->template_cache[$cache_key];
        }

        $templates_dir = $theme_dir . '/templates';
        $theme_index = $theme_dir . '/index.php';

        // Cheap check first: if no templates dir, skip to fallback
        if (!is_dir($templates_dir)) {
            $result = [];

            if ($use_index_fallback && file_exists($theme_index)) {
                $result = [
                    'file' => $theme_index,
                    'ext' => 'php',
                ];
            }

            $this->template_cache[$cache_key] = $result;

            return $result;
        }

        // Home page: check templates/home.php
        $is_home = empty($page_fmt) || $page_fmt == '/';

        if ($is_home) {
            $home_template = $templates_dir . '/home.php';

            if (file_exists($home_template)) {
                $result = [
                    'file' => $home_template,
                    'ext' => 'php',
                ];

                $this->template_cache[$cache_key] = $result;

                return $result;
            }
        } else {
            // Traverse the slug hierarchy: exact match, then parent, grandparent, etc.
            // e.g. services/web-monitoring/wordpress -> services/web-monitoring -> services
            $check_slug = $page_fmt;

            while (!empty($check_slug) && $check_slug != '.') {
                $template_file = $templates_dir . '/' . $check_slug . '.php';

                if (file_exists($template_file)) {
                    $result = [
                        'file' => $template_file,
                        'ext' => 'php',
                    ];

                    $this->template_cache[$cache_key] = $result;

                    return $result;
                }

                $check_slug = dirname($check_slug);
            }
        }

        // Fallback to theme index.php (required file - always exists)
        $result = [];

        if ($use_index_fallback) {
            $result = [
                'file' => $theme_index,
                'ext' => 'php',
            ];
        }

        $this->template_cache[$cache_key] = $result;

        return $result;
    }

    /**
     * Dj_App_Themes::getRelFile
     * @param string $file
     * @return string
     */
    public function getRelFile($file)
    {
        $themes_dir = $this->getThemesDir();
        $rel_file = $file;
        $rel_file = str_replace($themes_dir, '', $rel_file);

        return $rel_file;
    }

    /**
     * Format an ID allowing hyphens using formatStringId from String_util
     * @param string $id
     * @return string
     */
    public function formatId($id) {
        return Dj_App_String_Util::formatStringId($id, Dj_App_String_Util::KEEP_DASH);
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
}
