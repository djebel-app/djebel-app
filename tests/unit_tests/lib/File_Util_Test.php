<?php

use PHPUnit\Framework\TestCase;

class Dj_App_File_Util_Test extends TestCase {
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

    public function testGetExtSimpleFilename()
    {
        $path = 'file.md';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEquals('md', $result);
    }

    public function testGetExtUppercase()
    {
        $path = 'file.MD';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEquals('md', $result);
    }

    public function testGetExtFullPath()
    {
        $path = '/path/to/file.PHP';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEquals('php', $result);
    }

    public function testGetExtMultipleDots()
    {
        $path = 'file.tar.gz';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEquals('gz', $result);
    }

    public function testGetExtNoExtension()
    {
        $path = 'file';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEmpty($result);
    }

    public function testGetExtEmptyString()
    {
        $path = '';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEmpty($result);
    }

    public function testGetExtDotFile()
    {
        $path = '.htaccess';
        $result = Dj_App_File_Util::getExt($path);
        $this->assertEquals('htaccess', $result);
    }

    public function testGetExtDifferentExtensions()
    {
        $test_cases = [
            'script.JS' => 'js',
            'style.CSS' => 'css',
            'image.PNG' => 'png',
            'doc.Pdf' => 'pdf',
            'archive.ZIP' => 'zip',
        ];

        foreach ($test_cases as $input => $expected) {
            $result = Dj_App_File_Util::getExt($input);
            $this->assertEquals($expected, $result, "Failed for: {$input}");
        }
    }

    public function testGetBasenameFullPath()
    {
        $path = '/path/to/file.php';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEquals('file.php', $result);
    }

    public function testGetBasenameFilenameOnly()
    {
        $path = 'file.md';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEquals('file.md', $result);
    }

    public function testGetBasenameTrailingSlash()
    {
        $path = '/path/to/';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEquals('to', $result);
    }

    public function testGetBasenameEmptyString()
    {
        $path = '';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEmpty($result);
    }

    public function testGetBasenameDotFile()
    {
        $path = '/etc/.htaccess';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEquals('.htaccess', $result);
    }

    public function testGetBasenameWindowsPath()
    {
        $path = 'C:\\Users\\test\\file.txt';
        $result = Dj_App_File_Util::getBasename($path);
        $this->assertEquals('file.txt', $result);
    }

    public function testNormalizeExtJpeg()
    {
        $ext = 'jpeg';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEquals('jpg', $result);
    }

    public function testNormalizeExtJpegUppercase()
    {
        $ext = 'JPEG';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEquals('jpg', $result);
    }

    public function testNormalizeExtJpg()
    {
        $ext = 'jpg';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEquals('jpg', $result);
    }

    public function testNormalizeExtOther()
    {
        $ext = 'png';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEquals('png', $result);
    }

    public function testNormalizeExtEmpty()
    {
        $ext = '';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEmpty($result);
    }

    public function testNormalizeExtMixedCase()
    {
        $ext = 'JpEg';
        $result = Dj_App_File_Util::normalizeExt($ext);
        $this->assertEquals('jpg', $result);
    }

    public function testFormatSizeBytes() {
        $this->assertEquals('0 B', Dj_App_File_Util::formatSize(0));
        $this->assertEquals('512 B', Dj_App_File_Util::formatSize(512));
        $this->assertEquals('1023 B', Dj_App_File_Util::formatSize(1023));
    }

    public function testFormatSizeKilobytes() {
        // Exact unit boundary.
        $this->assertEquals('1 KB', Dj_App_File_Util::formatSize(1024));
        $this->assertEquals('1.5 KB', Dj_App_File_Util::formatSize(1536));
        $this->assertEquals('8 KB', Dj_App_File_Util::formatSize(8192));
    }

    public function testFormatSizeMegabytes() {
        // Exact unit boundary.
        $this->assertEquals('1 MB', Dj_App_File_Util::formatSize(1048576));
        $this->assertEquals('10 MB', Dj_App_File_Util::formatSize(10485760));

        // A typical desktop build artifact lands in the tens of MB.
        $this->assertEquals('36.9 MB', Dj_App_File_Util::formatSize(38700000));
    }

    public function testFormatSizeGigabytes() {
        // Exact unit boundary.
        $this->assertEquals('1 GB', Dj_App_File_Util::formatSize(1073741824));
        $this->assertEquals('1.5 GB', Dj_App_File_Util::formatSize(1610612736));
        $this->assertEquals('2 GB', Dj_App_File_Util::formatSize(2147483648));
    }

