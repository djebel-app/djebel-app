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
            $this->removeDirectory($this->test_dir);
        }
    }

    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }

        $scan_result = scandir($dir);
        $exclude_items = [ '.', '..', ];
        $files = array_diff($scan_result, $exclude_items);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;

            if (is_dir($path)) {
                $remove_res = $this->removeDirectory($path);

                if (!$remove_res) {
                    return false;
                }
            } else {
                $unlink_res = unlink($path);

                if (!$unlink_res) {
                    return false;
                }
            }
        }

        $rmdir_res = rmdir($dir);

        return $rmdir_res;
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

        $this->assertTrue($result->isSuccess());
        $this->assertEquals($content, $result->output);
    }

    public function testReadFileNotFound()
    {
        $test_file = $this->test_dir . '/missing.txt';

        $result = Dj_App_File_Util::read($test_file);

        $this->assertTrue($result->isError());
    }

    public function testReadLargeFileWithChunking()
    {
        $test_file = $this->test_dir . '/chunked.txt';
        $content = str_repeat('X', 100000); // 100KB
        file_put_contents($test_file, $content);

        $result = Dj_App_File_Util::read($test_file);

        $this->assertTrue($result->isSuccess());
        $this->assertEquals($content, $result->output);
        $this->assertEquals(100000, strlen($result->output));
    }

    public function testWriteNewFile()
    {
        $test_file = $this->test_dir . '/write_new.txt';
        $content = 'New file content';

        $res_obj = Dj_App_File_Util::write($test_file, $content);

        $this->assertTrue($res_obj->status);
        $this->assertFileExists($test_file);
        $this->assertEquals($content, file_get_contents($test_file));
    }

    public function testWriteExistingFile()
    {
        $test_file = $this->test_dir . '/write_existing.txt';
        file_put_contents($test_file, 'Original content');
        chmod($test_file, 0644);

        $new_content = 'Updated content';
        $res_obj = Dj_App_File_Util::write($test_file, $new_content);

        $this->assertTrue($res_obj->status);
        $this->assertEquals($new_content, file_get_contents($test_file));

        // Verify permissions preserved
        $perms = fileperms($test_file) & 0777;
        $this->assertEquals(0644, $perms);
    }

    public function testWriteAppendMode()
    {
        $test_file = $this->test_dir . '/write_append.txt';
        file_put_contents($test_file, 'Line 1' . "\n");

        $append_content = 'Line 2' . "\n";
        $write_params = ['flags' => FILE_APPEND];
        $res_obj = Dj_App_File_Util::write($test_file, $append_content, $write_params);

        $this->assertTrue($res_obj->status);
        $expected = 'Line 1' . "\n" . 'Line 2' . "\n";
        $this->assertEquals($expected, file_get_contents($test_file));
    }

    public function testWriteArrayData()
    {
        $test_file = $this->test_dir . '/write_array.json';
        $data = ['key1' => 'value1', 'key2' => 'value2'];

        $res_obj = Dj_App_File_Util::write($test_file, $data);

        $this->assertTrue($res_obj->status);
        $content = file_get_contents($test_file);
        $decoded = json_decode($content, true);
        $this->assertEquals($data, $decoded);
    }

    public function testWriteCreatesDirectory()
    {
        $test_subdir = $this->test_dir . '/subdir/nested';
        $test_file = $test_subdir . '/file.txt';
        $content = 'Content in nested dir';

        $res_obj = Dj_App_File_Util::write($test_file, $content);

        $this->assertTrue($res_obj->status);
        $this->assertDirectoryExists($test_subdir);
        $this->assertFileExists($test_file);
        $this->assertEquals($content, file_get_contents($test_file));
    }

    public function testWriteTempFileCleanup()
    {
        $test_file = $this->test_dir . '/temp_cleanup.txt';
        file_put_contents($test_file, 'Original');

        $content = 'Updated';
        $res_obj = Dj_App_File_Util::write($test_file, $content);

        $this->assertTrue($res_obj->status);

        // Verify no temp files left behind
        $temp_files = glob($this->test_dir . '/*.dj_tmp.*');
        $this->assertEmpty($temp_files, 'Temp files should be cleaned up');
    }

    public function testMkdirNewDirectory()
    {
        $test_subdir = $this->test_dir . '/new_dir';

        $res_obj = Dj_App_File_Util::mkdir($test_subdir);

        $this->assertTrue($res_obj->status);
        $this->assertDirectoryExists($test_subdir);
    }

    public function testMkdirExistingDirectory()
    {
        $test_subdir = $this->test_dir . '/existing_dir';
        mkdir($test_subdir, 0755);

        $res_obj = Dj_App_File_Util::mkdir($test_subdir);

        $this->assertTrue($res_obj->status);
        $this->assertDirectoryExists($test_subdir);
    }

    public function testMkdirNestedDirectories()
    {
        $test_nested = $this->test_dir . '/level1/level2/level3';

        $res_obj = Dj_App_File_Util::mkdir($test_nested);

        $this->assertTrue($res_obj->status);
        $this->assertDirectoryExists($test_nested);
    }

    public function testMkdirWithPermissions()
    {
        $test_subdir = $this->test_dir . '/perm_dir';

        $res_obj = Dj_App_File_Util::mkdir($test_subdir, 0755);

        $this->assertTrue($res_obj->status);
        $this->assertDirectoryExists($test_subdir);

        $perms = fileperms($test_subdir) & 0777;
        $this->assertEquals(0755, $perms);
    }

    public function testNormalizePathBackslashes()
    {
        $path = 'C:\\Users\\test\\file.txt';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('C:/Users/test/file.txt', $result);
    }

    public function testNormalizePathMultipleSlashes()
    {
        $path = '/path//to///file.txt';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('/path/to/file.txt', $result);
    }

    public function testNormalizePathEmptyString()
    {
        $path = '';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEmpty($result);
    }

    public function testNormalizePathNull()
    {
        $path = null;
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEmpty($result);
    }

    public function testNormalizePathTrimSpaces()
    {
        $path = '  /path/to/file.txt  ';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('/path/to/file.txt', $result);
    }

    public function testNormalizePathRemoveTrailingSlash()
    {
        $path = '/path/to/directory/';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('/path/to/directory', $result);
    }

    public function testNormalizePathRootSlash()
    {
        $path = '/';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('/', $result);
    }

    public function testNormalizePathMixedSlashes()
    {
        $path = 'C:\\path/to\\file.txt';
        $result = Dj_App_File_Util::normalizePath($path);
        $this->assertEquals('C:/path/to/file.txt', $result);
    }

    public function testRemoveExtSimpleFilename()
    {
        $path = 'file.md';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEquals('file', $result);
    }

    public function testRemoveExtFullPath()
    {
        $path = '/path/to/file.php';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEquals('/path/to/file', $result);
    }

    public function testRemoveExtMultipleDots()
    {
        $path = 'file.tar.gz';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEquals('file.tar', $result);
    }

    public function testRemoveExtNoExtension()
    {
        $path = 'file';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEquals('file', $result);
    }

    public function testRemoveExtEmptyString()
    {
        $path = '';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEmpty($result);
    }

    public function testRemoveExtDotFile()
    {
        $path = '.htaccess';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEmpty($result);
    }

    public function testRemoveExtPathWithDotFile()
    {
        $path = '/etc/.htaccess';
        $result = Dj_App_File_Util::removeExt($path);
        $this->assertEquals('/etc/', $result);
    }

    public function testRemoveExtDifferentExtensions()
    {
        $test_cases = [
            'script.js' => 'script',
            'style.css' => 'style',
            'image.png' => 'image',
            'doc.pdf' => 'doc',
            'archive.zip' => 'archive',
        ];

        foreach ($test_cases as $input => $expected) {
            $result = Dj_App_File_Util::removeExt($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }
}
