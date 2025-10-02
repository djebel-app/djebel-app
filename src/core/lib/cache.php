<?php
/**
 * Simple file-based cache utility
 * Dj_App_Cache
 */
class Dj_App_Cache
{
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

        // Check structure and TTL
        if (is_array($cached_data) && isset($cached_data['meta']) && isset($cached_data['data'])) {
            $meta = $cached_data['meta'];

            // Check expiration if TTL is set
            if (isset($meta['ttl']) && $meta['ttl'] > 0 && isset($meta['created_at'])) {
                $expires_at = $meta['created_at'] + $meta['ttl'];

                if (time() > $expires_at) {
                    self::delete($cache_file);
                    return null;
                }
            }

            return $cached_data['data'];
        }

        // Fallback for old format or direct data
        return $cached_data;
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