    public function testFormatSizeCastsNumericStrings() {
        // Sizes read from JSON/config may arrive as numeric strings.
        $this->assertEquals('2 KB', Dj_App_File_Util::formatSize('2048'));
        $this->assertEquals('512 B', Dj_App_File_Util::formatSize('512'));
    }

    public function testRmdirRemovesNestedTree() {
        $dir = $this->test_dir . '/tree';
        mkdir($dir . '/a/b', 0755, true);
        file_put_contents($dir . '/top.txt', 'x');
        file_put_contents($dir . '/a/mid.txt', 'x');
        file_put_contents($dir . '/a/b/deep.txt', 'x');

        $res_obj = Dj_App_File_Util::rmdir($dir);

        $this->assertTrue($res_obj->isSuccess(), $res_obj->msg());
        $this->assertTrue($res_obj->deleted);
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testRmdirOnMissingDirReportsSuccessWithNothingDeleted() {
        $dir = $this->test_dir . '/never_existed';

        $res_obj = Dj_App_File_Util::rmdir($dir);

        // "make sure it is gone" is satisfied by it already being gone.
        $this->assertTrue($res_obj->isSuccess(), $res_obj->msg());
        $this->assertFalse($res_obj->deleted);
    }

    public function testRmdirUnlinksSymlinkWithoutDeletingItsTarget() {
        $keep_dir = $this->test_dir . '/keep';
        mkdir($keep_dir, 0755, true);
        file_put_contents($keep_dir . '/precious.txt', 'do not delete me');

        $doomed_dir = $this->test_dir . '/doomed';
        mkdir($doomed_dir, 0755, true);
        symlink($keep_dir, $doomed_dir . '/link_to_keep');

        $res_obj = Dj_App_File_Util::rmdir($doomed_dir);

        $this->assertTrue($res_obj->isSuccess(), $res_obj->msg());
        $this->assertDirectoryDoesNotExist($doomed_dir);

        // THE point of the test: following the link would have wiped this.
        $this->assertDirectoryExists($keep_dir);
        $this->assertFileExists($keep_dir . '/precious.txt');
    }

    public function testRmdirRefusesEmptyDir() {
        $res_obj = Dj_App_File_Util::rmdir('');

        $this->assertFalse($res_obj->isSuccess());
    }

    public function testRmdirRefusesFilesystemRoot() {
        $res_obj = Dj_App_File_Util::rmdir('/');

        $this->assertFalse($res_obj->isSuccess());
        $this->assertDirectoryExists('/');
    }

    public function testRmdirRefusesAFile() {
        $file = $this->test_dir . '/a_file.txt';
        file_put_contents($file, 'x');

        $res_obj = Dj_App_File_Util::rmdir($file);

        $this->assertFalse($res_obj->isSuccess());
        $this->assertFileExists($file);
    }

    /**
     * A dir holding one of each thing the filters have to tell apart.
     */
    private function seedListFilesDir() {
        mkdir($this->test_dir . '/1.0.0');
        mkdir($this->test_dir . '/2.1.0-rc.1');
        mkdir($this->test_dir . '/zzz_1.0.0.old.20260802-174338');

        file_put_contents($this->test_dir . '/a.zip', 'x');
        file_put_contents($this->test_dir . '/b.TXT', 'x');
        file_put_contents($this->test_dir . '/c.tar.xz', 'x');
        file_put_contents($this->test_dir . '/.hidden', 'x');
        file_put_contents($this->test_dir . '/d.zip.dj_upload.tmp', 'x');
    }

    /**
     * Named, because a closure is not allowed as a callback here.
     */
    public static function skipUploadTemps($name, $file) {
        $is_temp = strpos($name, '.dj_upload.') !== false;

        return $is_temp;
    }

    public function testListFilesReturnsEveryEntryKeyedByName() {
        $this->seedListFilesDir();

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertArrayHasKey('1.0.0', $res_obj->files);
        $this->assertArrayHasKey('a.zip', $res_obj->files);
        $this->assertSame($this->test_dir . '/a.zip', $res_obj->files['a.zip']);
    }

    /**
     * The distinction a plain array could not make: nothing to list is a SUCCESS, while
     * a dir that is not there is an ERROR. Confusing them hides a typo'd path.
     */
    public function testListFilesSeparatesEmptyFromMissing() {
        $empty_dir = $this->test_dir . '/empty';
        mkdir($empty_dir);

        $empty_res_obj = Dj_App_File_Util::listFiles($empty_dir);

        $this->assertTrue($empty_res_obj->isSuccess());
        $this->assertEmpty($empty_res_obj->files);

        $missing_res_obj = Dj_App_File_Util::listFiles($this->test_dir . '/nope');

        $this->assertFalse($missing_res_obj->isSuccess());
        $this->assertEmpty($missing_res_obj->files);

        $empty_arg_res_obj = Dj_App_File_Util::listFiles('');

        $this->assertFalse($empty_arg_res_obj->isSuccess());
    }

    public function testListFilesDirsOnly() {
        $this->seedListFilesDir();

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'dirs_only' => 1, ]);

