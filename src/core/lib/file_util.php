<?php

class Dj_App_File_Util {
    const DEFAULT_DIR_PERM  = 0700;
    const DEFAULT_FILE_PERM = 0644;
    const SECURE_FILE_PERM  = 0600;

    // Dot entries are config or metadata, and a caller listing a data dir almost never
    // means to act on one. Read by [isSkippable] and by [listFiles], which needs the same
    // answer to decide whether to prune dot dirs before descending.
    const SKIP_DOT_FILES_DEFAULT = 1;

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
    static public function write($file, $data, $extra_opts = [])
    {
        $res_obj = new Dj_App_Result();
        $tmp_file = '';

        try {
            $dir = dirname($file);
            $mk_res = Dj_App_File_Util::mkdir($dir);

            if ($mk_res->isError()) {
                throw new Dj_App_File_Util_Exception("Couldn't create dir", ['dir' => $dir]);
            }

            $buff = is_scalar($data) ? $data : Dj_App_String_Util::jsonEncode($data);
            $flags = LOCK_EX;
            $input_flags = empty($extra_opts['flags']) ? 0 : $extra_opts['flags'];

            if (!empty($input_flags)) {
                $flags |= $input_flags;
            }

            $secure = !empty($extra_opts['secure']);
            $file_perm = $secure ? self::SECURE_FILE_PERM : self::DEFAULT_FILE_PERM;

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

                // Secure writes force owner-only; otherwise keep the file's own perms.
                $target_perm = $secure ? $file_perm : $perms;

                if (!empty($target_perm)) {
                    $chmod_res = chmod($file, $target_perm);
                }
            } else {
                // File doesn't exist, write directly
                $res = file_put_contents($file, $buff, $flags);

                if (empty($res)) {
                    throw new Dj_App_File_Util_Exception("Couldn't write to file", ['file' => $file]);
                }

                $chmod_res = chmod($file, $file_perm);
            }

            $res_obj->status = true;
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();

            // Clean up temp file on error. is_file() is the right check for unlink:
            // it returns true ONLY for regular files (not directories, not symlinks
            // pointing to nothing), which matches what unlink() can actually delete.
            // file_exists() would also return true for directories — unlink on a
            // directory raises a warning. PHP's stat cache merges this single stat
            // call with the unlink, so cost is one filesystem stat.
            if (!empty($tmp_file) && is_file($tmp_file)) {
                unlink($tmp_file);
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
    public static function mkdir($dir, $perm = self::DEFAULT_DIR_PERM) {
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
     * Removes a folder and everything in it.
     * Dj_App_File_Util::rmdir();
     *
     * A missing dir counts as SUCCESS: the caller asked for it to be gone.
     * Check $res_obj->deleted when you need to know whether anything was there.
     *
     * Symlinks are UNLINKED, never followed. Recursing into one would delete the
     * contents of whatever it points at, which is how a delete of a scratch folder
     * turns into a delete of someone's home dir.
     *
     * @param string $dir
     * @return Dj_App_Result
     */
    /**
     * The entries inside a directory, narrowed by $filters.
     *
     * One lister rather than a glob() at every call site, each with its own idea of what
     * to skip. Flat by default; pass recursive to walk the tree.
     *
     * The name-based filters are applied BEFORE the type ones, so an entry rejected by
     * its name never costs a filesystem call.
     *
     * Filters, all optional and ANDed:
     *   recursive      walk sub-directories too (default: off)
     *   dirs_only      only directories
     *   files_only     only files
     *   ext            extension, or a list of them, matched case-insensitively
     *   exclude_ext    the deny counterpart of ext — same shape, dropped instead of kept.
     *                  Both may be given; a name has to pass each
     *   name_pattern   a preg pattern the NAME must match — the strict one, for a caller
     *                  that knows the shape it expects (a version, an id, a date)
     *   skip_dot_files drop names beginning with a dot (default: on)
     *   skip_callback  a NAMED callable — 'my_func' or [ $obj, 'method' ], never a
     *                  closure — receiving ($name, $full_path) and returning true to drop
     *                  the entry. For what the filters above cannot express: an in-flight
     *                  upload temp, a lock file, anything the caller alone recognises.
     *                  Runs LAST, on the survivors only.
     *
     * @param string $dir
     * @param array $filters
     * @return Dj_App_Result ->files as [ key => full path ], keyed by NAME when flat and
     *         by the path RELATIVE to $dir when recursive, since a basename repeats
     *         across directories. An unreadable dir is an ERROR; a readable one that
     *         matched nothing is a SUCCESS with none, so the two are never confused.
     */
    /**
     * Whether an entry is skipped on its NAME alone.
     *
     * THE one place that rule lives, so the flat listing and the recursive prune cannot
     * disagree — and the place to grow it when there is a second thing worth skipping
     * everywhere. Name-only on purpose: it must be answerable without touching the
     * filesystem, which is what lets a caller reject an entry before paying for a stat.
     *
     * Takes the same $filters [listFiles] was given, so the DECISION lives here and a
     * caller never has to test a flag before asking.
     *
     * @param string $name a basename, not a path
     * @param array $filters skip_dot_files (absent = on)
     * @return bool
     */
    public static function isSkippable($name, $filters = [])
    {
        if (empty($name)) {
            return true;
        }

        $skip_dot_files = self::SKIP_DOT_FILES_DEFAULT;

        if (array_key_exists('skip_dot_files', $filters)) {
            $skip_dot_files = empty($filters['skip_dot_files']) ? 0 : 1;
        }

        if (empty($skip_dot_files)) {
            return false;
        }

        $is_dot_entry = $name[0] === '.';

        return $is_dot_entry;
    }

    /**
     * The accept-callback RecursiveCallbackFilterIterator takes — it keeps what returns
     * TRUE, which is why this exists rather than handing it [isSkippable] directly.
     *
     * A named method because a closure is not allowed as a callback here.
     *
     * @param SplFileInfo $file_info
     * @param string $key
     * @param Iterator $iterator
     * @return bool
     */
    public static function isEntryAllowed($file_info, $key, $iterator)
    {
        $name = $file_info->getFilename();
        $is_skippable = Dj_App_File_Util::isSkippable($name);
        $is_allowed = !$is_skippable;

        return $is_allowed;
    }

    public static function listFiles($dir, $filters = [])
    {
        $res_obj = new Dj_App_Result();
        $res_obj->files = [];

        if (empty($dir)) {
            $res_obj->msg = 'Empty dir';

            return $res_obj;
        }

        if (!is_dir($dir)) {
            $res_obj->msg = 'No such dir: ' . $dir;

            return $res_obj;
        }

        // ONE seam for the whole set rather than a hook per value: a site can add an
        // extension, force recursion off, or attach a skip_callback for a kind of file
        // it never wants listed, wherever the call was made from.
        $filter_ctx = [
            'dir' => $dir,
        ];

        $filters = Dj_App_Hooks::applyFilter('app.core.file_util.list_files_filters', $filters, $filter_ctx);
        $filters = empty($filters) ? [] : (array) $filters;

        $dirs_only = empty($filters['dirs_only']) ? 0 : 1;
        $files_only = empty($filters['files_only']) ? 0 : 1;
        $name_pattern = empty($filters['name_pattern']) ? '' : $filters['name_pattern'];
        $skip_callback = empty($filters['skip_callback']) ? '' : $filters['skip_callback'];

        // On unless the caller says otherwise: a dot file is config or metadata, and a
        // caller listing a data dir almost never means to act on one.
        $skip_dot_files = self::SKIP_DOT_FILES_DEFAULT;

        if (array_key_exists('skip_dot_files', $filters)) {
            $skip_dot_files = empty($filters['skip_dot_files']) ? 0 : 1;
        }

        $recursive = empty($filters['recursive']) ? 0 : 1;

        // CSV or array, dotted or not, any case — the SPEC arrives however reads
        // best at the call site and lands here as one clean lookup list.
        $allowed_extensions = [];

        if (!empty($filters['ext'])) {
            $allowed_extensions = Dj_App_File_Util::normalizeExtList($filters['ext']);
        }

        $skipped_extensions = [];

        if (!empty($filters['exclude_ext'])) {
            $skipped_extensions = Dj_App_File_Util::normalizeExtList($filters['exclude_ext']);
        }

        // SPL, not glob(): glob('*') silently omits dot entries, so honouring
        // skip_dot_files=0 meant a SECOND glob for '.[!.]*' and merging the two.
        // SKIP_DOTS drops only '.' and '..', so real dot files arrive like anything else.
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO;

        // Filterable for the cases the filters above cannot reach — FOLLOW_SYMLINKS on a
        // tree of links, or UNIX_PATHS on Windows. CURRENT_AS_FILEINFO is what the loop
        // below is written against, so a listener that drops it breaks the walk.
        $flags_ctx = $filter_ctx;
        $flags_ctx['filters'] = $filters;

        $flags = Dj_App_Hooks::applyFilter('app.core.file_util.list_files_flags', $flags, $flags_ctx);
        $flags = (int) $flags;

        $base_dir = rtrim($dir, '/');
        $entries = [];

        try {
            if (empty($recursive)) {
                $dir_iterator = new FilesystemIterator($base_dir, $flags);
            } else {
                $child_iterator = new RecursiveDirectoryIterator($base_dir, $flags);

                // PRUNE dot directories before descending, rather than dropping their
                // contents afterwards. Skipping `.git` from the listing while still
                // walking it leaked `.git/config`, whose own name starts with no dot —
                // and it cost a full walk of a tree nobody asked about.
                if (!empty($skip_dot_files)) {
                    $child_iterator = new RecursiveCallbackFilterIterator($child_iterator, [ __CLASS__, 'isEntryAllowed', ]);
                }

                $dir_iterator = new RecursiveIteratorIterator($child_iterator);
            }

            foreach ($dir_iterator as $file_info) {
                $name = $file_info->getFilename();

                // NAME FIRST, every time. These are string tests on something already in
                // hand; the isDir/isFile below reach the filesystem. Rejecting by name
                // first means the entries that lose never cost a stat at all.
                // NAME FIRST, and through the shared rule so this and the recursive prune
                // above cannot drift apart. The filters go WITH it — whether dot entries
                // count is its decision, not something to test before asking.
                if (Dj_App_File_Util::isSkippable($name, $filters)) {
                    continue;
                }

                if (!empty($name_pattern) && !preg_match($name_pattern, $name)) {
                    continue;
                }

                // Read once when either extension filter is in play, since both ask the
                // same question of the same name.
                if (!empty($allowed_extensions) || !empty($skipped_extensions)) {
                    $ext = Dj_App_File_Util::getExt($name);
                    $ext = strtolower($ext);

                    if (!empty($allowed_extensions) && !in_array($ext, $allowed_extensions)) {
                        continue;
                    }

                    if (!empty($skipped_extensions) && in_array($ext, $skipped_extensions)) {
                        continue;
                    }
                }

                // Only now, on what survived the free tests.
                if (!empty($dirs_only) && !$file_info->isDir()) {
                    continue;
                }

                if (!empty($files_only) && !$file_info->isFile()) {
                    continue;
                }

                $full_file = $file_info->getPathname();

                // LAST: the only test that costs a call into the caller's own code.
                if (!empty($skip_callback) && call_user_func($skip_callback, $name, $full_file)) {
                    continue;
                }

                // Flat listing keys by name. A RECURSIVE one cannot: two dirs may hold
                // the same basename and one would quietly overwrite the other, so the key
                // is the path relative to $dir, which is unique by construction.
                $key = $name;

                if (!empty($recursive)) {
                    $key = substr($full_file, strlen($base_dir) + 1);
                }

                $entries[$key] = $full_file;
            }
        } catch (Exception $e) {
            // An unreadable dir throws rather than returning nothing — report it as the
            // error it is instead of an empty listing that reads like "nothing here".
            $res_obj->msg = $e->getMessage();

            return $res_obj;
        }

        $res_obj->files = $entries;
        $res_obj->status = true;

        return $res_obj;
    }

    public static function rmdir($dir) {
        $res_obj = new Dj_App_Result();
        $res_obj->deleted = false;

        try {
            if (empty($dir)) {
                throw new Dj_App_File_Util_Exception("Empty dir");
            }

            // A recursive delete of / or a drive root is never a real request, and it
            // is the one mistake with no undo. Checked on the RESOLVED location so a
            // link or a '..' cannot walk up to it.
            $real_dir = realpath($dir);

            if (empty($real_dir)) { // gone already: nothing to do
                $res_obj->status = true;

                return $res_obj;
            }

            $parent_dir = dirname($real_dir);

            if ($parent_dir === $real_dir) {
                throw new Dj_App_File_Util_Exception("Refusing to remove a filesystem root", [ 'dir' => $real_dir ]);
            }

            if (!is_dir($real_dir)) {
                throw new Dj_App_File_Util_Exception("Not a dir", [ 'dir' => $real_dir ]);
            }

            $dir_it_obj = new RecursiveDirectoryIterator($real_dir, FilesystemIterator::SKIP_DOTS);
            $it_obj = new RecursiveIteratorIterator($dir_it_obj, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($it_obj as $entry_obj) {
                $entry = $entry_obj->getPathname();

                // isDir() FOLLOWS a link, so the link test comes first — otherwise a
                // symlink to a dir is rmdir'd (which fails) instead of unlinked.
                if ($entry_obj->isLink() || !$entry_obj->isDir()) {
                    $unlink_res = unlink($entry);

                    if (!$unlink_res) {
                        throw new Dj_App_File_Util_Exception("Couldn't remove file", [ 'file' => $entry ]);
                    }

                    continue;
                }

                $rmdir_res = rmdir($entry);

                if (!$rmdir_res) {
                    throw new Dj_App_File_Util_Exception("Couldn't remove dir", [ 'dir' => $entry ]);
                }
            }

            $rmdir_res = rmdir($real_dir);

            if (!$rmdir_res) {
                throw new Dj_App_File_Util_Exception("Couldn't remove dir", [ 'dir' => $real_dir ]);
            }

            $res_obj->deleted = true;
            $res_obj->status = true;
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
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
        $path = Dj_App_String_Util::trim($path);

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
     * @param string $file
     * @return string
     */
    public static function removeExt($file)
    {
        if (empty($file)) {
            return '';
        }

        $file = (string) $file;
        $ext = pathinfo($file, PATHINFO_EXTENSION);

        if (empty($ext)) {
            return $file;
        }

        $ext_len = strlen($ext);
        $dot_and_ext_len = $ext_len + 1;
        $result = substr($file, 0, -$dot_and_ext_len);

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
     * @param string $file
     * @return string
     */
    public static function getExt($file)
    {
        if (empty($file)) {
            return '';
        }

        $file = (string) $file;
        $ext = pathinfo($file, PATHINFO_EXTENSION);
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

    /** Spellings of the SAME format — a spec naming one matches files with either. */
    const EXT_ALIASES = [
        'jpg' => 'jpeg',
        'jpeg' => 'jpg',
    ];

    /**
     * An extension SPEC — CSV or array, dotted, any case — as one clean lowercase
     * lookup list. Known alias spellings EXPAND: a 'jpeg' spec matches .jpg files
     * too — the sibling is ADDED, nothing the caller asked for is rewritten.
     * Dj_App_File_Util::normalizeExtList();
     * @param string|array $input
     * @return array
     */
    public static function normalizeExtList($input)
    {
        if (empty($input)) {
            return [];
        }

        // The splitter takes both shapes and hands back trimmed strings, so the
        // loop below only strips dots and folds case.
        $items = Dj_App_String_Util::splitOnSeparators($input);
        $extensions = [];

        foreach ($items as $item) {
            $item = ltrim($item, '.');
            $item = strtolower($item);

            if (!strlen($item)) {
                continue;
            }

            $extensions[] = $item;

            if (isset(Dj_App_File_Util::EXT_ALIASES[$item])) {
                $extensions[] = Dj_App_File_Util::EXT_ALIASES[$item];
            }
        }

        return $extensions;
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
     * Format a byte count into a human-readable size ('1.5 GB', '10 MB',
     * '512 B').
     * Dj_App_File_Util::formatSize();
     *
     * @param int $bytes
     * @return string
     */
    public static function formatSize($bytes)
    {
        $bytes = (int) $bytes;

        if ($bytes >= 1024 * 1024 * 1024) {
            $gb = round($bytes / (1024 * 1024 * 1024), 1);
            $size_fmt = "{$gb} GB";

            return $size_fmt;
        }

        if ($bytes >= 1024 * 1024) {
            $mb = round($bytes / (1024 * 1024), 1);
            $size_fmt = "{$mb} MB";

            return $size_fmt;
        }

        if ($bytes >= 1024) {
            $kb = round($bytes / 1024, 1);
            $size_fmt = "{$kb} KB";

            return $size_fmt;
        }

        $size_fmt = "{$bytes} B";

        return $size_fmt;
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
                $home_placeholders = [ '${HOME}', '$HOME', ];
                $path = str_replace($home_placeholders, $home_dir, $path);
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
