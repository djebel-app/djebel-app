<?php
/**
 * The cache. Entries live in APCu, memory every PHP process of the server shares, when it is
 * enabled for the running PHP, and in files otherwise, such as on a command line where APCu is
 * off. Callers never choose: the app.cache.backend filter is where another store, such as Valkey,
 * takes over once it is set up.
 * Dj_App_Cache
 */
class Dj_App_Cache
{
    const METHOD_PHP_SERIALIZE = 'php_serialize';
    const METHOD_JSON = 'json';

    const BACKEND_FILE = 'file';
    const BACKEND_APCU = 'apcu';

    // APCu memory is shared by every site the server runs, so each key starts with this and a
    // hash of its own cache dir: one app's entries never meet another's, and removeAll() can find
    // a dir's entries by that prefix alone.
    const MEMORY_KEY_PREFIX = 'dj_cache:';

    const DEFAULT_TTL = 14400;

    const CODE_BAD_TTL = 'app.core.cache.bad_ttl';
    const CODE_ENCODE_FAILED = 'app.core.cache.encode_failed';
    const CODE_STORE_FAILED = 'app.core.cache.store_failed';
    const CODE_INCREMENT_FAILED = 'app.core.cache.increment_failed';

    // Compact, with Cyrillic and URLs kept as written and 1.0 kept a float, so a JSON cache file
    // decodes to the values a PHP serialize one does. A value JSON can't hold as written, such as
    // a byte that isn't valid UTF-8, INF or NAN, throws instead of being stored as something else:
    // a replaced byte in a key or a file name would come back as a value nobody wrote.
    const JSON_ENCODE_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private static $end_marker = '// ::dj_end::';

