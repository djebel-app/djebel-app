<?php

use PHPUnit\Framework\TestCase;

/**
 * Each case boots djebel in a child process of its own, over the committed fixtures in
 * data/headless, with an environment built here rather than inherited from the suite.
 */
class Dj_App_Bootstrap_Test extends TestCase {

    const REPORT_MARKER = 'DJ_BOOT_REPORT:';

    const FIXTURE_CLASSES = [
        'Dj_App_Test_Sys_Fixture',
        'Dj_App_Test_Regular_Fixture',
        'Dj_App_Test_Private_Fixture',
    ];

    /**
     * Pins what an API or a worker gets: every plugin folder loads, the URL rule still keeps a
     * plugin off, the request parse reaches a system plugin, app.core.init fires and headless's
     * own action after it, and nothing is printed, not even for a plugin that crashes.
     */
    public function testHeadlessLoadsPluginsAndPrintsNothing()
    {
        $boot_env = [
            'DJEBEL_APP_CORE_HEADLESS' => '1',
        ];

        $boot_res = $this->runBoot($boot_env);
        $report = $boot_res['report'];

        $this->assertNotEmpty($report, 'the boot returned to its caller');
        $this->assertEquals(self::FIXTURE_CLASSES, $report['loaded_classes'], 'system, regular and private plugins loaded; the URL rule kept one off');
        $this->assertTrue($report['core_init_fired'], 'headless fired app.core.init');
        $this->assertTrue($report['headless_init_fired'], 'headless fired its own action');
        $this->assertContains('sys_fixture_parsed', $report['segments'], 'the request was parsed again after system plugins loaded');
        $this->assertEmpty($boot_res['output'], 'headless printed nothing, the crash box included');
    }

    /**
     * Pins that a page is unchanged beside headless: the same plugins, app.core.init, the crash
     * box, and the request parse reaching a system plugin there too.
     */
    public function testPageLoadsPluginsAndFiresInit()
    {
        $boot_res = $this->runBoot();
        $report = $boot_res['report'];

        $this->assertNotEmpty($report, 'the boot returned to its caller');
        $this->assertEquals(self::FIXTURE_CLASSES, $report['loaded_classes'], 'a page loads the same plugins');
        $this->assertTrue($report['core_init_fired'], 'a page fires app.core.init');
        $this->assertFalse($report['headless_init_fired'], 'a page does not fire the headless action');
        $this->assertContains('sys_fixture_parsed', $report['segments'], 'the request was parsed again after system plugins loaded');
        $this->assertStringContainsString('Plugin [crash_fixture] crashed:', $boot_res['output'], 'a page shows the crash box');
    }

    /**
     * Pins that run off stays "core only", also when headless is set beside it, so a stray
     * headless that a run-off process inherits never loads plugins.
     */
    public function testRunOffLoadsNoPluginEvenWithHeadless()
    {
        $boot_envs = [
            'run off' => [ 'DJEBEL_APP_CORE_RUN' => '0', ],
            'run off with headless' => [ 'DJEBEL_APP_CORE_RUN' => '0', 'DJEBEL_APP_CORE_HEADLESS' => '1', ],
        ];

        foreach ($boot_envs as $case_name => $boot_env) {
            $boot_res = $this->runBoot($boot_env);
            $report = $boot_res['report'];

            $this->assertNotEmpty($report, "$case_name: the boot returned to its caller: {$boot_res['errors']}");
            $this->assertEmpty($report['loaded_classes'], "$case_name: no plugin loaded");
            $this->assertFalse($report['core_init_fired'], "$case_name: app.core.init did not fire");
            $this->assertFalse($report['headless_init_fired'], "$case_name: the headless action did not fire");
        }
    }

