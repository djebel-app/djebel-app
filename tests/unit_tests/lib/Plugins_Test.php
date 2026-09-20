<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Plugins_Test extends TestCase {

    private $backup_debug = false;
    private $backup_error_log_file = false;

    protected function setUp(): void
    {
        $this->backup_debug = getenv('DJEBEL_APP_DEBUG');
        $this->backup_error_log_file = getenv('DJEBEL_APP_ERROR_LOG_FILE');

        // cfg() memoizes resolved values under the RAW dotted key — drop those so each test
        // resolves fresh through the conventional env keys it sets.
        Dj_App_Config::cfg('app.debug', '', [ 'override' => 1, ]);
        Dj_App_Config::cfg('app.error_log_file', '', [ 'override' => 1, ]);
    }

    protected function tearDown(): void
    {
        // false means the variable was absent, and null is how set() removes one.
        $debug = $this->backup_debug === false ? null : $this->backup_debug;
        Dj_App_Env::set('DJEBEL_APP_DEBUG', $debug);

        $error_log_file = $this->backup_error_log_file === false ? null : $this->backup_error_log_file;
        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $error_log_file);

        Dj_App_Config::cfg('app.debug', '', [ 'override' => 1, ]);
        Dj_App_Config::cfg('app.error_log_file', '', [ 'override' => 1, ]);
    }

    /**
     * Pins that a visitor learns nothing about logging from the crash box: the same sentence
     * whether or not the entry was written, the ref to quote, and never the raw message.
     */
    public function testLoadPluginsCrashBoxKeepsLoggingOffThePublicPage()
    {
        $unwritable_log_dir = $this->createUnwritableLogTarget();

        Dj_App_Env::set('DJEBEL_APP_DEBUG', 0);

        $crash_params = [
            'crash_msg' => 'secret crash detail',
            'log_file' => $unwritable_log_dir,
        ];

        $crash_res = $this->loadCrashingPlugin($crash_params);
        $crash_box = $crash_res['crash_box'];
        $req_id = Dj_App_Util::reqId();

        $this->assertStringContainsString('crashed: error', $crash_box, 'the public box names no detail');
        $this->assertStringContainsString("(ref: $req_id)", $crash_box, 'the visitor gets a ref to quote');
        $this->assertStringNotContainsString('Not written', $crash_box, 'the public box never says whether logging worked');
        $this->assertStringNotContainsString('secret crash detail', $crash_box, 'the raw message stays off the public box');

        $rmdir_res = Dj_App_File_Util::rmdir($unwritable_log_dir);
        $this->assertFalse($rmdir_res->isError(), 'the unwritable log fixture was removed');
    }

    /**
     * Pins that debug mode says when the entry missed the app error log, so a developer is not
     * left hunting for a line that was never written.
     */
    public function testLoadPluginsCrashBoxTellsDevelopersTheEntryWasNotWritten()
    {
        $unwritable_log_dir = $this->createUnwritableLogTarget();

        Dj_App_Env::set('DJEBEL_APP_DEBUG', 1);

        $crash_params = [
            'crash_msg' => 'dev crash detail',
            'log_file' => $unwritable_log_dir,
        ];

        $crash_res = $this->loadCrashingPlugin($crash_params);
        $crash_box = $crash_res['crash_box'];

        $this->assertStringContainsString('dev crash detail', $crash_box, 'debug mode shows the raw message');
        $this->assertStringContainsString('Not written to the app error log.', $crash_box, 'debug mode says the entry was not written');

        $rmdir_res = Dj_App_File_Util::rmdir($unwritable_log_dir);
        $this->assertFalse($rmdir_res->isError(), 'the unwritable log fixture was removed');
    }

    /**
     * Pins that the note depends on the write — without this case an always-on note would
     * pass the test above.
     */
    public function testLoadPluginsCrashBoxStaysQuietWhenTheEntryWasWritten()
    {
        $log_file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set('DJEBEL_APP_DEBUG', 1);

        $crash_params = [
            'crash_msg' => 'logged crash detail',
            'log_file' => $log_file,
        ];

        $crash_res = $this->loadCrashingPlugin($crash_params);
        $crash_box = $crash_res['crash_box'];

        $this->assertStringNotContainsString('Not written', $crash_box, 'a written entry gets no note');

        $read_res = Dj_App_File_Util::read($log_file);
        $this->assertStringContainsString('logged crash detail', $read_res->output, 'the crash reached the app error log');

        $delete_res = Dj_App_File_Util::delete($log_file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * Pins that a crash reaches the Result as well — the box tells the visitor, this tells
     * whatever called the loader, including whether the entry reached the app error log.
     */
    public function testCrashedPluginIsReportedOnTheResult()
    {
        $log_file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set('DJEBEL_APP_DEBUG', 0);

        $crash_params = [
            'crash_msg' => 'result crash detail',
            'log_file' => $log_file,
        ];

        $crash_res = $this->loadCrashingPlugin($crash_params);
        $crashed_plugins = $crash_res['load_res_obj']->crashed_plugins;

        $this->assertCount(1, $crashed_plugins, 'the crash was reported');
        $this->assertEquals('crash-fixture', $crashed_plugins[0]['plugin_id'], 'the entry names the plugin');
        $this->assertEquals('result crash detail', $crashed_plugins[0]['reason'], 'the entry carries the reason');
        $this->assertTrue($crashed_plugins[0]['logged'], 'the entry says the crash reached the app error log');

        $delete_res = Dj_App_File_Util::delete($log_file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * Pins that a plugin the run refused is reported as an entry naming it and the reason, so a
     * caller can answer "what did not load, and why" without parsing strings.
     */
    public function testSkippedPluginIsReportedWithItsReason()
    {
        $plugins_dir_opts = [
            'prefix' => 'dj_skip_plugins',
            'ext' => '',
        ];

        $plugins_dir = Dj_App_File_Util::generateTempFile($plugins_dir_opts);

        // A plugin dir with no plugin.php in it — nothing to load.
        $marker_file = $plugins_dir . '/no-main-file/readme.md';

        $write_res = Dj_App_File_Util::write($marker_file, "no plugin.php here\n");
        $this->assertTrue($write_res->isSuccess(), 'Failed to write the plugin dir fixture');

        $load_params = [
            'dir' => $plugins_dir,
        ];

        $load_res_obj = Dj_App_Plugins::loadPlugins($load_params);
        $skipped_plugins = $load_res_obj->skipped_plugins;

        $this->assertCount(1, $skipped_plugins, 'the one unusable dir was reported');
        $this->assertEquals('no-main-file', $skipped_plugins[0]['plugin_id'], 'the entry names the plugin');
        $this->assertEquals('main plugin file not found', $skipped_plugins[0]['reason'], 'the entry carries the reason');
        $this->assertEmpty($load_res_obj->plugins, 'nothing loaded');

        $rmdir_res = Dj_App_File_Util::rmdir($plugins_dir);
        $this->assertFalse($rmdir_res->isError(), 'the plugins fixture was removed');
    }

    /**
     * Pins that the flags a caller passes survive into the per-plugin checks. A system plugin
     * carries no meta header, so it loads only while 'is_system' is still there to be read.
     */
    public function testSystemPluginWithoutMetaHeaderLoads()
    {
        $plugins_dir_opts = [
            'prefix' => 'dj_sys_plugins',
            'ext' => '',
        ];

        $plugins_dir = Dj_App_File_Util::generateTempFile($plugins_dir_opts);
        $plugin_file = $plugins_dir . '/no-meta/plugin.php';

        $write_res = Dj_App_File_Util::write($plugin_file, "<?php\n");
        $this->assertTrue($write_res->isSuccess(), 'Failed to write the system plugin fixture');

        $load_params = [
            'dir' => $plugins_dir,
            'is_system' => true,
        ];

        $load_res = Dj_App_Plugins::loadPlugins($load_params);

        $this->assertTrue($load_res->isSuccess(), 'the loader ran');
        $this->assertArrayHasKey($plugin_file, $load_res->plugins, 'a system plugin without a meta header still loads');

        $rmdir_res = Dj_App_File_Util::rmdir($plugins_dir);
        $this->assertFalse($rmdir_res->isError(), 'the plugins fixture was removed');
    }

    /**
     * An existing DIRECTORY to point the app error log at, so the write must fail.
     * @return string
     */
    private function createUnwritableLogTarget()
    {
        $log_dir_opts = [
            'prefix' => 'dj_plugins_bad_log',
            'ext' => '',
        ];

        $log_dir = Dj_App_File_Util::generateTempFile($log_dir_opts);

        $mkdir_res = Dj_App_File_Util::mkdir($log_dir);
        $this->assertFalse($mkdir_res->isError(), 'Failed to create the unwritable log fixture');

        return $log_dir;
    }

    /**
     * Loads a plugins dir holding one plugin that throws.
     * @param array $params crash_msg, log_file (where the app error log is pointed)
     * @return array crash_box (what the box printed), load_res_obj (the loader's Result)
     */
    private function loadCrashingPlugin($params)
    {
        $plugins_dir_opts = [
            'prefix' => 'dj_plugins',
            'ext' => '',
        ];

        $plugins_dir = Dj_App_File_Util::generateTempFile($plugins_dir_opts);
        $fallback_file = Dj_App_File_Util::generateTempFile();

        // Written fresh for every test: a file that was already included does not run again, so
        // a shared fixture would crash only in whichever test happened to run first.
        $crash_msg_src = var_export($params['crash_msg'], true);
        $plugin_src = "<?php\n/*\nplugin_name: Crash Fixture\n*/\n\nthrow new Exception($crash_msg_src);\n";
        $plugin_file = $plugins_dir . '/crash-fixture/plugin.php';

        $write_res = Dj_App_File_Util::write($plugin_file, $plugin_src);
        $this->assertTrue($write_res->isSuccess(), 'Failed to write the crashing plugin fixture');

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $params['log_file']);

        $load_params = [
            'dir' => $plugins_dir,
        ];

        try {
            $prior_error_log = ini_get('error_log');
            $prior_error_reporting = error_reporting();

            // Both PHP's own log and the failing write's warnings are captured here, so neither
            // lands in the run's output.
            ini_set('error_log', $fallback_file);
            error_reporting(0);

            ob_start();
            $load_res_obj = Dj_App_Plugins::loadPlugins($load_params);
        } finally {
            $crash_box = ob_get_clean();
            error_reporting($prior_error_reporting);
            ini_set('error_log', $prior_error_log);
        }

        $this->assertTrue($load_res_obj->isSuccess(), 'the loader carried on past the crash');
        $this->assertStringContainsString('Plugin [crash-fixture] crashed:', $crash_box, 'the crash box was printed');

        $rmdir_res = Dj_App_File_Util::rmdir($plugins_dir);
        $this->assertFalse($rmdir_res->isError(), 'the plugins fixture was removed');

        $delete_res = Dj_App_File_Util::delete($fallback_file);
        $this->assertFalse($delete_res->isError(), 'the fallback log fixture was removed');

        $crash_result = [
            'crash_box' => $crash_box,
            'load_res_obj' => $load_res_obj,
        ];

        return $crash_result;
    }
}
