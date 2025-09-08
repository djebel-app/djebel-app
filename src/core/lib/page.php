<?php

/**
 *
 */
class Dj_App_Page {
    /**
     * @var array
     */
    public function get($inp_name, $default = '')
    {
        $val = '';
        $name = $inp_name;
        $escape = false;
        $req_obj = Dj_App_Request::getInstance();
        $ctx = [];
        $ctx['name'] = $inp_name;

        if ($inp_name == 'full_page') {
            $all_segments = $req_obj->segments();
            $page = join('/', $all_segments);
            $page = empty($page) ? '' : $page;
            $page = Dj_App_Hooks::applyFilter( "app.core.request.page.get.$inp_name", $page, $ctx );
            return $page;
        } else if ($inp_name == 'page') {
            $all_segments = $req_obj->segments();
            $page = array_pop($all_segments);
            $page = empty($page) ? '' : $page;
            $page = Dj_App_Hooks::applyFilter( "app.core.request.page.get", $page, $ctx );
            return $page;
        }

        // if $name starts with esc or -_ then escape the value later and remove the prefix
        if (strpos($name, 'esc') === 0) {
            $escape = true;
            $name = preg_replace('/^[\-\_]*esc[\-\_]*(cape[\-\_]*)?/si', '', $name);
        }

        if (isset($this->data[$name])) {
            $val = $this->data[$name];
            $val = Dj_App_Hooks::applyFilter( "app.core.request.page.get.$name", $val, $ctx );

            if ($escape) {
                $val = Djebel_App_HTML::encodeEntities($val);
            }

            return $val;
        }

        $options_obj = Dj_App_Options::getInstance();
        $val = $options_obj->get($name);
        $val = Dj_App_Hooks::applyFilter( "app.core.request.page.get.$name", $val, $ctx );
        $val = empty($val) ? $default : $val;

        if ($escape) {
            $val = Djebel_App_HTML::encodeEntities($val);
        }

        return $val;
    }

    /**
     * @var array
     */
    public function set($key, $val, $extra_opts = [])
    {
        $ttl = isset($extra_opts['ttl']) ? $extra_opts['ttl'] : 24 * 60 * 60;
        $val = is_scalar($val) ? $val : serialize($val);
    }

    private $data = [];

    /**
     * Returns member data or a key from data. It's easier e.g. $data_res->output
     * @param string $name
     * @return mixed|null
     */
    public function __get($name) {
        $val = $this->get($name);
        return $val;
    }

    /**
     * Sets a property value with hook filtering support
     * @param string $key Property key
     * @param mixed $val Property value
     */
    public function __set($key, $val) {
        $ctx = [ 'key' => $key, 'val' => $val, ];
        $val = Dj_App_Hooks::applyFilter( 'app.page.filter.pre_set_property', $val, $ctx );
        $val = Dj_App_Hooks::applyFilter( 'app.page.filter.pre_set_property_' . $key, $val, $ctx );
        $this->data[$key] = $val;
    }

    /**
     * Unsets a property from the data array
     * @param string $key Property key
     */
    public function __unset($key) {
        $ctx = [ 'key' => $key, ];
        Dj_App_Hooks::doAction( 'app.page.action.pre_unset_property', $ctx );
        Dj_App_Hooks::doAction( 'app.page.action.pre_unset_property_' . $key, $ctx );
        unset($this->data[$key]);
    }

