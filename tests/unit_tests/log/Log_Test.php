<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Log_Test extends TestCase {

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

        $contents = file_get_contents($file);
        $this->assertStringContainsString('hello world', $contents);
        $this->assertStringContainsString('MYLABEL', $contents);

        @unlink($file);
    }

    public function testLevelsPrefixTheMessage()
    {
        $file = $this->tmpFile();

        Dj_App_Log::error('boom', '', $file);
        Dj_App_Log::info('note', '', $file);
        Dj_App_Log::warn('careful', '', $file);

        $contents = file_get_contents($file);
        $this->assertStringContainsString('[ERROR] boom', $contents);
        $this->assertStringContainsString('[INFO] note', $contents);
        $this->assertStringContainsString('[WARN] careful', $contents);

        @unlink($file);
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

        @unlink($file);
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
}
