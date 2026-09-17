<?php

/**
 *
 */
class Dj_App_Plugins {
    /**
     * @return string
     */
    public static function getPluginsDir()
    {
        $dir = Dj_App_Util::getContentDir() . '/plugins';
        $dir = Dj_App_Hooks::applyFilter( 'app.config.plugins_dir', $dir );
        return $dir;
    }

    /**
     * @return string
     */
    public static function getSysPluginsDir()
    {
        $dir = Dj_App_Util::getContentDir() . '/system_plugins';
        $dir = Dj_App_Hooks::applyFilter( 'app.core.system_plugins_dir', $dir );
        return $dir;
    }

    /**
     * Gets the plugins directory that we have for all sites /zzz_qs/djebel-app/app/shared_plugins/
     * Dj_App_Plugins::getSharedPluginsDir();
     * @return string
     */
    public static function getSharedPluginsDir()
    {
        $dir = Dj_App_Config::cfg('app.sys.plugins.shared_plugins_dir');
        $dir = Dj_App_Hooks::applyFilter( 'app.core.plugins.shared_plugins_dir', $dir );
        return $dir;
    }

    /**
     * Gets the non-public plugins directory (outside of document root)
     * Dj_App_Plugins::getNonPublicPluginsDir();
     * @return string
     */
    public static function getNonPublicPluginsDir()
    {
        $dir = Dj_App_Util::getCorePrivateDir(['app' => 'plugins']);
        $dir = Dj_App_Hooks::applyFilter( 'app.core.plugins.non_public_plugins_dir', $dir );
        return $dir;
    }

