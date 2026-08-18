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

        unlink($file);
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

        unlink($file);
    }

    public function testDisabledLoggingWritesNothing()
    {
        $file = Dj_App_File_Util::generateTempFile();

        Dj_App_Log::disableLogging();
        $res = Dj_App_Log::msg('should not write', '', $file);
        Dj_App_Log::enableLogging();

        $this->assertEmpty($res);
        $this->assertFileDoesNotExist($file);
    }

    /**
     * NESTED data flattens to a[b]=c rather than being dumped, so a record keeps its one
     * compact line however deep it goes.
     */
    public function testPrepMsgFlattensNested()
    {
        $out = Dj_App_Log::prepMsg([ 'a' => [ 'b' => 1, ], ]);

        $this->assertEquals('a[b]=1', $out);
        $this->assertStringNotContainsString("\n", $out);
    }

    /**
     * A FLAT array is a log record: it renders as ONE compact key=value line, so a caller
     * never builds that string itself and the file stays greppable.
     */
    public function testPrepMsgRendersFlatArrayAsOneLine()
    {
        $log_data = [
            'endpoint' => '/admin/vehicles/get',
            'code' => 'admin.vehicles.get.not_found',
            'msg' => 'Vehicle not found',
        ];

        $out = Dj_App_Log::prepMsg($log_data);
        $expected = 'endpoint=/admin/vehicles/get code=admin.vehicles.get.not_found msg=Vehicle not found';

        $this->assertEquals($expected, $out);
        $this->assertStringNotContainsString("\n", $out);
    }

    /**
     * A record mixing scalars with a nested value stays on ONE line — the scalars render
     * normally and the nested part carries its own bracketed key.
     */
    public function testPrepMsgFlattensRecordMixingScalarAndNested()
    {
        $log_data = [
            'code' => 'x.not_found',
            'params' => [ 'id' => 5, ],
        ];

        $out = Dj_App_Log::prepMsg($log_data);

        $this->assertEquals('code=x.not_found params[id]=5', $out);
    }

    /**
     * Booleans render as 1 / 0, which is what the query builder emits.
     */
    public function testPrepMsgRendersBooleansAsDigits()
    {
        $log_data = [
            'cached' => true,
            'stale' => false,
        ];

        $out = Dj_App_Log::prepMsg($log_data);

        $this->assertEquals('cached=1 stale=0', $out);
    }

    /**
     * A null value is DROPPED rather than logged as an empty pair — an absent field says
     * the same thing as an empty one, without the noise.
     */
    public function testPrepMsgDropsNullValue()
    {
        $log_data = [
            'code' => null,
            'msg' => 'boom',
        ];

        $out = Dj_App_Log::prepMsg($log_data);

        $this->assertEquals('msg=boom', $out);
    }

    /**
     * An empty array has no pairs, so it renders as an empty string.
     */
    public function testPrepMsgRendersEmptyArrayAsEmptyString()
    {
        $out = Dj_App_Log::prepMsg([]);

        $this->assertEmpty($out);
    }

    /**
     * An OBJECT contributes only its PUBLIC properties. That is the whole reason it goes
     * through the query builder rather than an (array) cast: the cast exports private
     * properties under NUL-mangled keys, which would put internal state and raw NUL bytes
     * into the log while looking like clean data.
     */
    public function testPrepMsgRendersObjectPublicPropertiesOnly()
    {
        $res_obj = new Dj_App_Result();
        $res_obj->code = 'app.access_denied';

        $out = Dj_App_Log::prepMsg($res_obj);

        $this->assertStringContainsString('code=app.access_denied', $out);
        $this->assertStringNotContainsString('expected_system_keys_regex', $out);
        $this->assertStringNotContainsString("\0", $out);
        $this->assertStringNotContainsString("\n", $out);
    }

    /**
     * null and resources are neither array nor object, so the builder would reject them —
     * they keep the dump instead of throwing.
     */
    public function testPrepMsgDumpsValueTheBuilderCannotTake()
    {
        $out = Dj_App_Log::prepMsg(null);

        $this->assertNotEmpty($out);
    }

    /**
     * A scalar is the message itself and passes through untouched.
     */
    public function testPrepMsgLeavesScalarsAlone()
    {
        $this->assertEquals('plain message', Dj_App_Log::prepMsg('plain message'));
        $this->assertEquals(42, Dj_App_Log::prepMsg(42));
    }

    public function testRequestIdTagsTheLine()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setRequestId('req-abc');
        $line = Dj_App_Log::msg('hi', 'L', $file);
        $req_obj->setRequestId('');

        $this->assertStringContainsString('req-abc', $line);

        unlink($file);
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

        unlink($file);
    }

    public function testMsgRawKeepsMultibyteEntryIntact()
    {
        $file = Dj_App_File_Util::generateTempFile();

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

        unlink($fallback_file);
        Dj_App_File_Util::rmdir($bad_target_dir);
    }

    public function testLogAppErrorWritesVerbatimToConfiguredFile()
    {
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "[2026-08-05 00:00:00] Exception: boom in /x.php on line 1\n";

        Dj_App_Env::set('DJEBEL_APP_ERROR_LOG_FILE', $file);

        $log_ok = Dj_App_Log::logAppError($entry);

        $this->assertTrue($log_ok, 'the entry landed in the app error log');

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, 'the entry is written verbatim — full paths intact');

        unlink($file);
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

        $log_ok = Dj_App_Log::logAppError($error_data);

        $this->assertTrue($log_ok, 'the warning landed in the app error log');

        $read_res = Dj_App_File_Util::read($file);

        $this->assertStringContainsString('Warning: something odd', $read_res->output, 'labelled by errno, not as a fatal');
        $this->assertStringContainsString('/x.php on line 12', $read_res->output, 'file and line are kept');

        unlink($file);
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

        $log_ok = Dj_App_Log::logAppError($error_data);

        $this->assertTrue($log_ok, 'the entry still logs without a type');

        $read_res = Dj_App_File_Util::read($file);

        $this->assertStringContainsString('Fatal Error: legacy shape', $read_res->output, 'a typeless entry keeps the fatal label');

        unlink($file);
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

        unlink($file);
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
        $file = Dj_App_File_Util::generateTempFile();

        $entry = "verbatim via options file\n";

        Dj_App_Log::msg($entry, '', [ 'raw' => 1, 'file' => $file, ]);

        $read_res = Dj_App_File_Util::read($file);
        $this->assertEquals($entry, $read_res->output, "the 'file' options key targets the file — no pin, no placeholder");

        unlink($file);
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
        $file = Dj_App_File_Util::generateTempFile();

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
        $file = Dj_App_File_Util::generateTempFile();

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

        unlink($file);
    }

    public function testLogAppErrorHonorsErrorLoggingDisabled()
    {
        $file = Dj_App_File_Util::generateTempFile();

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
        $file = Dj_App_File_Util::generateTempFile();

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
