<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Themes_Test extends TestCase {

    // The dir the themes-dir filter answers with, so each test renders its own fixture.
    public static $themes_dir = '';

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

        Dj_App_Hooks::addFilter('app.themes.themes_dir', ['Dj_App_Themes_Test', 'filterThemesDir']);
    }

    protected function tearDown(): void
    {
        $is_themes_dir_filter_removed = Dj_App_Hooks::removeFilter('app.themes.themes_dir', ['Dj_App_Themes_Test', 'filterThemesDir']);
        $this->assertTrue($is_themes_dir_filter_removed, 'the themes dir filter was removed');

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
    }

    public static function filterThemesDir($cur_val, $ctx = [])
    {
        return Dj_App_Themes_Test::$themes_dir;
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