    /**
     * Loads regular or system plugins from a folder.
     * Dj_App_Plugins::loadPlugins();
     * @param array $params Associative array with 'dir' and optional context params
     * @return Dj_App_Result
     * @throws Dj_App_Exception
     */
    public static function loadPlugins($params = [])
    {
        $dir = empty($params['dir']) ? Dj_App_Plugins::getPluginsDir() : $params['dir'];
        $ctx = empty($params) ? [] : $params;

        $timer_id = __METHOD__ . sha1($dir.serialize($ctx));
        Dj_App_Util::microtime($timer_id);
        $res_obj = new Dj_App_Result();
        $plugins = [];
        $plugin_load_times = [];
        $options_obj = Dj_App_Options::getInstance();
        $plugins_options = $options_obj->get('plugins');
        $plugins_options = empty($plugins_options) ? [] : (array) $plugins_options;

        try {
            if (!is_dir($dir)) {
                throw new Dj_App_Exception("Plugin folder doesn't exist or is not readable", [ "dir" => $dir, ]);
            }

            // plugins must be in folders.
            $plugins_root_dirs = glob($dir . '/*', GLOB_ONLYDIR);

            foreach ($plugins_root_dirs as $idx => $plugin_dir) {
                $ctx = [];
                $ctx['plugin_dir'] = $plugin_dir;
                $plugin_dir = Dj_App_Hooks::applyFilter( 'app.plugin.dir', $plugin_dir, $ctx );

                // the plugin must be named exactly plugin.php file no need to check for other stuff.
                // for now the plugin is determined by the folder name.
                $plugin_dir_name = basename($plugin_dir);
                $plugin_id = Dj_App_Plugins::formatId($plugin_dir_name);
                $ctx['plugin_id'] = $plugin_id;
                $plugins_options = Dj_App_Hooks::applyFilter( 'app.plugin.options', $plugins_options, $ctx );

                // is this deactivated?
                // non-public plugins can still be deactivated via config
                if (isset($plugins_options[$plugin_id]['active']) && empty($plugins_options[$plugin_id]['active'])) {
                    continue;
                }

                // @todo check for a prefix(es) for plugins $options_obj; /contact form plugins must run on /contact page onnly

                $plugin_file = $plugin_dir . '/plugin.php';
                $plugin_file_rel = Dj_App_Plugins::getRelFile($plugin_file);

                $prefix = "[$idx] plugin [$plugin_file_rel]";

                if (!file_exists($plugin_file)) {
                    // @todo log error
                    $res_obj->data($prefix, "main plugin file not found");
                    continue;
                }

                $partial_plugin_header_res_obj = Dj_App_File_Util::readPartially($plugin_file);

                if ($partial_plugin_header_res_obj->isError()) {
                    // @todo log error
                    $res_obj->data($prefix, $partial_plugin_header_res_obj->msg);
                    continue;
                }

                $buff = $partial_plugin_header_res_obj->output;
                $extr_res = Dj_App_Util::extractMetaInfo($buff);

                // missing meta info in a system plugin file is not an error.
                if (empty($ctx['is_system']) && $extr_res->isError()) {
                    $res_obj->data($prefix, $partial_plugin_header_res_obj->msg);
                    // @todo log error
                    continue;
                }

                if (!empty($extr_res->plugin_id)) {
                    $plugin_id = Dj_App_Plugins::formatId($extr_res->plugin_id);

                    // check for activeness using internal plugin id
                    if (isset($plugins_options[$plugin_id]['active']) && empty($plugins_options[$plugin_id]['active'])) {
                        continue;
                    }
                }

                // if the plugin requires a higher version of the php skip it.
                if (!empty($extr_res->min_php_ver) && version_compare(PHP_VERSION, $extr_res->min_php_ver, '<')) {
                    $res_obj->data($prefix, "PHP version is too low. Required: {$extr_res->min_php_ver}");
                    continue;
                }

                // core, system, or non-public plugins don't need to be active to be loaded.
                if (!empty($ctx['is_system']) || !empty($ctx['is_core']) || !empty($ctx['is_nonpublic'])) {
                    // ok
                } else {
                    // Check for active plugins
                    $active_plugins = empty($ctx['active_plugins']) ? [] : $ctx['active_plugins'];

                    if (!empty($active_plugins) && empty($active_plugins[$plugin_file_rel])) {
                        continue;
                    }
                }

                $plugin_meta_info = $extr_res->data();
                $plugin_meta_info['plugin_file'] = $plugin_file;

                // Carried so the load loop names a plugin the same way the activation check
                // and the options filter do, instead of deriving a second id of its own.
                $plugin_meta_info['plugin_id'] = $plugin_id;

                $plugins[$plugin_file] = $plugin_meta_info;
            }

            // sort by (load) priority
            uasort( $plugins, 'Dj_App_Util::sortByPriority' );

            // Read ONCE: it cannot change between two plugins, and re-reading it per plugin
            // spends a config lookup on every one of them to learn the same answer.
            $continue_after_crash = Dj_App_Config::cfg('app.core.plugins.continue_loading', true);

            foreach ($plugins as $plugin_file => $plugin_meta_info) {
                $plugin_crashed = false;

                try {
                    $load_time = Dj_App_Util::microtime($plugin_file);
                    include_once $plugin_file;
                } catch (Throwable $e) {
                    $plugin_crashed = true;

                    // A crashed plugin must leave a trace — full detail (message,
                    // file, line, trace) goes to the app error log. The page shows
                    // the raw message only on a dev/debug setup.
                    // Deliberately unchecked: the crash is reported to the visitor either
                    // way, and there is nothing to do differently when the write fails.
                    Dj_App_Log::logAppError($e);

                    // The id resolved during discovery — the same string the activation check
                    // and the options filter use, so what the box names is what a site owner
                    // would put in config. Every plugin's main file carries the same name, so
                    // that name on its own identifies nothing.
                    $plugin_id = Dj_App_Util::getField('plugin_id', $plugin_meta_info);
                    $plugin_id_esc = dj_esc_html($plugin_id);

                    $is_debug = Dj_App_Config::cfg('app.debug', false);

                    if (empty($is_debug)) {
                        // The same either way. Whether the line got written is this box's
                        // business, and a visitor reading two different sentences learns
                        // only how it is configured.
                        $msg = 'error';
                    } else {
                        $msg = $e->getMessage();
                        $msg = dj_esc_html($msg);
                    }

                    $error_msg = sprintf('Plugin [%s] crashed: %s', $plugin_id_esc, $msg);

                    // Shown so what the visitor quotes locates the entry — the same value
                    // stamped inside it. Always, because it names THIS request whether or
                    // not a line was written, and the other entries from the same run
                    // carry it too.
                    $req_id = Dj_App_Util::reqId();
                    $req_id_esc = dj_esc_html($req_id);
                    $error_msg = sprintf('%s (ref: %s)', $error_msg, $req_id_esc);

                    // The whole line goes THROUGH msg(), not beside it — the prefix used to be
                    // echoed as bare text next to the box, leaving half the sentence unstyled.
                    echo Dj_App_Util::msg($error_msg);
                } finally {
                    $load_time = Dj_App_Util::microtime($plugin_file);
                    $plugin_load_times[$plugin_file] = $load_time;
                }
                
                // The config decides what happens AFTER a crash — that is the whole question
                // it answers. Consulted only when one actually happened, so a clean run is
                // never cut short by it.
                if ($plugin_crashed && !$continue_after_crash) {
                    break;
                }

                // A plugin can stop the run for reasons of its own, so the filter is asked
                // after every load — but ONLY when something is listening. Its context costs
                // a search plus a slice plus two key-scans of the whole list, per plugin,
                // and with no listener every bit of that is thrown away.
                if (!Dj_App_Hooks::hasFilter('app.core.plugins.continue_loading')) {
                    continue;
                }

                $plugin_names = array_keys($plugins);
                $plugin_idx = array_search($plugin_file, $plugin_names);
                $remaining_plugins = array_slice($plugin_names, $plugin_idx + 1);

                $load_ctx = [];
                $load_ctx['current_plugin'] = $plugin_file;
                $load_ctx['current_plugin_meta'] = $plugin_meta_info;
                $load_ctx['loaded_plugins'] = array_keys($plugin_load_times);
                $load_ctx['remaining_plugins'] = $remaining_plugins;

                $continue_loading = Dj_App_Hooks::applyFilter('app.core.plugins.continue_loading', true, $load_ctx);

                if (!$continue_loading) {
                    break;
                }
            }

            $res_obj->status(1);
        } catch (Dj_App_Exception $e) {
            $res_obj->msg = $e->getMessage();
        } finally {
            $res_obj->plugins = $plugins;
            $res_obj->exec_time = Dj_App_Util::microtime( $timer_id );
            $res_obj->plugin_load_times = $plugin_load_times;
        }

        return $res_obj;
    }

    /**
     * Format an ID allowing hyphens using formatStringId from String_util
     * @param string $id
     * @return string
     */
    public static function formatId($id) {
        return Dj_App_String_Util::formatStringId($id, Dj_App_String_Util::KEEP_DASH);
    }

    /**
     * Dj_App_Plugins::getRelFile
     * @param string $file
     * @return string
     */
    public static function getRelFile($file)
    {
        $plugins_dir = Dj_App_Plugins::getPluginsDir();
        $rel_file = $file;
        $rel_file = str_replace($plugins_dir, '', $rel_file);

        return $rel_file;
    }
}
