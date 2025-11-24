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
        return !is_null($this->__get($key));
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
    // checks the app.ini if it has 'theme' or 'theme_id' in [site], [theme] sections
     * @return string
     */
    public function getCurrentTheme()
    {
        $options_obj = Dj_App_Options::getInstance();
        $current_theme = $options_obj->get('theme.theme,theme.theme_id,site.theme_id,site.theme', 'default');

        $current_theme = $this->formatId($current_theme);
        $current_theme = Dj_App_Hooks::applyFilter( 'app.themes.current_theme', $current_theme );

        return $current_theme;
    }

    /**
     * @return void
     */
    public function loadTheme($inp_params = [])
    {
        $req_obj = Dj_App_Request::getInstance();

        try {
            $page_obj = Dj_App_Page::getInstance();
            $options_obj = Dj_App_Options::getInstance();

            $ctx = [];
            $ctx['page'] = $page_obj->page;
            $ctx['full_page'] = $page_obj->full_page;

            $site_section = Dj_App_Options::getInstance()->site;
            $current_theme = empty($inp_params['theme']) ? $this->getCurrentTheme() : $inp_params['theme'];
            $current_theme = $this->formatId($current_theme);
            $this->current_theme = $current_theme;
            $theme_load_main_file = !isset($site_section['theme_load_main_file']) || !empty($site_section['theme_load_main_file']) ? true : false;

            $themes_dir = $this->getThemesDir();
            $current_theme_dir = $themes_dir . '/' . $current_theme;
            $this->current_theme_dir = $current_theme_dir;
            $current_theme_dir = Dj_App_Hooks::applyFilter('app.themes.current_theme_dir', $current_theme_dir, $ctx);

            $this->current_theme_dir = $current_theme_dir;

            // Build full URL: site_url/dj-content/themes/theme-name
            $content_dir_url = Dj_App_Util::getContentDirUrl();
            $this->current_theme_url = $content_dir_url . '/themes/' . $current_theme;
            $default_theme_file = $current_theme_dir . '/index.php';

            // should we load theme's functions file?
            $load_theme_func_file = Dj_App_Util::isEnabled($options_obj->get('site.theme_load_functions'));
            $load_theme_func_file = Dj_App_Config::cfg('app.core.theme.load_theme_functions', $load_theme_func_file);
            $load_theme_func_file = Dj_App_Hooks::applyFilter('app.core.theme.load_theme_functions', $load_theme_func_file, $ctx);

            // we're loading it early so that the theme can schedule its hooks
            if ($load_theme_func_file) {
                $theme_func_file = $current_theme_dir . '/functions.php';
                $theme_func_file = Dj_App_Hooks::applyFilter('app.core.theme.functions_file', $theme_func_file, $ctx);

                if (file_exists($theme_func_file)) {
                    Dj_App_Hooks::doAction('app.core.theme.functions_before_load');
                    include_once $theme_func_file;
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
            }

            $footer_buff = ob_get_clean();
            $header_buff = Dj_App_Hooks::applyFilter('app.page.footer_buffer', $footer_buff);
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
            } else {
                if (!file_exists($default_theme_file)) {
                    Dj_App_Util::die("Theme file not found: [theme:$current_theme]", ['code' => 404,]);
                }

                ob_start();
                include_once $default_theme_file;
                $full_page_content = ob_get_clean();
            }

            $full_page_content = Dj_App_Hooks::applyFilter('app.page.full_content', $full_page_content);
            $full_page_content = trim($full_page_content);

            $req_obj->setContent($full_page_content);
        } finally {
            Dj_App_Hooks::doAction( 'app.core.theme.theme_loaded', $ctx );
        }
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
        $options_obj = Dj_App_Options::getInstance();

        $pages_dir = $current_theme_dir . '/pages';
        $pages_dir = Dj_App_Hooks::applyFilter('app.themes.current_theme.pages_dir', $pages_dir, $ctx);

        $ctx['pages_dir'] = $pages_dir;
        $ctx['theme_dir'] = $current_theme_dir;
        $single_page = !empty($options_obj->get('theme.single_page')) || !is_dir($pages_dir);
        $single_page = Dj_App_Hooks::applyFilter('app.themes.single_page', $single_page, $ctx);

        // the main theme file handles the content
        if ($single_page) {
            return;
        }

        $page = $page_obj->full_page;
        $page = empty($page) ? '' : $page; // this can be product or product/prod1

        $site_section = Dj_App_Options::getInstance()->site;
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

        $page_file_candiates = [
            $file = $pages_dir . "/$page_fmt.php",
        ];

        $file = '';
        $page_file_candiates = Dj_App_Hooks::applyFilter('app.themes.current_theme_page_file_candidates', $page_file_candiates, $ctx);

        // Remove duplicate candidates while preserving order
        $page_file_candiates = array_unique($page_file_candiates);

        foreach ($page_file_candiates as $loop_file) {
            // If path doesn't start with /, treat as relative and prepend pages_dir
            if (Dj_App_String_Util::getFirstChar($loop_file) !== '/') {
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
            $page_not_found_file = $pages_dir . "/404.php";
            $page_not_found_file = Dj_App_Hooks::applyFilter( 'app.themes.page_not_found_file', $page_not_found_file, $ctx );

            if (is_dir($dir)) {
                $page = empty($site_section['front_page']) ? $default_page : $site_section['front_page'];
                $page_fmt = $page_obj->formatFullPageSlug($page);
                $file = $dir . "/$page_fmt.php";

                if (!file_exists($file)) {
                    $file = $page_not_found_file;
                }
            }

            if (!file_exists($file)) {
                // Allow plugins to intercept and provide content for missing pages
                $not_found_ctx = $ctx;
                $not_found_ctx['page'] = $page;
                $not_found_ctx['page_fmt'] = $page_fmt;
                $not_found_ctx['candidates_tried'] = $page_file_candiates;

                $file = Dj_App_Hooks::applyFilter('app.core.theme.page_file_not_found', '', $not_found_ctx);

                if (empty($file) || !file_exists($file)) {
                    Dj_App_Util::die( "The requested page can not be found", "Page not found", [ 'code' => 404, 'file' => $file, ] );
                }
            }
        }

        // Let's make the loaded content from that specific page filterable
        $local_ctx = $ctx;
        $local_ctx['page'] = $page;
        $local_ctx['file'] = $file;

        ob_start();
        include_once $file;
        $buff = ob_get_clean();
        $buff = Dj_App_Hooks::applyFilter( 'app.page.content', $buff, $local_ctx );

        echo $buff;
    }

    /**
     * Dj_App_Themes::getRelPath
     * @param $path
     * @return string
     */
    public function getRelPath($path)
    {
        $themes_dir = $this->getThemesDir();
        $rel_path = $path;
        $rel_path = str_replace($themes_dir, '', $rel_path);
        return $rel_path;
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
