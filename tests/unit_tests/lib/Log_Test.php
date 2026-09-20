<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Log_Test extends TestCase {

    private $backup_error_logging = false;
    private $backup_error_log_file = false;
    private $backup_djebel_env = false;
    private $backup_app_env = false;

    protected function setUp(): void
    {
        $this->backup_error_logging = getenv('DJEBEL_APP_ERROR_LOGGING');
        $this->backup_error_log_file = getenv('DJEBEL_APP_ERROR_LOG_FILE');
        $this->backup_djebel_env = getenv('DJEBEL_APP_ENV');
        $this->backup_app_env = getenv('APP_ENV');

        putenv('DJEBEL_APP_ERROR_LOGGING');
        putenv('DJEBEL_APP_ERROR_LOG_FILE');

        // dump() gates on the environment, so each case declares its own. Cleared through the
        // framework rather than putenv: the resolved name is remembered for the request, and
        // only this path drops it. Both keys, since either can answer.
        Dj_App_Env::set('DJEBEL_APP_ENV', null);
        Dj_App_Env::set('APP_ENV', null);

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

    public function testMsgWritesTimestampedLabelledLine()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $line = Dj_App_Log::msg('hello world', 'MYLABEL', $file);

        $this->assertStringContainsString('MYLABEL', $line);
        $this->assertStringContainsString('hello world', $line);
        $this->assertFileExists($file);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('hello world', $contents);
        $this->assertStringContainsString('MYLABEL', $contents);

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLevelsPrefixTheMessage()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Log::error('boom', '', $file);
        Dj_App_Log::info('note', '', $file);
        Dj_App_Log::warn('careful', '', $file);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('[ERROR] boom', $contents);
        $this->assertStringContainsString('[INFO] note', $contents);
        $this->assertStringContainsString('[WARN] careful', $contents);

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testDisabledLoggingWritesNothing()
    {
        $file = Dj_App_File_Util::generateTempFile();

        try {
            $is_enabled = Dj_App_Log::loggingEnabled(0);

            $this->assertFalse($is_enabled);

            $res = Dj_App_Log::msg('should not write', '', $file);

            $this->assertEmpty($res);
            $this->assertFileDoesNotExist($file);
        } finally {
            // Restored in a finally: a failed assert above would otherwise leave logging off
            // for every test that runs after this one, and they would pass writing nothing.
            $is_enabled = Dj_App_Log::loggingEnabled(1);

            $this->assertTrue($is_enabled, 'logging stayed off after the test');
        }
    }

    public function testRequestIdTagsTheLine()
    {
        $file = Dj_App_File_Util::generateTempFile();

        // Captured above the try: the read resolves through a filter and can throw, and a
        // finally firing with this undefined would restore null over a live id.
        $saved_req_id = Dj_App_Util::reqId();

        try {
            $set_req_id = Dj_App_Util::reqId('req-abc');

            $this->assertEquals('req-abc', $set_req_id);

            $line = Dj_App_Log::msg('hi', 'L', $file);

            $this->assertStringContainsString('req-abc', $line);
        } finally {
            $restored_req_id = Dj_App_Util::reqId($saved_req_id);

            $this->assertEquals($saved_req_id, $restored_req_id, 'the test id leaked out of the test');
        }

        $delete_res = Dj_App_File_Util::delete($file);

        $this->assertFalse($delete_res->isError(), 'the temp log fixture was removed');
    }

    public function testFileHonorsExplicitFile()
    {
        $file = Dj_App_File_Util::generateTempFile();

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
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "[2026-08-05 00:00:00] Fatal Error: boom in /x.php on line 1\n" . str_repeat('-', 80) . "\n";

        $line = Dj_App_Log::msg($entry, '', $file, [ 'raw' => 1, ]);

        $this->assertEquals($entry, $line, 'raw mode returns the entry untouched');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'raw mode writes VERBATIM — no prefix, no extra newline');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testMsgRawKeepsMultibyteEntryIntact()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "[2026-08-05 00:00:00] Exception: Разбрах — тест\n";

        $line = Dj_App_Log::msg($entry, '', $file, [ 'raw' => 1, ]);

        $this->assertNotEmpty($line, 'the entry was written');

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertEquals($entry, $contents, 'the multibyte entry survives byte-for-byte');
        $this->assertNotFalse(mb_check_encoding($contents, 'UTF-8'), 'still valid UTF-8');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testMsgFallsBackAndReturnsEmptyWhenFileWriteFails()
    {
        // An existing DIRECTORY as the target "file" — the write must fail.
        $bad_target_opts = [
            'prefix' => 'dj_log_bad',
            'ext' => '',
        ];

        $bad_target_dir = Dj_App_File_Util::generateTempFile($bad_target_opts);
        $fallback_file = Dj_App_File_Util::generateTempFile();

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

        $delete_res = Dj_App_File_Util::delete($fallback_file);
        $this->assertFalse($delete_res->isError(), 'the fallback log fixture was removed');

        $rmdir_res = Dj_App_File_Util::rmdir($bad_target_dir);
        $this->assertFalse($rmdir_res->isError(), 'the directory fixture was removed');
    }

    /**
     * Pins that both ways of missing the target file answer alike, so a caller can never read
     * the answer as "the file has it" for an entry the file never received.
     */
    public function testMsgReturnsEmptyWhenTheLogDirCannotBeCreated()
    {
        // A FILE where the log's parent dir should be — no dir can be created under it.
        $blocker_file = Dj_App_File_Util::generateTempFile();
        $fallback_file = Dj_App_File_Util::generateTempFile();

        $write_res = Dj_App_File_Util::write($blocker_file, 'not a dir');
        $this->assertTrue($write_res->isSuccess(), 'Failed to write the blocker fixture');

        $target_file = $blocker_file . '/logs/app.log';

        try {
            $prior_error_log = ini_get('error_log');
            $prior_error_reporting = error_reporting();

            ini_set('error_log', $fallback_file);
            error_reporting(0);

            $line = Dj_App_Log::msg('no dir for me', '', $target_file);
        } finally {
            error_reporting($prior_error_reporting);
            ini_set('error_log', $prior_error_log);
        }

        $this->assertEmpty($line, 'an entry that missed the named file answers empty');
        $this->assertFileExists($fallback_file, 'the entry fell back to the default error log');

        $read_res = Dj_App_File_Util::read($fallback_file);
        $this->assertStringContainsString('no dir for me', $read_res->output, 'the entry is never lost');

        $delete_fallback_res = Dj_App_File_Util::delete($fallback_file);
        $this->assertFalse($delete_fallback_res->isError(), 'the fallback log fixture was removed');

        $delete_blocker_res = Dj_App_File_Util::delete($blocker_file);
        $this->assertFalse($delete_blocker_res->isError(), 'the blocker fixture was removed');
    }

    public function testLogAppErrorWritesVerbatimToConfiguredFile()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "[2026-08-05 00:00:00] Exception: boom in /x.php on line 1\n";

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $is_logged = Dj_App_Log::logAppError($entry);

        $this->assertTrue($is_logged, 'the entry landed in the app error log');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'the entry is written verbatim — full paths intact');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * An errno under 'type' labels the entry, so a warning is not filed as a fatal.
     */
    public function testLogAppErrorLabelsEntryByErrorType()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $error_data = [
            'type' => E_WARNING,
            'message' => 'something odd',
            'file' => '/x.php',
            'line' => 12,
        ];

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $is_logged = Dj_App_Log::logAppError($error_data);

        $this->assertTrue($is_logged, 'the warning landed in the app error log');

        $read_res = Dj_App_File_Util::read($file);

        $this->assertStringContainsString('Warning: something odd', $read_res->output, 'labelled by errno, not as a fatal');
        $this->assertStringContainsString('/x.php on line 12', $read_res->output, 'file and line are kept');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * A typeless array is the pre-existing error_get_last() shape — it keeps the fatal label.
     */
    public function testLogAppErrorFallsBackToFatalLabelWithoutType()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $error_data = [
            'message' => 'legacy shape',
            'file' => '/y.php',
            'line' => 3,
        ];

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $is_logged = Dj_App_Log::logAppError($error_data);

        $this->assertTrue($is_logged, 'the entry still logs without a type');

        $read_res = Dj_App_File_Util::read($file);

        $this->assertStringContainsString('Fatal Error: legacy shape', $read_res->output, 'a typeless entry keeps the fatal label');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * The error handler routes a warning into the app log and still defers to PHP, so
     * whatever logs today keeps logging.
     */
    public function testHandleErrorLogsWarningAndDefersToPhp()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $handled = Dj_App_Bootstrap::handleError(E_USER_WARNING, 'handler routed', '/z.php', 7);

        $this->assertFalse($handled, 'returns false so PHP still runs its own handling');

        $read_res = Dj_App_File_Util::read($file);

        $this->assertStringContainsString('User Warning: handler routed', $read_res->output, 'the warning reached the app log');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    /**
     * A diagnostic the site chose not to report must not reach the log either.
     */
    public function testHandleErrorRespectsErrorReporting()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        try {
            $reporting_level = error_reporting();

            error_reporting(E_ALL & ~E_USER_NOTICE);

            $handled = Dj_App_Bootstrap::handleError(E_USER_NOTICE, 'muted', '/z.php', 9);
        } finally {
            error_reporting($reporting_level);
        }

        $this->assertFalse($handled, 'still defers to PHP');
        $this->assertFileDoesNotExist($file, 'a muted diagnostic never reaches the log');
    }

    public function testMsgSmartThirdArgTakesOptionsArray()
    {
        $file = Dj_App_File_Util::generateTempFile();

        // Pin the default log file, then pass the OPTIONS as the 3rd arg — the
        // smart slot means no '' file placeholder is needed.
        $pinned_file = Dj_App_Log::file($file);

        $this->assertEquals($file, $pinned_file, 'the default log file was pinned');

        $entry = "verbatim via smart arg\n";

        $line = Dj_App_Log::msg($entry, '', [ 'raw' => 1, ]);

        $this->assertEquals($entry, $line, 'the options array is recognized in the 3rd slot');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'the entry went to the default log file, raw');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testMsgOptionsCarryTheTargetFile()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "verbatim via options file\n";

        $line = Dj_App_Log::msg($entry, '', [ 'raw' => 1, 'file' => $file, ]);

        $this->assertNotEmpty($line, 'the entry was written');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, "the 'file' options key targets the file — no pin, no placeholder");

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLogAppErrorBlankFileFallsBackToDefaultErrorLog()
    {
        $fallback_file = Dj_App_File_Util::generateTempFile();

        $entry = "[2026-08-05 00:00:00] Exception: still logged\n";

        // A BLANKED app.error_log_file must not drop the entry — it degrades
        // to PHP's default error log (captured here in a scratch file).
        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', '');

        $prior_error_log = ini_get('error_log');
        ini_set('error_log', $fallback_file);

        $is_logged = Dj_App_Log::logAppError($entry);

        ini_set('error_log', $prior_error_log);

        $this->assertTrue($is_logged, 'the entry was still logged');
        $this->assertFileExists($fallback_file, 'the entry landed in the default error log');

        $read_res = Dj_App_File_Util::read($fallback_file);
        $this->assertStringContainsString('still logged', $read_res->output, 'the entry is never lost');

        $delete_res = Dj_App_File_Util::delete($fallback_file);
        $this->assertFalse($delete_res->isError(), 'the fallback log fixture was removed');
    }

    public function testLogAppErrorFormatsAThrowable()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $is_logged = Dj_App_Log::logAppError(new Exception('boom'));

        $this->assertTrue($is_logged, 'the exception was logged');

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('Exception: boom', $contents, 'the logger built the entry from the Throwable');
        $this->assertStringContainsString(' on line ', $contents, 'file + line came from the Throwable');
        $this->assertStringContainsString('Stack trace:', $contents, 'the trace is part of the entry');
        $this->assertStringContainsString(str_repeat('-', 80), $contents, 'entries stay separator-delimited');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLogAppErrorFormatsAFatalErrorArray()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $error = [ 'type' => E_ERROR, 'message' => 'oom', 'file' => '/x.php', 'line' => 7, ];

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $is_logged = Dj_App_Log::logAppError($error);

        $this->assertTrue($is_logged, 'the fatal was logged');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertStringContainsString('Fatal Error: oom in /x.php on line 7', $read_res->output, 'the logger built the entry from the error_get_last() array');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLogAppErrorExtractsACarriedException()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $res_obj = new Dj_App_Result();
        $res_obj->exception = new Exception('carried by result');

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        Dj_App_Log::logAppError([ 'exception' => new Exception('carried by array'), ]);
        Dj_App_Log::logAppError($res_obj);

        $read_res = Dj_App_File_Util::read($file);
        $contents = $read_res->output;
        $this->assertStringContainsString('Exception: carried by array', $contents, "the 'exception' array key is unwrapped");
        $this->assertStringContainsString('Exception: carried by result', $contents, 'a result obj carrying the exception is unwrapped');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }

    public function testLogAppErrorHonorsErrorLoggingDisabled()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Env::set([
            'DJEBEL_APP_ERROR_LOGGING' => '0',
            'DJEBEL_APP_ERROR_LOG_FILE' => $file,
        ]);

        $is_logged = Dj_App_Log::logAppError("nope\n");

        $this->assertFalse($is_logged, 'disabled error logging refuses the write');
        $this->assertFileDoesNotExist($file, 'nothing is written when disabled');
    }

    /**
     * Pins false for an entry that missed the app error log file, so "logged" never covers an
     * entry that is not where whoever reads that log will look for it.
     */
    public function testLogAppErrorReturnsFalseWhenTheFileCannotBeWritten()
    {
        // An existing DIRECTORY as the target "file" — the write must fail.
        $bad_target_opts = [
            'prefix' => 'dj_log_bad',
            'ext' => '',
        ];

        $bad_target_dir = Dj_App_File_Util::generateTempFile($bad_target_opts);
        $fallback_file = Dj_App_File_Util::generateTempFile();

        $mkdir_res = Dj_App_File_Util::mkdir($bad_target_dir);
        $this->assertFalse($mkdir_res->isError(), 'Failed to create the directory fixture');

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $bad_target_dir);

        try {
            $prior_error_log = ini_get('error_log');
            $prior_error_reporting = error_reporting();

            ini_set('error_log', $fallback_file);
            error_reporting(0);

            $is_logged = Dj_App_Log::logAppError(new Exception('missed the app log'));
        } finally {
            error_reporting($prior_error_reporting);
            ini_set('error_log', $prior_error_log);
        }

        $this->assertFalse($is_logged, 'an entry outside the app error log is not reported as logged');

        $read_res = Dj_App_File_Util::read($fallback_file);
        $this->assertStringContainsString('missed the app log', $read_res->output, 'the entry is never lost');

        $delete_fallback_res = Dj_App_File_Util::delete($fallback_file);
        $this->assertFalse($delete_fallback_res->isError(), 'the fallback log fixture was removed');

        $rmdir_res = Dj_App_File_Util::rmdir($bad_target_dir);
        $this->assertFalse($rmdir_res->isError(), 'the directory fixture was removed');
    }

    public function testLogAppErrorBlankGateValueStillLogs()
    {
        $file = Dj_App_File_Util::generateTempFile();

        // A BLANK gate value is not an explicit disable — the critical
        // facility stays ON; only 0/false/off/no really turn it off.
        Dj_App_Env::set([
            'DJEBEL_APP_ERROR_LOGGING' => '',
            'DJEBEL_APP_ERROR_LOG_FILE' => $file,
        ]);

        $is_logged = Dj_App_Log::logAppError("still on\n");

        $this->assertTrue($is_logged, 'a blank gate value does not kill error logging');
        $this->assertFileExists($file, 'the entry was written');

        $delete_res = Dj_App_File_Util::delete($file);
        $this->assertFalse($delete_res->isError(), 'the log fixture was removed');
    }
}
