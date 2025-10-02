<?php
/**
 * Simple file-based cache utility
 * Dj_App_Cache
 */
class Dj_App_Cache
{
    /**
     * Get cached data by key
     * Dj_App_Cache::get();
     *
     * @param string|array $key Cache key (string or array for namespacing)
     * @param array $params Optional parameters
     * @return mixed|null Cached data or null if invalid/not found/expired
     */
    public static function get($key, $params = [])
    {
        $ctx = ['key' => $key, 'params' => $params];

        $result = [];
        $result = Dj_App_Hooks::applyFilter('app.cache.pre_get.data', $result, $ctx);

        if (!empty($result)) {
            return $result;
        }

        $cache_file = self::getCacheFile($key, $params);
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.get.file', $cache_file, $ctx);

        $result = self::read($cache_file);
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
     */
    public static function set($key, $data, $params = [])
    {
        $cache_file = self::getCacheFile($key, $params);

        $ctx = ['key' => $key, 'params' => $params];
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.set.file', $cache_file, $ctx);

        $data = Dj_App_Hooks::applyFilter('app.cache.set.data', $data, $ctx);
        $result = self::write($cache_file, $data, $params);

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

        $cache_file = self::getCacheFile($key, $params);
        $cache_file = Dj_App_Hooks::applyFilter('app.cache.remove.file', $cache_file, $ctx);

        $result = self::delete($cache_file);

        return $result;
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

        $cache_dir = self::getCacheDir($cache_dir_params);
        $cache_file = $cache_dir . '/' . $key . '.cache';

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

        $dir .= '/cache';

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
     * Read data from cache file
     * Dj_App_Cache::read();
     *
     * @param string $cache_file Path to cache file
     * @return mixed|null Cached data or null if invalid/not found/expired
     */
    public static function read($cache_file)
    {
        if (!file_exists($cache_file)) {
            return null;
        }

        $cache_content = Dj_App_File_Util::read($cache_file);

        if (empty($cache_content)) {
            return null;
        }

        $cached_data = Dj_App_Util::unserialize($cache_content);

        if ($cached_data === false) {
            return null;
        }

        // Check structure
        if (!is_array($cached_data) || empty($cached_data['meta']) || empty($cached_data['data'])) {
            return $cached_data;
        }

        $meta = $cached_data['meta'];

        // Check expiration if TTL is set
        if (!empty($meta['ttl']) && !empty($meta['created_at'])) {
            $expires_at = $meta['created_at'] + $meta['ttl'];

            if (time() > $expires_at) {
                self::delete($cache_file);
                return null;
            }
        }

        return $cached_data['data'];
    }

    /**
     * Write data to cache file
     * Dj_App_Cache::write();
     *
     * @param string $cache_file Path to cache file
     * @param mixed $data Data to cache
     * @param array $params Optional parameters (ttl, etc)
     * @return Dj_App_Result
     */
    public static function write($cache_file, $data, $params = [])
    {
        $res_obj = new Dj_App_Result();

        // Structure with meta and data sections
        $ttl = isset($params['ttl']) ? $params['ttl'] : 4 * 60 * 60; // default 4 hours

        $cache_data = [
            'meta' => [
                'created_at' => time(),
                'ttl' => $ttl,
            ],
            'data' => $data,
        ];

        $cache_content = Dj_App_Util::serialize($cache_data);

        if ($cache_content === false) {
            $res_obj->msg = "Can't serialize data";
            return $res_obj;
        }

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
        if (!file_exists($cache_file)) {
            return true;
        }

        return @unlink($cache_file);
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