    /**
     * Checks if a data property exists
     * @param string $key Property key
     */
    public function __isset($key) {
        // Check if it exists directly in our data array first
        if (isset($this->data[$key])) {
            return true;
        }
        
        // For special computed properties
        if (in_array($key, ['full_page', 'page'])) {
            return true;
        }
        
        // Check if it exists in options as a fallback
        $options_obj = Dj_App_Options::getInstance();
        $val = $options_obj->get($key);
        return !empty($val);
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

    /**
     * Renders navigation menu from configuration
     * 
     * @param array $args Optional arguments to customize menu rendering
     * @return string HTML menu structure
     */
    public function renderMenu($args = []) 
    {
        $req_obj = Dj_App_Request::getInstance();
        $options_obj = Dj_App_Options::getInstance();
        $nav_options = $options_obj->get('page_nav');
        $nav_options = empty($nav_options) ? [] : (array) $nav_options;
        
        if (empty($nav_options)) {
            return '';
        }

        $current_page = $this->get('page');
        $menu_items = [];
        $submenus = [];
        
        // First pass: separate main items from submenus
        foreach ($nav_options as $key => $item) {
            // Skip if explicitly set as inactive
            if (isset($item['active']) && empty($item['active'])) {
                continue;
            }

            $url = empty($item['url']) ? '' : $item['url'];
            $slug = empty($item['slug']) ? $this->formatPageSlug($url) : $item['slug'];
            $title = empty($item['title']) ? '' : $item['title'];
            $parent = empty($item['parent']) ? '' : $item['parent'];

            if (empty($title) || empty($url)) {
                continue;
            }

            // put the web path prefix for relative URLs
            if (stripos($url, 'http') === false) {
                $url = Dj_App_Util::removeSlash($url); // this prevents home or / to not end in trailing slash for consistency
                $url = $req_obj->getWebPath() . $url;
            }

            $is_current = $slug == $current_page || 0;
            $item_class = 'dj-app-menu-item';
            
            if ($is_current) {
                $item_class .= ' dj-app-menu-item-current';
            }

            $item_data = [
                'key' => $key,
                'title' => $title,
                'url' => $url,
                'slug' => $slug,
                'is_current' => $is_current,
                'item_class' => $item_class
            ];

            if (!empty($parent)) {
                if (!isset($submenus[$parent])) {
                    $submenus[$parent] = [];
                }
                $submenus[$parent][] = $item_data;
                continue;
            }

            $menu_items[$key] = $item_data;
        }

        // Build HTML for menu items
        $menu_html_items = [];

        foreach ($menu_items as $key => $item) {
            $submenu_html = '';
            
            // Check if this item has submenus
            if (isset($submenus[$key])) {
                $item['item_class'] .= ' dj-app-menu-item-has-submenu';
                $submenu_items = [];

                foreach ($submenus[$key] as $subitem) {
                    $subitem_class = 'dj-app-menu-item';

                    if ($subitem['is_current']) {
                        $subitem_class .= ' dj-app-menu-item-current';
                    }
                    
                    if ($subitem['is_current']) {
                        $submenu_items[] = "            <li class='$subitem_class'><span class='dj-app-menu-text'>{$subitem['title']}</span></li>";
                    } else {
                        $submenu_items[] = "            <li class='$subitem_class'><a href='{$subitem['url']}' class='dj-app-menu-link'>{$subitem['title']}</a></li>";
                    }
                }
                
                if (!empty($submenu_items)) {
                    $submenu_html = "\n        <ul class='dj-app-submenu'>\n" . implode("\n", $submenu_items) . "\n        </ul>";
                }
            }
            
            if ($item['is_current']) {
                $menu_html_items[] = "        <li class='{$item['item_class']}'><span class='dj-app-menu-text'>{$item['title']}</span>$submenu_html</li>";
            } else {
                $menu_html_items[] = "        <li class='{$item['item_class']}'><a href='{$item['url']}' class='dj-app-menu-link'>{$item['title']}</a>$submenu_html</li>";
            }
        }

        // Filter menu items array before building HTML
        $menu_html_items = Dj_App_Hooks::applyFilter('app.page.menu.items', $menu_html_items, $args);

        $menu_parts = [
            '<div class="dj-app-menu-container">',
            '    <ul class="dj-app-menu-nav">',
            implode("\n", $menu_html_items),
            '    </ul>',
            '</div>',
        ];

        $menu_html = implode("\n", $menu_parts) . "\n";
        $menu_html = Dj_App_Hooks::applyFilter('app.page.menu.html', $menu_html, $args);

        echo $menu_html;
    }

    /**
     * @param string $page
     * @return string
     */
    public function formatPageSlug($page)
    {
        if (empty($page) || $page == '/') {
            return '';
        }

        // get last part of the page
        if (strpos($page, '/') !== false) {
            $page = rtrim($page, '/');
            $page = explode('/', $page);
            $page = array_pop($page);
        }

        // loop through the page and remove non alpha numeric and dash
        $page = preg_replace('/[^\w\-]/si', '_', $page);
        $page = preg_replace('/_+/si', '_', $page);
        $page = preg_replace('/\-+/si', '-', $page);
        $page = substr($page, 0, 100);
        $page = trim($page, '_-');

        return $page;
    }

    /**
     * Formats a full page slug by handling slashes and calling formatPageSlug for each element
     * en/home
     * @param string $page
     * @return string
     */
    public function formatFullPageSlug($page)
    {
        if (empty($page)) {
            return '';
        }

        $formatted_page = '';

        // Check if there's a slash in the page
        if (strpos($page, '/') !== false) {
            // Split by slash and format each element
            $segments = explode('/', $page);
            $formatted_segments = [];

            foreach ($segments as $segment) {
                $formatted_segment = $this->formatPageSlug($segment);
                
                if (!empty($formatted_segment)) {
                    $formatted_segments[] = $formatted_segment;
                }
            }

            // Join the formatted segments back with slashes
            $formatted_page = implode('/', $formatted_segments);
        } else {
            // No slashes, just format the single page
            $formatted_page = $this->formatPageSlug($page);
        }

        return $formatted_page;
    }
}

