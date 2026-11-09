<?php
/**
 * Simple file-based cache utility
 * Dj_App_Cache
 */
class Dj_App_Cache
{
    const METHOD_PHP_SERIALIZE = 'php_serialize';
    const METHOD_JSON = 'json';

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

        $result = Dj_App_Cache::read($cache_file);
        $result = Dj_App_Hooks::applyFilter('app.cache.get.data', $result, $ctx);

        return $result;
    }

    /**
     * Set cached data by key
     * Dj_App_Cache::set();
     *
     * @param string|array $key Cache key (string or array for namespacing)
     * @param mixed $data Data to cache
     * @param array $params Optional parameters (ttl, etc)
     * @return Dj_App_Result
     * @throws Dj_App_Exception when cache_method names no known method
     */
    public static function set($key, $data, $params = [])
    {
        $cache_file = Dj_App_Cache::getCacheFile($key, $params);

        $ctx = ['key' => $key, 'params' => $params];
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.set.file', $cache_file, $ctx);

        $data = Dj_App_Hooks::applyFilter('app.cache.set.data', $data, $ctx);
        $result = Dj_App_Cache::write($cache_file, $data, $params);

        return $result;
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

        $result = Dj_App_Cache::delete($cache_file);

        return $result;
    }

    /**
     * Delete all cache files in a directory (e.g., all plugin cache)
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

        if (!is_dir($cache_dir)) {
            return true;
        }

        $ctx = ['params' => $params];
        Dj_App_Hooks::doAction('app.cache.pre_remove_all', $ctx);

        $scan_result = scandir($cache_dir);
        $exclude_items = [ '.', '..', ];
        $files = array_diff($scan_result, $exclude_items);

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
     * Case, spaces and punctuation are forgiven; any other name throws, so a typo can't quietly
     * leave a site on the method it meant to leave.
     * Dj_App_Cache::getCacheMethod();
     *
     * @return string Dj_App_Cache::METHOD_PHP_SERIALIZE or Dj_App_Cache::METHOD_JSON
     * @throws Dj_App_Exception
     */
    public static function getCacheMethod()
    {
        $options_obj = Dj_App_Options::getInstance();
        $cache_method = $options_obj->get('app.cache_method');

        if (empty($cache_method)) {
            $cache_method = Dj_App_Config::cfg('app.core.cache_method');
        }

        $cache_method = Dj_App_Hooks::applyFilter('app.cache.method', $cache_method);

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
     * Read data from cache file, decoded with the site's cache_method. A file in the other method,
     * a damaged one, or one write() did not make reads as a miss.
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

        $cache_method = Dj_App_Cache::getCacheMethod();

        if ($cache_method == Dj_App_Cache::METHOD_JSON) {
            $cached_data = Dj_App_String_Util::jsonDecode($cache_content);
        } else {
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
     * Write data to cache file, encoded with the site's cache_method. Data the method can't
     * encode, or a ttl that isn't a number of seconds, is not cached, and the Result says so.
     * Dj_App_Cache::write();
     *
     * @param string $cache_file Path to cache file
     * @param mixed $data Data to cache
     * @param array $params Optional parameters (ttl, etc)
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
        $ttl = isset($params['ttl']) ? $params['ttl'] : 4 * 60 * 60; // default 4 hours

        if (!is_numeric($ttl)) {
            $res_obj->msg = 'The cache ttl must be a number of seconds';
            return $res_obj;
        }

        $cache_data = [
            'meta' => [
                'created_at' => time(),
                'ttl' => $ttl,
            ],
            'data' => $data,
        ];

        $cache_method = Dj_App_Cache::getCacheMethod();

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

            // Logged too: data that can't be encoded is built again on every request, and nothing
            // else says why.
            $log_data = [
                'msg' => 'Cache data could not be encoded',
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