    /**
     * Pins that run is read as an on/off word: every off word stops at the gate, and an on word
     * or a word it does not know serves the request, its default. An empty value stops at the
     * gate as well; it is not a case here, since a child process cannot be handed an empty env var.
     */
    public function testRunSwitchReadsOnOffWords()
    {
        $run_cases = [
            [ 'word' => 'false', 'is_run' => false, ],
            [ 'word' => 'no', 'is_run' => false, ],
            [ 'word' => 'off', 'is_run' => false, ],
            [ 'word' => '1', 'is_run' => true, ],
            [ 'word' => 'yes', 'is_run' => true, ],
            [ 'word' => 'unknown_word', 'is_run' => true, ],
        ];

        foreach ($run_cases as $run_case) {
            $boot_env = [
                'DJEBEL_APP_CORE_RUN' => $run_case['word'],
            ];

            $boot_res = $this->runBoot($boot_env);
            $report = $boot_res['report'];

            $this->assertNotEmpty($report, "run='{$run_case['word']}': the boot returned to its caller: {$boot_res['errors']}");
            $this->assertSame($run_case['is_run'], $report['core_init_fired'], "run='{$run_case['word']}'");
        }
    }

    /**
     * Pins that headless is read as an on/off word: an on word loads plugins and serves nothing,
     * and an off word or a word it does not know serves the page, its default.
     */
    public function testHeadlessSwitchReadsOnOffWords()
    {
        $headless_cases = [
            [ 'word' => 'yes', 'is_headless' => true, ],
            [ 'word' => 'on', 'is_headless' => true, ],
            [ 'word' => 'true', 'is_headless' => true, ],
            [ 'word' => 'false', 'is_headless' => false, ],
            [ 'word' => 'no', 'is_headless' => false, ],
            [ 'word' => '0', 'is_headless' => false, ],
            [ 'word' => 'unknown_word', 'is_headless' => false, ],
        ];

        foreach ($headless_cases as $headless_case) {
            $boot_env = [
                'DJEBEL_APP_CORE_HEADLESS' => $headless_case['word'],
            ];

            $boot_res = $this->runBoot($boot_env);
            $report = $boot_res['report'];

            $this->assertNotEmpty($report, "headless='{$headless_case['word']}': the boot returned to its caller: {$boot_res['errors']}");
            $this->assertSame($headless_case['is_headless'], $report['headless_init_fired'], "headless='{$headless_case['word']}'");
            $this->assertTrue($report['core_init_fired'], "headless='{$headless_case['word']}': app.core.init fires either way");
        }
    }

    /**
     * Pins that the request URI reaches the bootstrap when only the server variables carry it, as
     * under CGI, FastCGI and the CLI: a deep URL gives its segments, and a static file's URL is
     * the 404 on a page while headless leaves it alone.
     */
    public function testRequestUriReachesTheBootstrap()
    {
        $boot_env = [
            'REQUEST_URI' => '/about/team',
        ];

        $boot_res = $this->runBoot($boot_env);
        $report = $boot_res['report'];

        $this->assertNotEmpty($report, "the boot returned to its caller: {$boot_res['errors']}");
        $this->assertContains('about', $report['segments'], 'the URL gave its first segment');
        $this->assertContains('team', $report['segments'], 'the URL gave its second segment');

        $boot_env['REQUEST_URI'] = '/logo.jpg';
        $boot_res = $this->runBoot($boot_env);

        $this->assertEmpty($boot_res['report'], 'a page stopped at the static-file 404');
        $this->assertStringContainsString('Page Not Found (404)', $boot_res['output'], 'a page answered the static file with the 404');

        $boot_env['DJEBEL_APP_CORE_HEADLESS'] = '1';
        $boot_res = $this->runBoot($boot_env);

        $this->assertNotEmpty($boot_res['report'], 'headless left the static file URL to its caller');
    }