    /**
     * Get cached data by key
     * Dj_App_Cache::get();
     *
     * @param string|array $key Cache key (string or array for namespacing)
     * @param array $params Optional parameters
     * @return mixed|null Cached data or null if invalid/not found/expired
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function get($key, $params = [])
    {
        if (isset($params['enabled']) && empty($params['enabled'])) {
            return null;
        }

        $ctx = ['key' => $key, 'params' => $params];

        $result = [];
        $result = Dj_App_Hooks::applyFilter('app.cache.pre_get.data', $result, $ctx);

        if (!empty($result)) {
            return $result;
        }

        $cache_file = Dj_App_Cache::getCacheFile($key, $params);
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.get.file', $cache_file, $ctx);
        $backend = Dj_App_Cache::getBackend($params);

        if ($backend == Dj_App_Cache::BACKEND_APCU) {
            $memory_key = Dj_App_Cache::getMemoryKey($cache_file);
            $is_found = false;
            $result = apcu_fetch($memory_key, $is_found);

            if (empty($is_found)) {
                $result = null;
            }
        } else {
            $result = Dj_App_Cache::read($cache_file);
        }

        $result = Dj_App_Hooks::applyFilter('app.cache.get.data', $result, $ctx);

        return $result;
    }

    /**
     * Set cached data by key
     * Dj_App_Cache::set();
     *
     * @param string|array $key Cache key (string or array for namespacing)
     * @param mixed $data Data to cache
     * @param array $params Optional parameters (ttl, cache_method, etc)
     * @return Dj_App_Result
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function set($key, $data, $params = [])
    {
        $cache_file = Dj_App_Cache::getCacheFile($key, $params);

        $ctx = ['key' => $key, 'params' => $params];
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.set.file', $cache_file, $ctx);

        $data = Dj_App_Hooks::applyFilter('app.cache.set.data', $data, $ctx);
        $backend = Dj_App_Cache::getBackend($params);

        if ($backend == Dj_App_Cache::BACKEND_APCU) {
            $result = Dj_App_Cache::writeMemory($cache_file, $data, $params);
        } else {
            $result = Dj_App_Cache::write($cache_file, $data, $params);
        }

        return $result;
    }

    /**
     * Adds one to a counter kept under a key, atomically, and reports the new count, so requests
     * arriving together each count. A key that holds nothing starts at 0. The ttl counts from the
     * counter's first increment in APCu, and from its latest on the file backend, which rewrites
     * the entry under a lock.
     * Dj_App_Cache::increment();
     *
     * @param string|array $key Cache key (string or array for namespacing)
     * @param array $params Optional parameters (ttl, plugin)
     * @return Dj_App_Result count
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function increment($key, $params = [])
    {
        $res_obj = new Dj_App_Result();
        $res_obj->count = 0;

        $ttl = isset($params['ttl']) ? $params['ttl'] : Dj_App_Cache::DEFAULT_TTL;

        if (!is_numeric($ttl)) {
            $res_obj->msg = 'The cache ttl must be a number of seconds';
            $res_obj->code(Dj_App_Cache::CODE_BAD_TTL);

            return $res_obj;
        }

        $cache_file = Dj_App_Cache::getCacheFile($key, $params);
        $backend = Dj_App_Cache::getBackend($params);

        if ($backend == Dj_App_Cache::BACKEND_APCU) {
            $memory_key = Dj_App_Cache::getMemoryKey($cache_file);

            // Creates the counter with its ttl only when it is not there yet; the increment below
            // is APCu's own, so two requests never read the same count.
            apcu_add($memory_key, 0, $ttl);

            $is_incremented = false;
            $count = apcu_inc($memory_key, 1, $is_incremented);

            if (empty($is_incremented)) {
                $res_obj->msg = 'The counter could not be incremented';
                $res_obj->code(Dj_App_Cache::CODE_INCREMENT_FAILED);

                return $res_obj;
            }

            $res_obj->count = $count;
            $res_obj->status(true);

            return $res_obj;
        }

        $lock_params = [ 'file' => $cache_file, ];
        $lock_res_obj = Dj_App_File_Util::acquireLock($lock_params);

        if ($lock_res_obj->isError()) {
            $res_obj->msg = $lock_res_obj->msg;
            $res_obj->code($lock_res_obj->code);

            return $res_obj;
        }

        $release_res_obj = null;

        try {
            $stored_count = Dj_App_Cache::read($cache_file);
            $count = (int) $stored_count;
            $count++;

            $write_params = [ 'ttl' => $ttl, ];
            $write_res_obj = Dj_App_Cache::write($cache_file, $count, $write_params);
        } finally {
            $release_res_obj = Dj_App_File_Util::releaseLock($lock_res_obj);
        }

        // The count is written either way, and a lock that would not let go is dropped when the
        // process ends; the log says it happened.
        if ($release_res_obj->isError()) {
            $log_data = [ 'msg' => 'A cache counter lock could not be released', 'cache_file' => $cache_file, ];

            Dj_App_Log::error($log_data, __METHOD__);
        }

        if ($write_res_obj->isError()) {
            $res_obj->msg = $write_res_obj->msg;
            $res_obj->code($write_res_obj->code);

            return $res_obj;
        }

        $res_obj->count = $count;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Where entries live: APCu when it is enabled for the running PHP, files otherwise. The
     * app.cache.backend filter gets the last word; a name it hands back that no backend answers
     * to falls back to files, so a mistyped setting never loses the cache.
     * Dj_App_Cache::getBackend();
     *
     * @param array $params the entry's params, passed to the filter
     * @return string a BACKEND_* value
     */
    public static function getBackend($params = [])
    {
        $is_apcu_on = function_exists('apcu_enabled') && apcu_enabled();

        if ($is_apcu_on) {
            $backend = Dj_App_Cache::BACKEND_APCU;
        } else {
            $backend = Dj_App_Cache::BACKEND_FILE;
        }

        $ctx = [ 'params' => $params, ];
        $backend = Dj_App_Hooks::applyFilter('app.cache.backend', $backend, $ctx);

        if ($backend == Dj_App_Cache::BACKEND_APCU && $is_apcu_on) {
            return Dj_App_Cache::BACKEND_APCU;
        }

        return Dj_App_Cache::BACKEND_FILE;
    }

