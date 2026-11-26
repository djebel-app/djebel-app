<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Themes_Test extends TestCase {

    // The dir the themes-dir filter answers with, so each test renders its own fixture.
    public static $themes_dir = '';

    // The address the full-page filter answers with; the CLI request itself has none.
    public static $full_page = '';

    private $backup_load_functions = false;
    private $backup_load_header = false;
    private $backup_load_footer = false;
    private $backup_content = '';

    protected function setUp(): void
    {
        $this->backup_load_functions = getenv('DJEBEL_APP_CORE_THEME_LOAD_THEME_FUNCTIONS');
        $this->backup_load_header = getenv('DJEBEL_APP_CORE_THEME_LOAD_THEME_HEADER');
        $this->backup_load_footer = getenv('DJEBEL_APP_CORE_THEME_LOAD_THEME_FOOTER');

        // A theme load hands the finished page to the request singleton, which outlives the test.
        $req_obj = Dj_App_Request::getInstance();
        $this->backup_content = $req_obj->getContent();

        $this->clearThemeConfigMemo();

        $page_obj = Dj_App_Page::getInstance();
        $page_obj->clear();

        $themes_obj = Dj_App_Themes::getInstance();

        Dj_App_Hooks::addFilter('app.themes.themes_dir', ['Dj_App_Themes_Test', 'filterThemesDir']);
        Dj_App_Hooks::addFilter('app.core.request.page.get.full_page', ['Dj_App_Themes_Test', 'filterFullPage']);
        Dj_App_Hooks::addAction('app.page.content.render', [$themes_obj, 'renderPageContent']);
    }

    protected function tearDown(): void
    {
        $is_themes_dir_filter_removed = Dj_App_Hooks::removeFilter('app.themes.themes_dir', ['Dj_App_Themes_Test', 'filterThemesDir']);
        $this->assertTrue($is_themes_dir_filter_removed, 'the themes dir filter was removed');

        $is_full_page_filter_removed = Dj_App_Hooks::removeFilter('app.core.request.page.get.full_page', ['Dj_App_Themes_Test', 'filterFullPage']);
        $this->assertTrue($is_full_page_filter_removed, 'the full page filter was removed');

        $themes_obj = Dj_App_Themes::getInstance();
        $is_render_action_removed = Dj_App_Hooks::removeAction('app.page.content.render', [$themes_obj, 'renderPageContent']);
        $this->assertTrue($is_render_action_removed, 'the content render listener was removed');

        $page_obj = Dj_App_Page::getInstance();
        $page_obj->clear();

        // false means the variable was absent, and null is how set() removes one.
        $load_functions = $this->backup_load_functions === false ? null : $this->backup_load_functions;
        Dj_App_Env::set('DJEBEL_APP_CORE_THEME_LOAD_THEME_FUNCTIONS', $load_functions);

        $load_header = $this->backup_load_header === false ? null : $this->backup_load_header;
        Dj_App_Env::set('DJEBEL_APP_CORE_THEME_LOAD_THEME_HEADER', $load_header);

        $load_footer = $this->backup_load_footer === false ? null : $this->backup_load_footer;
        Dj_App_Env::set('DJEBEL_APP_CORE_THEME_LOAD_THEME_FOOTER', $load_footer);

        $this->clearThemeConfigMemo();

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setContent($this->backup_content);

        Dj_App_Themes_Test::$themes_dir = '';
        Dj_App_Themes_Test::$full_page = '';
    }

    public static function filterThemesDir($cur_val, $ctx = [])
    {
        return Dj_App_Themes_Test::$themes_dir;
    }

    public static function filterFullPage($cur_val, $ctx = [])
    {
        return Dj_App_Themes_Test::$full_page;
    }

    /**
     * Pins that an address no page serves answers 404 with djebel's own page when the theme has
     * no page for the error. It used to answer 200 with the theme's layout around an empty body,
     * because the theme's index.php counted as the page.
     */
    public function testLoadThemeAnswersAMissingPageWithDjebelsPage()
    {
        Dj_App_Themes_Test::$full_page = 'no-such-page';

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setResponseCode(418);

        $theme_files = [
            'index.php' => "<?php echo 'THEME LAYOUT'; Dj_App_Hooks::doAction('app.page.content.render'); ?>\n",
            'pages/about.php' => "<?php echo 'ABOUT PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);
        $content = $req_obj->getContent();
        $response_code = $req_obj->getResponseCode();

        $this->assertEquals(404, $response_code);
        $this->assertEquals('error_page', $theme_info['render_mode']);
        $this->assertStringContainsString('djebel-app-error-container', $content, "djebel's own page answered");
        $this->assertStringContainsString('The requested page can not be found.', $content);
        $this->assertStringNotContainsString('THEME LAYOUT', $content, "the theme's render was thrown away");
    }

    /**
     * Pins the same answer for a theme that pulls its body in with [djebel_page_content]. That
     * shortcode renders the page while the finished page is filtered, so the 404 is found only
     * then, and the theme's layout used to go out around an empty body under the 404.
     */
    public function testLoadThemeAnswersAMissingPageWithDjebelsPageForAShortcodeTheme()
    {
        Dj_App_Themes_Test::$full_page = 'no-such-page';

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setResponseCode(418);

        $theme_files = [
            'index.php' => "THEME LAYOUT [djebel_page_content]\n",
            'pages/about.php' => "<?php echo 'ABOUT PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);
        $content = $req_obj->getContent();
        $response_code = $req_obj->getResponseCode();

        $this->assertEquals(404, $response_code);
        $this->assertEquals('error_page', $theme_info['render_mode']);
        $this->assertStringContainsString('djebel-app-error-container', $content, "djebel's own page answered");
        $this->assertStringNotContainsString('THEME LAYOUT', $content, "the theme's render was thrown away");
    }

    /**
     * Pins that a shortcode theme with its own page for the error shows it inside its layout.
     */
    public function testLoadThemeShowsTheErrorPageOfAShortcodeTheme()
    {
        Dj_App_Themes_Test::$full_page = 'no-such-page';

        $theme_files = [
            'index.php' => "THEME LAYOUT [djebel_page_content]\n",
            'pages/404.php' => "<?php echo 'THEME NOT FOUND PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $req_obj = Dj_App_Request::getInstance();
        $content = $req_obj->getContent();

        $this->assertEquals('main_file', $theme_info['render_mode']);
        $this->assertStringContainsString('THEME LAYOUT', $content);
        $this->assertStringContainsString('THEME NOT FOUND PAGE', $content);
        $this->assertStringNotContainsString('djebel-app-error-container', $content);
    }

    /**
     * Pins that a theme with its own page for the error shows it, inside its layout and under the
     * error's code; djebel's page is only for a theme that has none.
     */
    public function testLoadThemeShowsTheThemesOwnErrorPage()
    {
        Dj_App_Themes_Test::$full_page = 'no-such-page';

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setResponseCode(418);

        $theme_files = [
            'index.php' => "<?php echo 'THEME LAYOUT'; Dj_App_Hooks::doAction('app.page.content.render'); ?>\n",
            'pages/404.php' => "<?php echo 'THEME NOT FOUND PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);
        $content = $req_obj->getContent();
        $response_code = $req_obj->getResponseCode();

        $this->assertEquals(404, $response_code);
        $this->assertEquals('main_file', $theme_info['render_mode']);
        $this->assertStringContainsString('THEME LAYOUT', $content);
        $this->assertStringContainsString('THEME NOT FOUND PAGE', $content);
        $this->assertStringNotContainsString('djebel-app-error-container', $content);
    }

    /**
     * Pins that an error a plugin set before the theme loads replaces the page the address would
     * normally get. A missing blog post used to render the blog's page with a 200.
     */
    public function testLoadThemeAnswersAPluginErrorWithDjebelsPage()
    {
        Dj_App_Themes_Test::$full_page = 'blog';

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setResponseCode(418);

        $page_obj = Dj_App_Page::getInstance();

        $error_params = [
            'code' => 503,
        ];

        $page_obj->setError($error_params);

        $theme_files = [
            'index.php' => "<?php echo 'THEME LAYOUT'; Dj_App_Hooks::doAction('app.page.content.render'); ?>\n",
            'pages/blog.php' => "<?php echo 'BLOG PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);
        $content = $req_obj->getContent();
        $response_code = $req_obj->getResponseCode();

        $this->assertEquals(503, $response_code);
        $this->assertEquals('error_page', $theme_info['render_mode']);
        $this->assertStringContainsString('djebel-app-error-container', $content);
        $this->assertStringNotContainsString('BLOG PAGE', $content, 'the page for the address never rendered');
        $this->assertStringNotContainsString('THEME LAYOUT', $content);
    }

    /**
     * Pins that an error a plugin set shows the theme's page for that code in place of the page the
     * address would normally get, so a theme's own error design reaches plugin errors too.
     */
    public function testLoadThemeShowsTheThemesPageForAPluginError()
    {
        Dj_App_Themes_Test::$full_page = 'blog';

        $page_obj = Dj_App_Page::getInstance();

        $error_params = [
            'code' => 503,
        ];

        $page_obj->setError($error_params);

        $theme_files = [
            'index.php' => "<?php echo 'THEME LAYOUT'; Dj_App_Hooks::doAction('app.page.content.render'); ?>\n",
            'pages/blog.php' => "<?php echo 'BLOG PAGE'; ?>\n",
            'pages/503.php' => "<?php echo 'THEME UNAVAILABLE PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $req_obj = Dj_App_Request::getInstance();
        $content = $req_obj->getContent();

        $this->assertEquals('main_file', $theme_info['render_mode']);
        $this->assertStringContainsString('THEME UNAVAILABLE PAGE', $content);
        $this->assertStringNotContainsString('BLOG PAGE', $content);
    }

    /**
     * Pins that the home page still renders through the theme's main file when the theme has no
     * page for it: only other addresses lost the index.php fallback.
     */
    public function testLoadThemeKeepsTheHomePageWithoutAPageFile()
    {
        $theme_files = [
            'index.php' => "<?php echo 'THEME LAYOUT'; Dj_App_Hooks::doAction('app.page.content.render'); ?>\n",
            'pages/about.php' => "<?php echo 'ABOUT PAGE'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $req_obj = Dj_App_Request::getInstance();
        $content = $req_obj->getContent();

        $page_obj = Dj_App_Page::getInstance();
        $error = $page_obj->getError();

        $this->assertEmpty($error, 'the home page is not an error');
        $this->assertEquals('main_file', $theme_info['render_mode']);
        $this->assertStringContainsString('THEME LAYOUT', $content);
    }

    /**
     * Pins that a theme carrying only its main file says so, and names what it resolved — the
     * answer used to exist as locals and vanish when the method returned.
     */
    public function testLoadThemeReportsTheMainFileRender()
    {
        $theme_files = [
            'index.php' => "<?php echo 'main file output'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $this->assertEquals('probe-theme', $theme_info['theme'], 'the theme id it settled on');
        $this->assertStringContainsString('probe-theme', $theme_info['theme_dir'], 'the dir it resolved');
        $this->assertNotEmpty($theme_info['theme_url'], 'the url it built');
        $this->assertEquals('main_file', $theme_info['render_mode'], 'no header/footer, so the main file rendered');
        $this->assertFalse($theme_info['functions_loaded'], 'the theme ships no functions file');
        $this->assertFalse($theme_info['header_loaded']);
        $this->assertFalse($theme_info['footer_loaded']);
    }

    /**
     * Pins that the functions file being included is reported. Nothing else records it, so
     * without this the only way to know is to look for whatever the file did.
     */
    public function testLoadThemeReportsTheLoadedFunctionsFile()
    {
        Dj_App_Env::set('DJEBEL_APP_CORE_THEME_LOAD_THEME_FUNCTIONS', 1);

        $theme_files = [
            'index.php' => "<?php echo 'main file output'; ?>\n",
            'functions.php' => "<?php \$GLOBALS['dj_test_theme_functions_ran'] = 1; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $this->assertTrue($theme_info['functions_loaded'], 'the functions file was included');
        $this->assertEquals('main_file', $theme_info['render_mode']);

        unset($GLOBALS['dj_test_theme_functions_ran']);
    }

    /**
     * Pins the other render path: a theme whose header and footer both produce output is
     * assembled around the page content instead of running its main file.
     */
    public function testLoadThemeReportsTheSandwichRender()
    {
        Dj_App_Env::set([
            'DJEBEL_APP_CORE_THEME_LOAD_THEME_HEADER' => 1,
            'DJEBEL_APP_CORE_THEME_LOAD_THEME_FOOTER' => 1,
        ]);

        $theme_files = [
            'index.php' => "<?php echo 'main file output'; ?>\n",
            'header.php' => "<?php echo 'HEADER'; ?>\n",
            'footer.php' => "<?php echo 'FOOTER'; ?>\n",
        ];

        $theme_info = $this->loadFixtureTheme($theme_files);

        $this->assertTrue($theme_info['header_loaded'], 'the header file was included');
        $this->assertTrue($theme_info['footer_loaded'], 'the footer file was included');
        $this->assertEquals('sandwich', $theme_info['render_mode'], 'header + footer wrap the content');

        $req_obj = Dj_App_Request::getInstance();
        $content = $req_obj->getContent();

        $this->assertStringContainsString('HEADER', $content, 'the rendered page carries the header');
        $this->assertStringContainsString('FOOTER', $content, 'and the footer');
    }

    /**
     * Writes a throwaway theme, loads it, and returns what the load reported.
     * @param array $theme_files file name => contents
     * @return array
     */
    private function loadFixtureTheme($theme_files)
    {
        $themes_dir_opts = [
            'prefix' => 'dj_themes',
            'ext' => '',
        ];

        $themes_dir = Dj_App_File_Util::generateTempFile($themes_dir_opts);
        Dj_App_Themes_Test::$themes_dir = $themes_dir;

        // Fresh per test: an already-included theme file does not run again.
        $theme_dir = $themes_dir . '/probe-theme';

        foreach ($theme_files as $theme_file_name => $theme_file_body) {
            $theme_file = $theme_dir . '/' . $theme_file_name;
            $write_res_obj = Dj_App_File_Util::write($theme_file, $theme_file_body);

            $this->assertTrue($write_res_obj->isSuccess(), 'Failed to write the theme fixture: ' . $theme_file_name);
        }

        $themes_obj = Dj_App_Themes::getInstance();

        $load_params = [
            'theme' => 'probe-theme',
        ];

        $res_obj = $themes_obj->loadTheme($load_params);

        $this->assertTrue($res_obj->isSuccess(), 'the theme loaded');

        $theme_info = $res_obj->theme_info;

        $rmdir_res_obj = Dj_App_File_Util::rmdir($themes_dir);
        $this->assertFalse($rmdir_res_obj->isError(), 'the themes fixture was removed');

        return $theme_info;
    }

    /**
     * cfg() memoizes each resolved value under the RAW dotted key, and the raw key wins over the
     * conventional env var — so a test that sets one must drop the memo first.
     */
    private function clearThemeConfigMemo()
    {
        $memo_keys = [
            'app.core.theme.load_theme_functions',
            'app.core.theme.load_theme_header',
            'app.core.theme.load_theme_footer',
        ];

        $clear_attribs = [
            'override' => 1,
        ];

        foreach ($memo_keys as $memo_key) {
            Dj_App_Config::cfg($memo_key, '', $clear_attribs);
        }
    }
}
