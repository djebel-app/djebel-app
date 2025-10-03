<?php

use PHPUnit\Framework\TestCase;

class File_Util_Test extends TestCase {

    private $test_dir;

    public function setUp() : void {
        $this->test_dir = sys_get_temp_dir() . '/djebel_file_util_test_' . uniqid();
        mkdir($this->test_dir, 0755, true);
    }

    public function tearDown() : void {
        if (is_dir($this->test_dir)) {
            $files = glob($this->test_dir . '/*');

            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }

            rmdir($this->test_dir);
        }
    }

    public function testReadPartiallySmallFile()
    {
        $test_file = $this->test_dir . '/small.txt';
        $content = 'Hello World';
        file_put_contents($test_file, $content);

        $res_obj = Dj_App_File_Util::readPartially($test_file, 1024);

        $this->assertTrue($res_obj->status);
        $this->assertEquals($content, $res_obj->output);
    }

    public function testReadPartiallyLargeFile()
    {
        $test_file = $this->test_dir . '/large.txt';
        $content = str_repeat('A', 50000); // 50KB
        file_put_contents($test_file, $content);

        $res_obj = Dj_App_File_Util::readPartially($test_file, 1024 * 1024); // 1MB limit

        $this->assertTrue($res_obj->status);
        $this->assertEquals($content, $res_obj->output);
        $this->assertEquals(50000, strlen($res_obj->output));
    }

    public function testReadPartiallyWithLimit()
    {
        $test_file = $this->test_dir . '/limited.txt';
        $content = str_repeat('B', 20000); // 20KB
        file_put_contents($test_file, $content);

        $res_obj = Dj_App_File_Util::readPartially($test_file, 10000); // Read only 10KB

        $this->assertTrue($res_obj->status);
        $this->assertEquals(10000, strlen($res_obj->output));
        $this->assertEquals(str_repeat('B', 10000), $res_obj->output);
    }

    public function testReadPartiallyWithSeek()
    {
        $test_file = $this->test_dir . '/seek.txt';
        $content = '0123456789ABCDEFGHIJ';
        file_put_contents($test_file, $content);

        $res_obj = Dj_App_File_Util::readPartially($test_file, 5, 10);

        $this->assertTrue($res_obj->status);
        $this->assertEquals('ABCDE', $res_obj->output);
    }

    public function testReadPartiallyFileNotFound()
    {
        $test_file = $this->test_dir . '/nonexistent.txt';

        $res_obj = Dj_App_File_Util::readPartially($test_file, 1024);

        $this->assertTrue($res_obj->isError());
    }

    public function testReadFullFile()
    {
        $test_file = $this->test_dir . '/full.txt';
        $content = 'Full file content';
        file_put_contents($test_file, $content);

        $result = Dj_App_File_Util::read($test_file);

        $this->assertEquals($content, $result);
    }

    public function testReadFileNotFound()
    {
        $test_file = $this->test_dir . '/missing.txt';

        $result = Dj_App_File_Util::read($test_file);

        $this->assertFalse($result);
    }

    public function testReadLargeFileWithChunking()
    {
        $test_file = $this->test_dir . '/chunked.txt';
        $content = str_repeat('X', 100000); // 100KB
        file_put_contents($test_file, $content);

        $result = Dj_App_File_Util::read($test_file);

        $this->assertEquals($content, $result);
        $this->assertEquals(100000, strlen($result));
    }
}