    /**
     * The APCu key of the entry a cache file would hold: the prefix, a hash of the file's dir and
     * the file's name, so keys stay apart per app and per plugin dir.
     * Dj_App_Cache::getMemoryKey();
     *
     * @param string $cache_file
     * @return string
     */
    public static function getMemoryKey($cache_file)
    {
        $cache_dir = dirname($cache_file);
        $file_name = basename($cache_file);
        $dir_prefix = Dj_App_Cache::getMemoryDirPrefix($cache_dir);
        $memory_key = $dir_prefix . $file_name;

        return $memory_key;
    }

    /**
     * The start every APCu key of one cache dir shares.
     * Dj_App_Cache::getMemoryDirPrefix();
     *
     * @param string $cache_dir
     * @return string
     */
    public static function getMemoryDirPrefix($cache_dir)
    {
        $dir_hash = sha1($cache_dir);
        $dir_prefix = Dj_App_Cache::MEMORY_KEY_PREFIX . $dir_hash . ':';

        return $dir_prefix;
    }

    /**
     * Stores an entry in APCu with its ttl. Empty data or an entry switched off is not stored,
     * as with files.
     * Dj_App_Cache::writeMemory();
     *
     * @param string $cache_file the file the entry would be on the file backend, which names it
     * @param mixed $data
     * @param array $params ttl, enabled
     * @return Dj_App_Result
     */
    public static function writeMemory($cache_file, $data, $params = [])
    {
        $res_obj = new Dj_App_Result();

        if (empty($data)) {
            return $res_obj;
        }

        if (isset($params['enabled']) && empty($params['enabled'])) {
            return $res_obj;
        }

        $ttl = isset($params['ttl']) ? $params['ttl'] : Dj_App_Cache::DEFAULT_TTL;

        if (!is_numeric($ttl)) {
            $res_obj->msg = 'The cache ttl must be a number of seconds';
            $res_obj->code(Dj_App_Cache::CODE_BAD_TTL);

            return $res_obj;
        }

        $memory_key = Dj_App_Cache::getMemoryKey($cache_file);
        $is_stored = apcu_store($memory_key, $data, $ttl);

        if (empty($is_stored)) {
            $res_obj->msg = 'The entry could not be stored in memory';
            $res_obj->code(Dj_App_Cache::CODE_STORE_FAILED);

            return $res_obj;
        }

        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Delete cached data by key
     * Dj_App_Cache::remove();
     *
     * @param string|array $key Cache key
     * @param array $params Optional parameters
     * @return bool
     */
    public static function remove($key, $params = [])
    {
        $ctx = ['key' => $key, 'params' => $params];

        $cache_file = Dj_App_Cache::getCacheFile($key, $params);
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.remove.file', $cache_file, $ctx);

        // Both places: an entry made while APCu was off, or on a command line, sits in a file,
        // and one made by the web server in memory.
        if (function_exists('apcu_enabled') && apcu_enabled()) {
            $memory_key = Dj_App_Cache::getMemoryKey($cache_file);
            apcu_delete($memory_key);
        }

        $result = Dj_App_Cache::delete($cache_file);

        return $result;
    }

    /**
     * Delete all cache entries of a directory (e.g., all plugin cache): its files, and its entries
     * in APCu where this PHP can reach them.
     * Dj_App_Cache::removeAll();
     *
     * @param array $params Parameters (plugin, etc)
     * @return bool
     */
    public static function removeAll($params = [])
    {
        $cache_dir = Dj_App_Cache::getCacheDir($params);

        if (empty($cache_dir)) {
            return false;
        }

        $ctx = ['params' => $params];
        Dj_App_Hooks::doAction('app.cache.pre_remove_all', $ctx);

        // A dir with no files on disk can still have entries in memory: APCu writes none.
        $files = [];

        if (is_dir($cache_dir)) {
            $scan_result = scandir($cache_dir);
            $exclude_items = [ '.', '..', ];
            $files = array_diff($scan_result, $exclude_items);
        }

        $deleted_count = 0;

        foreach ($files as $file) {
            $file_path = $cache_dir . '/' . $file;

            if (!is_file($file_path)) {
                continue;
            }

            $ext = Dj_App_File_Util::getExt($file);

            if ($ext !== 'php') {
                continue;
            }

            $delete_result = Dj_App_Cache::delete($file_path);

            if ($delete_result) {
                $deleted_count++;
            }
        }

        // The web server's entries in memory go too, where this PHP can reach APCu. A command line
        // with APCu off cannot: those entries stay until their ttl or a server restart, and the
        // log says so, since a clear that leaves them looks like it worked.
        $is_apcu_on = function_exists('apcu_enabled') && apcu_enabled();

        if ($is_apcu_on) {
            $dir_prefix = Dj_App_Cache::getMemoryDirPrefix($cache_dir);
            $prefix_pattern = '/^' . preg_quote($dir_prefix, '/') . '/';
            $memory_iterator = new APCUIterator($prefix_pattern, APC_ITER_KEY);
            $is_memory_cleared = apcu_delete($memory_iterator);

            if (empty($is_memory_cleared)) {
                $log_data = [ 'msg' => 'Cache entries in memory could not all be removed', 'cache_dir' => $cache_dir, ];

                Dj_App_Log::error($log_data, __METHOD__);
            }
        } elseif (extension_loaded('apcu')) {
            $log_data = [ 'msg' => 'APCu is off for this PHP, so the web server\'s cache entries in memory stay until they expire', 'cache_dir' => $cache_dir, ];

            Dj_App_Log::warn($log_data, __METHOD__);
        }

        Dj_App_Hooks::doAction('app.cache.post_remove_all', $ctx);

        return true;
    }

    /**
     * Get cache file path from key
     * Dj_App_Cache::getCacheFile();
     *
     * @param string|array $key Cache key
     * @param array $params Optional parameters
     * @return string Cache file path
     */
    private static function getCacheFile($key, $params = [])
    {
        if (is_array($key)) {
            ksort($key);
            $key = implode('_', $key);
        }

        $key = Dj_App_String_Util::formatStringId($key);

        $cache_dir_params = [];

        if (!empty($params['plugin'])) {
            $cache_dir_params['plugin'] = $params['plugin'];
        }

        $cache_dir = Dj_App_Cache::getCacheDir($cache_dir_params);
        $cache_file = $cache_dir . '/' . $key . '.php';

        return $cache_file;
    }

    /**
     * Get cache directory
     * Dj_App_Cache::getCacheDir();
     *
     * @param array $params Optional parameters
     * @return string
     */
    public static function getCacheDir($params = [])
    {
        $dir = Dj_App_Util::getCorePrivateDir();

        if (empty($dir)) {
            return '';
        }

        $dir .= '/data/cache';

        $ctx = ['params' => $params];
        $dir = Dj_App_Hooks::applyFilter('app.config.djebel_cache_dir', $dir, $ctx);

        if (!empty($params['plugin'])) {
            $slug = $params['plugin'];
            $slug = Dj_App_String_Util::formatStringId($slug);
            $dir .= '/plugins/' . $slug;
            $dir = Dj_App_Hooks::applyFilter('app.config.djebel_cache_plugin_dir', $dir, $ctx);
        }

        $dir = Dj_App_Hooks::applyFilter('app.cache.dir', $dir, $ctx);

        return $dir;
    }

    /**
     * How cache files are encoded: the site's `[app] cache_method`, else the app.core.cache_method
     * env var or constant, else php_serialize; the app.cache.method filter gets the last word.
     * A cache_method key in $params can ask for JSON for one entry, on any site.
     * Case, spaces and punctuation are forgiven; any other name throws, so a typo can't quietly
     * leave a site on the method it meant to leave.
     * Dj_App_Cache::getCacheMethod();
     *
     * @param array $params Optional: cache_method, plus whatever the filter's listeners read
     * @return string Dj_App_Cache::METHOD_PHP_SERIALIZE or Dj_App_Cache::METHOD_JSON
     * @throws Dj_App_Exception
     */
    public static function getCacheMethod($params = [])
    {
        $options_obj = Dj_App_Options::getInstance();
        $cache_method = $options_obj->get('app.cache_method');

        if (empty($cache_method)) {
            $cache_method = Dj_App_Config::cfg('app.core.cache_method');
        }

        // Asking for php_serialize changes nothing, so an entry can't take a site off JSON and make
        // it run unserialize() on its cache dir. Any other name goes on to the check below.
        if (!empty($params['cache_method'])) {
            $entry_method = $params['cache_method'];

            if (is_scalar($entry_method)) {
                $entry_method = Dj_App_String_Util::formatKey($entry_method);
            }

            if ($entry_method != Dj_App_Cache::METHOD_PHP_SERIALIZE) {
                $cache_method = $params['cache_method'];
            }
        }

        $ctx = [ 'params' => $params, ];
        $cache_method = Dj_App_Hooks::applyFilter('app.cache.method', $cache_method, $ctx);

        if (empty($cache_method)) {
            return Dj_App_Cache::METHOD_PHP_SERIALIZE;
        }

        // A listener may hand back anything; only a scalar is formatted, and anything else falls
        // through to the throw below.
        if (is_scalar($cache_method)) {
            $cache_method = Dj_App_String_Util::formatKey($cache_method);
        }

        if (($cache_method == Dj_App_Cache::METHOD_PHP_SERIALIZE) || ($cache_method == Dj_App_Cache::METHOD_JSON)) {
            return $cache_method;
        }

        throw new Dj_App_Exception('Unknown cache method', [
            'code' => 'app.core.cache.unknown_method',
            'cache_method' => $cache_method,
        ]);
    }

    /**
     * Read data from cache file. The file says how it was written: JSON reads on any site, and PHP
     * serialize only on a site set to php_serialize. A damaged file, or one write() did not make,
     * reads as a miss.
     * Dj_App_Cache::read();
     *
     * @param string $cache_file Path to cache file
     * @return mixed|null Cached data or null if invalid/not found/expired
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function read($cache_file)
    {
        if (!file_exists($cache_file)) {
            return null;
        }

        $read_result = Dj_App_File_Util::read($cache_file);

        if ($read_result->isError() || empty($read_result->output)) {
            return null;
        }

        $cache_content = $read_result->output;

        // Skip protection header
        $marker_pos = strpos($cache_content, Dj_App_Cache::$end_marker);

        if ($marker_pos !== false) {
            $offset = $marker_pos + strlen(Dj_App_Cache::$end_marker);
            $cache_content = substr($cache_content, $offset);

            // NOTE: PHP's trim, not Dj_App_String_Util::trim(), which also strips the NUL bytes
            // inside a serialized string and so turns a value that holds one into a miss.
            $cache_content = trim($cache_content);
        }

        // One entry may be JSON on a site that isn't, so the first byte decides the decoder. Only a
        // site on php_serialize ever reaches unserialize(), and JSON can't build an object anywhere.
        $first_char = Dj_App_String_Util::getFirstChar($cache_content);

        if ($first_char == '{') {
            $cached_data = Dj_App_String_Util::jsonDecode($cache_content);
        } else {
            $cache_method = Dj_App_Cache::getCacheMethod();

            if ($cache_method == Dj_App_Cache::METHOD_JSON) {
                return null;
            }

            $cached_data = Dj_App_Util::unserialize($cache_content);
        }

        // Only the meta + data array write() makes is a hit, so the next set() writes over any
        // other file instead of this handing it to the caller.
        if (!is_array($cached_data) || empty($cached_data['meta']) || empty($cached_data['data'])) {
            return null;
        }

        $meta = $cached_data['meta'];

        // Check expiration if TTL is set
        if (!empty($meta['ttl']) && !empty($meta['created_at'])) {
            // A created_at or ttl that isn't a number breaks the sum below on every request, so the
            // file reads as a miss and the next set() writes over it.
            if (!is_numeric($meta['created_at']) || !is_numeric($meta['ttl'])) {
                return null;
            }

            $expires_at = $meta['created_at'] + $meta['ttl'];

            if (time() > $expires_at) {
                $delete_res = Dj_App_Cache::delete($cache_file);

                // A miss either way, and the next set() writes over the file, but a cache dir that
                // refuses deletes is a permissions problem worth finding in the log.
                if (empty($delete_res)) {
                    $log_data = [
                        'msg' => 'Expired cache file could not be deleted',
                        'cache_file' => $cache_file,
                    ];

                    Dj_App_Log::error($log_data, __METHOD__);
                }

                return null;
            }
        }

        return $cached_data['data'];
    }

    /**
     * Write data to cache file, encoded with the site's cache_method unless $params asks for JSON.
     * Data the method can't encode, or a ttl that isn't a number of seconds, is not cached, and the
     * Result says so.
     * Dj_App_Cache::write();
     *
     * @param string $cache_file Path to cache file
     * @param mixed $data Data to cache
     * @param array $params Optional parameters (ttl, cache_method, etc)
     * @return Dj_App_Result
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function write($cache_file, $data, $params = [])
    {
        $res_obj = new Dj_App_Result();

        if (empty($data)) {
            return $res_obj;
        }

        if (isset($params['enabled']) && empty($params['enabled'])) {
            return $res_obj;
        }

        // Structure with meta and data sections
        $ttl = isset($params['ttl']) ? $params['ttl'] : Dj_App_Cache::DEFAULT_TTL;

        if (!is_numeric($ttl)) {
            $res_obj->msg = 'The cache ttl must be a number of seconds';
            $res_obj->code(Dj_App_Cache::CODE_BAD_TTL);

            return $res_obj;
        }

        $cache_data = [
            'meta' => [
                'created_at' => time(),
                'ttl' => $ttl,
            ],
            'data' => $data,
        ];

        $cache_method = Dj_App_Cache::getCacheMethod($params);

        if ($cache_method == Dj_App_Cache::METHOD_JSON) {
            // Objects become arrays first, so both methods give back the same values.
            $cache_data = Dj_App_Util::normalizeForSerialization($cache_data);

            try {
                $encoded_data = Dj_App_String_Util::jsonEncode($cache_data, Dj_App_Cache::JSON_ENCODE_FLAGS);
            } catch (JsonException $e) {
                $encoded_data = false;
                $res_obj->error_detail = $e->getMessage();
            }
        } else {
            $encoded_data = Dj_App_Util::serialize($cache_data);
        }

        if ($encoded_data === false) {
            $res_obj->msg = "Can't encode data";
            $res_obj->code(Dj_App_Cache::CODE_ENCODE_FAILED);

            // Logged too: data that can't be encoded is built again on every request, and nothing
            // else says why.
            $log_data = [
                'msg' => 'Cache data could not be encoded',
                'code' => Dj_App_Cache::CODE_ENCODE_FAILED,
                'cache_method' => $cache_method,
                'cache_file' => $cache_file,
                'error_detail' => $res_obj->error_detail,
            ];

            Dj_App_Log::error($log_data, __METHOD__);

            return $res_obj;
        }

        // Add protection header
        $cache_content = "<?php exit; " . Dj_App_Cache::$end_marker . $encoded_data;

        $result = Dj_App_File_Util::write($cache_file, $cache_content);

        return $result;
    }

    /**
     * Delete cache file
     * Dj_App_Cache::delete();
     *
     * @param string $cache_file Path to cache file
     * @return bool
     */
    public static function delete($cache_file)
    {
        // is_file() is the right check for unlink — true ONLY for regular files,
        // matching what unlink() can actually delete. Single stat call (PHP stat
        // cache merges this with the unlink). Returning true on missing file is
        // the desired idempotent behavior.
        if (!is_file($cache_file)) {
            return true;
        }

        return unlink($cache_file);
    }

    /**
     * Check if cache file exists and is valid
     * Dj_App_Cache::exists();
     *
     * @param string $cache_file Path to cache file
     * @return bool
     */
    public static function exists($cache_file)
    {
        return file_exists($cache_file);
    }
}