    /**
     * Pins that a page whose plugin throws from app.core.init answers with djebel's error page and
     * its ref, never an empty body.
     */
    public function testPageErrorShowsTheErrorPage()
    {
        $boot_env = [
            'DJEBEL_APP_CONTENT_DIR' => DJEBEL_APP_TEST_DATA_DIR . '/headless/content_init_throw',
        ];

        $boot_res = $this->runBoot($boot_env);

        $this->assertEmpty($boot_res['report'], 'the boot ended in the error');
        $this->assertStringContainsString('<html', $boot_res['output'], 'the visitor gets a page');
        $this->assertStringContainsString('Something went wrong.', $boot_res['output'], 'the page carries the public message');
        $this->assertStringContainsString('Ref: ', $boot_res['output'], 'the page carries the ref');
    }

    /**
     * Pins that an error in headless never answers with a page: plain text with the ref, and
     * JSON with the ref for an AJAX call.
     */
    public function testHeadlessErrorIsTextOrJsonNeverAPage()
    {
        $boot_env = [
            'DJEBEL_APP_CONTENT_DIR' => DJEBEL_APP_TEST_DATA_DIR . '/headless/content_init_throw',
            'DJEBEL_APP_CORE_HEADLESS' => '1',
        ];

        $boot_res = $this->runBoot($boot_env);

        $this->assertStringStartsWith('Djebel Error: Something went wrong. (ref: ', $boot_res['output'], 'headless answers in plain text with the ref');
        $this->assertStringNotContainsString('<', $boot_res['output'], 'the plain text holds no markup');

        $boot_env['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $boot_res = $this->runBoot($boot_env);
        $error_json = Dj_App_String_Util::jsonDecode($boot_res['output']);

        $this->assertFalse($error_json['status'], 'an AJAX call gets a failed Result');
        $this->assertEquals('Something went wrong.', $error_json['msg'], 'the Result carries the public message');
        $this->assertNotEmpty($error_json['data']['req_id'], 'the Result carries the ref');
    }

    /**
     * Boots djebel in a child process over the fixtures and reads its report.
     * @param array $boot_env env vars for this case, on top of the fixture dirs
     * @return array output (what the boot printed), report (decoded; empty when the boot ended in
     *               an error), exit_code, errors (the child's stderr)
     */
    private function runBoot($boot_env = [])
    {
        $data_dir = DJEBEL_APP_TEST_DATA_DIR . '/headless';

        // The error log stays off so a crashing fixture writes nothing into the committed tree.
        $child_env = [
            'DJEBEL_APP_CONTENT_DIR' => $data_dir . '/content',
            'DJEBEL_APP_PRIVATE_DIR' => $data_dir . '/private',
            'DJEBEL_APP_ERROR_LOGGING' => '0',
            'DJEBEL_PLUGINS_URL_FIXTURE_LOAD_IF_URL' => '/nowhere',
        ];

        $child_env = array_replace($child_env, $boot_env);
        $djebel_dir = dirname(__DIR__, 3);
        $boot_cmd = [ PHP_BINARY, $data_dir . '/boot_report.php', $djebel_dir, ];

        $child_streams = [
            1 => [ 'pipe', 'w', ],
            2 => [ 'pipe', 'w', ],
        ];

        $proc = proc_open($boot_cmd, $child_streams, $pipes, null, $child_env);
        $this->assertIsResource($proc, 'the boot process started');

        $child_output = stream_get_contents($pipes[1]);
        $child_errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit_code = proc_close($proc);
        $marker_pos = strrpos($child_output, self::REPORT_MARKER);

        // A boot that ended in an error never reaches the report: all it printed is the output.
        if ($marker_pos === false) {
            $printed_output = $child_output;
            $report = [];
        } else {
            $printed_output = substr($child_output, 0, $marker_pos);
            $report_json = substr($child_output, $marker_pos + strlen(self::REPORT_MARKER));
            $report = Dj_App_String_Util::jsonDecode($report_json);
        }

        $printed_output = Dj_App_String_Util::trim($printed_output);

        $boot_res = [
            'output' => $printed_output,
            'report' => $report,
            'exit_code' => $exit_code,
            'errors' => $child_errors,
        ];

        return $boot_res;
    }
}
