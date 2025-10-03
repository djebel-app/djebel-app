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
            Dj_App_Util::time( __METHOD__, $func_args );

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

            $res_obj->exec_time = Dj_App_Util::time( __METHOD__, $func_args );
        }

        return $res_obj;
    }

    /**
     * @desc read function using flock
     * Dj_App_File_Util::read();
     * @param string $file
     * @return false|string
     */
    static public function read($file) {
        $max_bytes = 1 * 1024 * 1024 * 1024; // 1GB
        $res_obj = self::readPartially($file, $max_bytes);

        if ($res_obj->isError()) {
            return false;
        }

        return $res_obj->output;
    }

    /**
     * Writes to a file and creates the dir if it doesn't exist.
     * Dj_App_File_Util::write();
     * @param string $file
     * @param string|mixed $data
     * @param array $params - ['flags' => FILE_APPEND] to pass custom flags
     * @return Dj_App_Result
     */
    static public function write($file, $data, $params = [])
    {
        $res_obj = new Dj_App_Result();

        try {
            $dir = dirname($file);
            $mk_res = Dj_App_File_Util::mkdir($dir);

            if ($mk_res->isError()) {
                throw new Dj_App_File_Util_Exception("Couldn't create dir", ['dir' => $dir]);
            }

            $buff = is_scalar($data) ? $data : json_encode($data, JSON_PRETTY_PRINT);
            $flags = LOCK_EX;

            if (!empty($params['flags'])) {
                $flags |= $params['flags'];
            }

            $res = file_put_contents($file, $buff, $flags);

            if (empty($res)) {
                throw new Dj_App_File_Util_Exception("Couldn't write to file", ['file' => $file]);
            }

            $res_obj->status = true;
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
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
            $res_obj->chmod_res = $res_obj;

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

        $path = (string)$path;
        $path = trim($path);

        // convert backslashes first
        $path = str_replace('\\', '/', $path);

        // collapse duplicate slashes, but do it in a loop to be safe
        while (strpos($path, '//') !== false) {
            $path = str_replace('//', '/', $path);
        }

        if (strlen($path) > 1) {
            $path = Dj_App_Util::removeSlash($path);
        }

        return $path;
    }
}

class Dj_App_File_Util_Exception extends Dj_App_Exception {}
