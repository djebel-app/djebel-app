<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Log_Test extends TestCase {

    private $backup_error_logging = false;
    private $backup_error_log_file = false;

    protected function setUp(): void
    {
        $this->backup_error_logging = getenv('DJEBEL_APP_ERROR_LOGGING');
        $this->backup_error_log_file = getenv('DJEBEL_APP_ERROR_LOG_FILE');

        putenv('DJEBEL_APP_ERROR_LOGGING');
        putenv('DJEBEL_APP_ERROR_LOG_FILE');

        // cfg() memoizes resolved values under the RAW dotted key — drop those
        // so each test resolves fresh through the conventional env keys above.
        Dj_App_Config::cfg('app.error_logging', '', [ 'override' => 1, ]);
        Dj_App_Config::cfg('app.error_log_file', '', [ 'override' => 1, ]);
    }

    protected function tearDown(): void
    {
        if ($this->backup_error_logging === false) {
            putenv('DJEBEL_APP_ERROR_LOGGING');
        } else {
            putenv('DJEBEL_APP_ERROR_LOGGING=' . $this->backup_error_logging);
        }

        if ($this->backup_error_log_file === false) {
            putenv('DJEBEL_APP_ERROR_LOG_FILE');
        } else {
            putenv('DJEBEL_APP_ERROR_LOG_FILE=' . $this->backup_error_log_file);
        }

        Dj_App_Config::cfg('app.error_logging', '', [ 'override' => 1, ]);
        Dj_App_Config::cfg('app.error_log_file', '', [ 'override' => 1, ]);
    }

    private function tmpFile()
    {
        return sys_get_temp_dir() . '/dj_log_test_' . getmypid() . '_' . uniqid() . '.log';
    }

    public function testMsgWritesTimestampedLabelledLine()
    {
        $file = $this->tmpFile();

        $line = Dj_App_Log::msg('hello world', 'MYLABEL', $file);

        $this->assertStringContainsString('MYLABEL', $line);
        $this->assertStringContainsString('hello world', $line);
        $this->assertFileExists($file);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('hello world', $contents);
        $this->assertStringContainsString('MYLABEL', $contents);

        unlink($file);
    }

    public function testLevelsPrefixTheMessage()
    {
        $file = $this->tmpFile();

        Dj_App_Log::error('boom', '', $file);
        Dj_App_Log::info('note', '', $file);
        Dj_App_Log::warn('careful', '', $file);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('[ERROR] boom', $contents);
        $this->assertStringContainsString('[INFO] note', $contents);
        $this->assertStringContainsString('[WARN] careful', $contents);

        unlink($file);
    }

    public function testDisabledLoggingWritesNothing()
    {
        $file = $this->tmpFile();

        Dj_App_Log::disableLogging();
        $res = Dj_App_Log::msg('should not write', '', $file);
        Dj_App_Log::enableLogging();

        $this->assertEmpty($res);
        $this->assertFileDoesNotExist($file);
    }

    public function testPrepMsgDumpsNonScalar()
    {
        $out = Dj_App_Log::prepMsg([ 'a' => 1, ]);

        $this->assertNotEmpty($out);
        $this->assertStringContainsString('a', $out);
    }

    public function testRequestIdTagsTheLine()
    {
        $file = $this->tmpFile();

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setRequestId('req-abc');
        $line = Dj_App_Log::msg('hi', 'L', $file);
        $req_obj->setRequestId('');

        $this->assertStringContainsString('req-abc', $line);

        unlink($file);
    }

    public function testFileHonorsExplicitFile()
    {
        $file = $this->tmpFile();

        $resolved = Dj_App_Log::file($file);

        $this->assertEquals($file, $resolved);
    }

    public function testDumpReturnsStringWhenNotPrinting()
    {
        $out = Dj_App_Log::dump('secret data', 'L', false);

        $this->assertStringContainsString('secret data', $out);
    }

    public function testRemoveNotEssentialStuffCompactsTypeNoise()
    {
        $cleaned = Dj_App_Log::removeNotEssentialStuff('int(42) bool(true) bool(false)');

        $this->assertStringContainsString('42', $cleaned);
        $this->assertStringContainsString('true', $cleaned);
        $this->assertStringContainsString('false', $cleaned);
        $this->assertStringNotContainsString('int(', $cleaned);
        $this->assertStringNotContainsString('bool(', $cleaned);
    }

    public function testMsgRawWritesVerbatimEntry()
    {
        $file = $this->tmpFile();

        $entry = "[2026-08-05 00:00:00] Fatal Error: boom in /x.php on line 1\n" . str_repeat('-', 80) . "\n";

        $line = Dj_App_Log::msg($entry, '', $file, [ 'raw' => 1, ]);

        $this->assertEquals($entry, $line, 'raw mode returns the entry untouched');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'raw mode writes VERBATIM — no prefix, no extra newline');

        unlink($file);
    }

    public function testMsgRawKeepsMultibyteEntryIntact()
    {
        $file = $this->tmpFile();

        $entry = "[2026-08-05 00:00:00] Exception: Разбрах — тест\n";

        Dj_App_Log::msg($entry, '', $file, [ 'raw' => 1, ]);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertEquals($entry, $contents, 'the multibyte entry survives byte-for-byte');
        $this->assertNotFalse(mb_check_encoding($contents, 'UTF-8'), 'still valid UTF-8');

        unlink($file);
    }

    public function testMsgFallsBackAndReturnsEmptyWhenFileWriteFails()
    {
        // An existing DIRECTORY as the target "file" — the write must fail.
        $bad_target_dir = sys_get_temp_dir() . '/dj_log_bad_' . getmypid() . '_' . uniqid();
        $fallback_file = $this->tmpFile();

        $mkdir_res = Dj_App_File_Util::mkdir($bad_target_dir);
        $this->assertFalse($mkdir_res->isError(), 'Failed to create the directory fixture');

        try {
            $prior_error_log = ini_get('error_log');
            $prior_error_reporting = error_reporting();

            // Capture PHP's default error log (the fallback destination) and
            // silence the expected warnings from the failing file attempts.
            ini_set('error_log', $fallback_file);
            error_reporting(0);

            $line = Dj_App_Log::msg('lost? never', '', $bad_target_dir);
        } finally {
            error_reporting($prior_error_reporting);
            ini_set('error_log', $prior_error_log);
        }

        $this->assertEmpty($line, 'a failed file write returns an empty line');
        $this->assertFileExists($fallback_file, 'the entry fell back to the default error log');

        $read_res = Dj_App_File_Util::read($fallback_file);
        $this->assertStringContainsString('lost? never', $read_res->output, 'the entry is never lost');

        unlink($fallback_file);
        Dj_App_File_Util::rmdir($bad_target_dir);
    }

    public function testLogAppErrorWritesVerbatimToConfiguredFile()
    {
        $file = $this->tmpFile();

        $entry = "[2026-08-05 00:00:00] Exception: boom in /x.php on line 1\n";

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $log_ok = Dj_App_Log::logAppError($entry);

        $this->assertTrue($log_ok, 'the entry landed in the app error log');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'the entry is written verbatim — full paths intact');

        unlink($file);
    }

    public function testMsgSmartThirdArgTakesOptionsArray()
    {
        $file = $this->tmpFile();

        // Pin the default log file, then pass the OPTIONS as the 3rd arg — the
        // smart slot means no '' file placeholder is needed.
        Dj_App_Log::file($file);

        $entry = "verbatim via smart arg\n";

        $line = Dj_App_Log::msg($entry, '', [ 'raw' => 1, ]);

        $this->assertEquals($entry, $line, 'the options array is recognized in the 3rd slot');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'the entry went to the default log file, raw');

        unlink($file);
    }

    public function testMsgOptionsCarryTheTargetFile()
    {
        $file = $this->tmpFile();

        $entry = "verbatim via options file\n";

        Dj_App_Log::msg($entry, '', [ 'raw' => 1, 'file' => $file, ]);

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, "the 'file' options key targets the file — no pin, no placeholder");

        unlink($file);
    }

    public function testLogAppErrorBlankFileFallsBackToDefaultErrorLog()
    {
        $fallback_file = $this->tmpFile();

        $entry = "[2026-08-05 00:00:00] Exception: still logged\n";

        // A BLANKED app.error_log_file must not drop the entry — it degrades
        // to PHP's default error log (captured here in a scratch file).
        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', '');

        $prior_error_log = ini_get('error_log');
        ini_set('error_log', $fallback_file);

        $log_ok = Dj_App_Log::logAppError($entry);

        ini_set('error_log', $prior_error_log);

        $this->assertTrue($log_ok, 'the entry was still logged');
        $this->assertFileExists($fallback_file, 'the entry landed in the default error log');

        $read_res = Dj_App_File_Util::read($fallback_file);
        $this->assertStringContainsString('still logged', $read_res->output, 'the entry is never lost');

        unlink($fallback_file);
    }

    public function testLogAppErrorFormatsAThrowable()
    {
        $file = $this->tmpFile();

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $log_ok = Dj_App_Log::logAppError(new Exception('boom'));

        $this->assertTrue($log_ok, 'the exception was logged');

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('Exception: boom', $contents, 'the logger built the entry from the Throwable');
        $this->assertStringContainsString(' on line ', $contents, 'file + line came from the Throwable');
        $this->assertStringContainsString('Stack trace:', $contents, 'the trace is part of the entry');
        $this->assertStringContainsString(str_repeat('-', 80), $contents, 'entries stay separator-delimited');

        unlink($file);
    }

    public function testLogAppErrorFormatsAFatalErrorArray()
    {
        $file = $this->tmpFile();

        $error = [ 'type' => E_ERROR, 'message' => 'oom', 'file' => '/x.php', 'line' => 7, ];

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $log_ok = Dj_App_Log::logAppError($error);

        $this->assertTrue($log_ok, 'the fatal was logged');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertStringContainsString('Fatal Error: oom in /x.php on line 7', $read_res->output, 'the logger built the entry from the error_get_last() array');

        unlink($file);
    }

    public function testLogAppErrorExtractsACarriedException()
    {
        $file = $this->tmpFile();

        $res_obj = new Dj_App_Result();
        $res_obj->exception = new Exception('carried by result');

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        Dj_App_Log::logAppError([ 'exception' => new Exception('carried by array'), ]);
        Dj_App_Log::logAppError($res_obj);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('Exception: carried by array', $contents, "the 'exception' array key is unwrapped");
        $this->assertStringContainsString('Exception: carried by result', $contents, 'a result obj carrying the exception is unwrapped');

        unlink($file);
    }

    public function testLogAppErrorHonorsErrorLoggingDisabled()
    {
        $file = $this->tmpFile();

        Dj_App_Env::set([
            'DJEBEL_APP_ERROR_LOGGING' => '0',
            'DJEBEL_APP_ERROR_LOG_FILE' => $file,
        ]);

        $log_ok = Dj_App_Log::logAppError("nope\n");

        $this->assertFalse($log_ok, 'disabled error logging refuses the write');
        $this->assertFileDoesNotExist($file, 'nothing is written when disabled');
    }

    public function testLogAppErrorBlankGateValueStillLogs()
    {
        $file = $this->tmpFile();

        // A BLANK gate value is not an explicit disable — the critical
        // facility stays ON; only 0/false/off/no really turn it off.
        Dj_App_Env::set([
            'DJEBEL_APP_ERROR_LOGGING' => '',
            'DJEBEL_APP_ERROR_LOG_FILE' => $file,
        ]);

        $log_ok = Dj_App_Log::logAppError("still on\n");

        $this->assertTrue($log_ok, 'a blank gate value does not kill error logging');
        $this->assertFileExists($file, 'the entry was written');

        unlink($file);
    }
}
