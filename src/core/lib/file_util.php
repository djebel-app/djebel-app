<?php

class Dj_App_File_Util {
    /**
     * Reads a file partially e.g. the first NN bytes.
     * Dj_App_File_Util::readFilePartially();
     *
     * @param string $file
     * @param int $len_bytes how much bytes to read
     * @param int $seek_bytes should we start from the start?
     * @return Dj_App_Result
     */
    static function readPartially($file, $len_bytes = 2048, $seek_bytes = 0) {
        $res_obj = new Dj_App_Result();
        $func_args = func_get_args();

        try {
            Dj_App_Util::microtime( __METHOD__, $func_args );

            if (!file_exists($file)) {
                throw new Dj_App_Exception("File not found", [ 'file' => $file ]);
            }

            $fp = fopen($file, 'rb');

            if (empty($fp)) {
                throw new Dj_App_Exception("Couldn't open file for reading", [ 'file' => $file ]);
            }

            flock($fp, LOCK_SH);

            if ($seek_bytes > 0) {
                $fsee_res = fseek($fp, $seek_bytes);

                if ($fsee_res === -1) {
                    throw new Dj_App_Exception("Couldn't seek to position", [ 'file' => $file, 'seek_bytes' => $seek_bytes ]);
                }
            }

            $buff = '';
            $buff_size = 8192;
            $ctx = ['file' => $file, 'len_bytes' => $len_bytes, 'seek_bytes' => $seek_bytes];
            $buff_size = Dj_App_Hooks::applyFilter('app.core.file_util.read_buffer_size', $buff_size, $ctx);

            while (!feof($fp)) {
                $buff .= fread($fp, $buff_size);

                if (strlen($buff) >= $len_bytes) {
                    $buff = substr($buff, 0, $len_bytes); // be precise
                    break;
                }
            }

            $res_obj->output = $buff;
            $res_obj->status(true);
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
        } finally {
            if (!empty($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }

            $res_obj->exec_time = Dj_App_Util::microtime( __METHOD__, $func_args );
        }

        return $res_obj;
    }

    /**
     * @desc read function using flock
     * Dj_App_File_Util::read();
     * @param string $file
     * @return Dj_App_Result
     */
    static public function read($file) {
        $max_bytes = 1 * 1024 * 1024 * 1024; // 1GB
        $res_obj = self::readPartially($file, $max_bytes);
        return $res_obj;
    }

    /**
     * Writes to a file and creates the dir if it doesn't exist.
     * Uses temp file + rename for atomic writes and permission preservation.
     * Dj_App_File_Util::write();
     * @param string $file
     * @param string|mixed $data
     * @param array $params - ['flags' => FILE_APPEND] to pass custom flags
     * @return Dj_App_Result
     */
    static public function write($file, $data, $params = [])
    {
        $res_obj = new Dj_App_Result();
        $tmp_file = '';

        try {
            $dir = dirname($file);
            $mk_res = Dj_App_File_Util::mkdir($dir);

            if ($mk_res->isError()) {
                throw new Dj_App_File_Util_Exception("Couldn't create dir", ['dir' => $dir]);
            }

            $buff = is_scalar($data) ? $data : json_encode($data, JSON_PRETTY_PRINT);
            $flags = LOCK_EX;
            $input_flags = empty($params['flags']) ? 0 : $params['flags'];

            if (!empty($input_flags)) {
                $flags |= $input_flags;
            }

            // Use temp file approach for existing files
            if (file_exists($file)) {
                $perms = fileperms($file);

                // Format microtime with 4-digit fractional part
                $microtime_val = (string) microtime(true);
                $microtime_parts = explode('.', $microtime_val);
                $microtime_sec = $microtime_parts[0];
                $microtime_frac_raw = empty($microtime_parts[1]) ? 0 : (int) substr($microtime_parts[1], 0, 4);
                $microtime_frac = sprintf('%04d', $microtime_frac_raw);
                $microtime_fmt = $microtime_sec . '.' . $microtime_frac;

                $tmp_file = $file . '.dj_tmp.' . $microtime_fmt;

                // For append mode, copy existing file to temp first
                if ($input_flags & FILE_APPEND) {
                    $copy_res = copy($file, $tmp_file);

                    if (!$copy_res) {
                        throw new Dj_App_File_Util_Exception("Couldn't copy file to temp", ['file' => $file, 'tmp_file' => $tmp_file]);
                    }
                }

                // Write to temp file
                $res = file_put_contents($tmp_file, $buff, $flags);

                if (empty($res)) {
                    throw new Dj_App_File_Util_Exception("Couldn't write to temp file", ['tmp_file' => $tmp_file]);
                }

                // Rename temp to target
                $rename_res = rename($tmp_file, $file);

                if (!$rename_res) {
                    throw new Dj_App_File_Util_Exception("Couldn't rename temp file", ['tmp_file' => $tmp_file, 'file' => $file]);
                }

                // Restore permissions
                if (!empty($perms)) {
                    $chmod_res = chmod($file, $perms);
                }
            } else {
                // File doesn't exist, write directly
                $res = file_put_contents($file, $buff, $flags);

                if (empty($res)) {
                    throw new Dj_App_File_Util_Exception("Couldn't write to file", ['file' => $file]);
                }
            }

            $res_obj->status = true;
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();

            // Clean up temp file on error
            if (!empty($tmp_file) && file_exists($tmp_file)) {
                @unlink($tmp_file);
            }
        } finally {

        }

        return $res_obj;
    }

    /**
     * Creates a folder recursively if it doesn't exist.
     * @param string $dir
     * @param int $perm
     * @return Dj_App_Result
     */
    public static function mkdir($dir, $perm = 0755) {
        $res_obj = new Dj_App_Result();

        try {
            $old_mask = umask();
            umask(0);

            if (!is_dir($dir)) {
                $res = mkdir($dir, $perm, true);

                if (!$res) {
                    throw new Dj_App_File_Util_Exception("Couldn't create dir", ['dir' => $dir]);
                }
            }

            $chmod_res = chmod($dir, $perm); // jic
            $res_obj->chmod_res = $chmod_res;

            $res_obj->status = true;
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
        } finally {
            umask($old_mask);
        }

        return $res_obj;
    }

    /**
     * Dj_App_File_Util::normalizePath();
     * Normalize a filesystem/web path:
     *  - convert "\" to "/"
     *  - collapse multiple "/" to single "/"
     *  - trim spaces
     *  - optionally run removeSlash() if available in this class
     *  - ensure leading "/" when the result is non-empty
     *  - keep "/" for root; remove trailing "/" otherwise
     *
     * @param string|null $path
     * @return string
     */
    public static function normalizePath($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = (string) $path;
        $path = trim($path);

        // convert backslashes first
        $path = str_replace('\\', '/', $path);

        // Collapse duplicate slashes
        $path = Dj_App_String_Util::singlefy($path, '/');

        if (strlen($path) > 1) {
            $path = Dj_App_Util::removeSlash($path);
        }

        return $path;
    }

    /**
     * Remove file extension from a filename or path
     * Examples:
     *   removeExt('file.md') => 'file'
     *   removeExt('/path/to/file.php') => '/path/to/file'
     *   removeExt('file.tar.gz') => 'file.tar'
     *   removeExt('file') => 'file'
     * @param string $path
     * @return string
     */
    public static function removeExt($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = (string) $path;
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if (empty($ext)) {
            return $path;
        }

        $ext_len = strlen($ext);
        $dot_and_ext_len = $ext_len + 1;
        $result = substr($path, 0, -$dot_and_ext_len);

        return $result;
    }

    /**
     * Get file extension (lowercase)
     * Dj_App_File_Util::getExt();
     *
     * Examples:
     *   getExt('file.MD') => 'md'
     *   getExt('/path/to/file.PHP') => 'php'
     *   getExt('file.tar.gz') => 'gz'
     *   getExt('file') => ''
     *   getExt('.htaccess') => 'htaccess'
     *
     * @param string $path
     * @return string
     */
    public static function getExt($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = (string) $path;
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $ext = strtolower($ext);

        return $ext;
    }

    /**
     * Normalize file extension to canonical form
     * Dj_App_File_Util::normalizeExt();
     *
     * Examples:
     *   normalizeExt('jpeg') => 'jpg'
     *   normalizeExt('JPEG') => 'jpg'
     *   normalizeExt('jpg') => 'jpg'
     *   normalizeExt('png') => 'png'
     *
     * @param string $ext
     * @return string
     */
    public static function normalizeExt($ext)
    {
        if (empty($ext)) {
            return '';
        }

        $ext = (string) $ext;
        $ext = strtolower($ext);

        // Cheap first-char check before string compare
        if ($ext[0] === 'j' && $ext === 'jpeg') {
            return 'jpg';
        }

        return $ext;
    }

    /**
     * Get basename (filename without directory)
     * Dj_App_File_Util::getBasename();
     *
     * Examples:
     *   getBasename('/path/to/file.php') => 'file.php'
     *   getBasename('file.md') => 'file.md'
     *   getBasename('C:\\Users\\test\\file.txt') => 'file.txt'
     *   getBasename('/path/to/') => 'to'
     *   getBasename('') => ''
     *
     * @param string $path
     * @return string
     */
    public static function getBasename($path)
    {
        if (empty($path)) {
            return '';
        }

        $path = self::normalizePath($path);
        $basename = basename($path);

        return $basename;
    }
    /**
     * Resolve home directory placeholders in a path
     * Supports $HOME, ${HOME}, and ~/
     * Returns the path with placeholders replaced by the actual home directory
     *
     * Dj_App_File_Util::resolvePath('$HOME/site/htdocs')
     * Dj_App_File_Util::resolvePath('${HOME}/site/htdocs')
     * Dj_App_File_Util::resolvePath('~/site/htdocs')
     *
     * @param string $path
     * @return string Resolved path (unchanged if no placeholders or HOME not set)
     */
    public static function resolvePath($path) {
        if (empty($path)) {
            return '';
        }

        // Normalize ~/ to $HOME/ so one str_replace handles all cases
        if (strpos($path, '~/') === 0) {
            $path = '$HOME' . substr($path, 1);
        }

        // Expand $HOME and ${HOME} placeholders
        if (strpos($path, '$') !== false) {
            $home_dir = getenv('HOME');

            if (!empty($home_dir)) {
                $path = str_replace([ '${HOME}', '$HOME', ], $home_dir, $path);
            }
        }

        // Only resolve if relative path or symlink (skip for absolute non-symlinks)
        $path_first_char = substr($path, 0, 1);

        if ($path_first_char !== '/' || is_link($path)) {
            $resolved_path = realpath($path);

            if (!empty($resolved_path)) {
                return $resolved_path;
            }
        }

        return $path;
    }
}

class Dj_App_File_Util_Exception extends Dj_App_Exception {}