        $this->assertArrayHasKey('1.0.0', $res_obj->files);
        $this->assertArrayNotHasKey('a.zip', $res_obj->files);
    }

    public function testListFilesFilesOnly() {
        $this->seedListFilesDir();

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'files_only' => 1, ]);

        $this->assertArrayHasKey('a.zip', $res_obj->files);
        $this->assertArrayNotHasKey('1.0.0', $res_obj->files);
    }

    /**
     * The extension is matched case-insensitively, and takes a list as readily as one.
     */
    public function testListFilesFiltersByExtension() {
        $this->seedListFilesDir();

        $zip_res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'ext' => 'zip', ]);

        $this->assertArrayHasKey('a.zip', $zip_res_obj->files);
        $this->assertArrayNotHasKey('c.tar.xz', $zip_res_obj->files);

        $many_res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'ext' => [ 'zip', 'XZ', ], ]);

        $this->assertArrayHasKey('a.zip', $many_res_obj->files);
        $this->assertArrayHasKey('c.tar.xz', $many_res_obj->files);

        // Upper-case ON DISK, lower-case in the filter.
        $txt_res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'ext' => 'txt', ]);

        $this->assertArrayHasKey('b.TXT', $txt_res_obj->files);
    }

    /**
     * Regression guard for the bug this was built for: a directory whose name merely
     * CONTAINS a version — a parked `zzz_1.0.0.old.<ts>` — was treated as a release and
     * published, because the old test only asked whether the name held safe characters.
     */
    public function testListFilesNamePatternRejectsALookalike() {
        $this->seedListFilesDir();

        $filters = [
            'dirs_only' => 1,
            'name_pattern' => '#^\d+\.\d+\.\d+(-[\w.]+)?$#',
        ];

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir, $filters);

        $this->assertArrayHasKey('1.0.0', $res_obj->files);
        $this->assertArrayHasKey('2.1.0-rc.1', $res_obj->files);
        $this->assertArrayNotHasKey('zzz_1.0.0.old.20260802-174338', $res_obj->files);
    }

    public function testListFilesSkipsDotFilesUnlessAsked() {
        $this->seedListFilesDir();

        $default_res_obj = Dj_App_File_Util::listFiles($this->test_dir);

        $this->assertArrayNotHasKey('.hidden', $default_res_obj->files);

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir, [ 'skip_dot_files' => 0, ]);

        $this->assertArrayHasKey('.hidden', $res_obj->files);
    }

    /**
     * For what the built-in filters cannot express — here, an in-flight upload temp.
     */
    public function testListFilesSkipCallbackDropsEntries() {
        $this->seedListFilesDir();

        $filters = [
            'files_only' => 1,
            'skip_callback' => [ __CLASS__, 'skipUploadTemps', ],
        ];

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir, $filters);

        $this->assertArrayNotHasKey('d.zip.dj_upload.tmp', $res_obj->files);
        $this->assertArrayHasKey('a.zip', $res_obj->files);
    }

    /**
     * Non-recursive on purpose: a caller that wants a tree walks it itself.
     */
    public function testListFilesDoesNotRecurse() {
        mkdir($this->test_dir . '/outer');
        file_put_contents($this->test_dir . '/outer/inner.txt', 'x');

        $res_obj = Dj_App_File_Util::listFiles($this->test_dir);

        $this->assertArrayHasKey('outer', $res_obj->files);
        $this->assertArrayNotHasKey('inner.txt', $res_obj->files);
    }
}
