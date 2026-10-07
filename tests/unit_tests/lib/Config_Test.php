<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Dj_App_Config class, which is declared in the bootstrap rather than a
 * lib of its own. Per-source-file home: EVERY Dj_App_Config method's tests belong here,
 * not in new per-feature files. Fixtures live in unit_tests/data/.
 */
class Dj_App_Config_Test extends TestCase
{
    public function testLoadIniFileWithMissingFile()
    {
        $missing_file = DJEBEL_APP_TEST_DATA_DIR . '/config_missing_nope.ini';
        $result = Dj_App_Config::loadIniFile($missing_file);

        $this->assertSame([], $result);
    }

    public function testLoadIniFileWithMalformedIni()
    {
        // Regression: parse_ini_file returns FALSE on a malformed file (e.g. a '#'
        // pseudo-comment with parens — '#' is NOT an ini comment); passing that to
        // array_change_key_case() was a fatal TypeError. Must bail out empty instead.
        $bad_file = DJEBEL_APP_TEST_DATA_DIR . '/config_bad.ini';
        $log_file = Dj_App_File_Util::generateTempFile();
        $backup_log_file = Dj_App_Log::file();

        // Only the window where the log destination is redirected — so an assertion that
        // fails cannot leak the redirect into whatever test runs next.
        try {
            $set_log_file = Dj_App_Log::file($log_file);

            // @ mutes parse_ini_file's own syntax warning — the fatal is what's under test.
            $result = @Dj_App_Config::loadIniFile($bad_file);

            $read_res = Dj_App_File_Util::read($log_file);
            $log_contents = $read_res->output;
        } finally {
            $restored_log_file = Dj_App_Log::file($backup_log_file);
            $delete_res = Dj_App_File_Util::delete($log_file);
        }

        $this->assertSame($log_file, $set_log_file, 'the log wrote to the fixture, not the real log');
        $this->assertSame([], $result);

        // An unparseable file loads as nothing, which reads exactly like one that set
        // nothing — so it is reported rather than returned quietly.
        $this->assertNotEmpty($log_contents, 'the loader logged the parse failure');
        $this->assertStringContainsString('could not be parsed', $log_contents);

        $this->assertSame($backup_log_file, $restored_log_file, 'the log destination was restored');
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLoadIniFileReturnsParsedData()
    {
        $env_file = DJEBEL_APP_TEST_DATA_DIR . '/config_env.ini';

        $result = Dj_App_Config::loadIniFile($env_file);

        // Pure loader: keys come back as parsed, untouched; no env side effects —
        // applying them is Dj_App_Env::set()'s job.
        $this->assertArrayHasKey('djebel_config_test_key', $result);
        $this->assertEquals('cfg_val_1', $result['djebel_config_test_key']);

        $env_val = Dj_App_Env::getEnv('djebel_config_test_key');
        $this->assertEmpty($env_val);
    }
}
