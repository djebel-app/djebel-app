<?php

/**
 * Loads private app libraries on demand — lazy and hookless, unlike Dj_App_Plugins (which
 * scans a folder and registers plugins). A lib is a single class file living at
 * .ht_djebel/app/lib/<id>/lib.php and is required only when a caller actually needs it.
 */
class Dj_App_Lib {
    const ENTRY_FILE = 'lib.php';

    /**
     * Lazily loads a private app library on demand (from .ht_djebel/app/lib/<id>/lib.php).
     * Unlike Dj_App_Plugins::loadPlugins() this is an explicit, lazy require — no meta, no hooks.
     * $lib may be one id, a separator-delimited list ("a, b | c"), or an array of ids. A token may
     * be a glob: "*" loads every lib, "orbisius*" every lib whose id starts with "orbisius". An
     * enable flag ("1"/"true"/"on") is shorthand for "*". A bad id is a caller bug and throws; a
     * valid-but-absent lib is soft-skipped (the "load it if it's there" case).
     * Dj_App_Lib::loadLib('djebel-core-lib-http');
     * @param string|array $lib
     * @param array $extra_opts
     * @return Dj_App_Result
     * @throws Dj_App_Exception
     */
    public static function loadLib($lib = '', $extra_opts = [])
    {
        $res_obj = new Dj_App_Result();

        if (empty($lib)) {
            return $res_obj;
        }

        // An enable flag ("1"/"true"/"on") means load every lib — the same as the "*" glob.
        if (!is_array($lib) && Dj_App_Util::isEnabled($lib)) {
            $lib = '*';
        }

        // Accept one id, a separator-delimited list ("a, b | c"), or an array of ids/globs.
        if (is_array($lib)) {
            $tokens = $lib;
        } else {
            $tokens = Dj_App_String_Util::splitOnSeparators($lib);
        }

        $lib_dir = empty($extra_opts['dir']) ? '' : $extra_opts['dir'];

        if (empty($lib_dir)) {
            $lib_dir = Dj_App_Util::getCorePrivateDir(['app' => 'lib']);
        }

        // Split tokens in ONE pass. A plain token ("djebel-core-lib-http") is an exact id, validated
        // right here — a malformed id is a caller bug / injection attempt, so throw before any dir
        // scan or require. A glob ("*" = all, "orbisius*" = prefix) matches against the dir's libs;
        // only a glob needs the scan, so a plain-id list never touches the filesystem here.
        $ids = [];
        $globs = [];

        foreach ($tokens as $token) {
            if (strpos($token, '*') !== false) {
                $globs[] = $token;
                continue;
            }

            if (!Dj_App_String_Util::isAlphaNumericExt($token)) {
                throw new Dj_App_Exception('Invalid lib id', ['id' => $token]);
            }

            $ids[] = $token;
        }

        if (!empty($globs)) {
            $dir_ids = [];

            if (is_dir($lib_dir)) {
                $scan_result = scandir($lib_dir);
                $dir_entries = empty($scan_result) ? [] : $scan_result;

                foreach ($dir_entries as $dir_entry) {
                    // Skip non-libs cheaply, before the is_dir() stat. Check the leading char first
                    // (O(1)) — "." / ".." / hidden entries are always present; str_contains then
                    // catches an interior dot, which also can't be a lib id (a "mylib.bak" /
                    // "mylib.old" artifact). The zzz_skip_ prefix parks a lib out of a bulk load.
                    if ($dir_entry[0] == '.' || str_contains($dir_entry, '.') || str_starts_with($dir_entry, 'zzz_skip_')) {
                        continue;
                    }

                    $lib_sub_dir = $lib_dir . '/' . $dir_entry;

                    if (is_dir($lib_sub_dir)) {
                        $dir_ids[] = $dir_entry;
                    }
                }
            }

            foreach ($globs as $glob) {
                foreach ($dir_ids as $dir_id) {
                    if (Dj_App_String_Util::matchesPattern($dir_id, $glob)) {
                        $ids[] = $dir_id;
                    }
                }
            }
        }

        $ids = array_unique($ids);
        $entry_file = self::ENTRY_FILE;

        foreach ($ids as $id) {
            $lib_file = $lib_dir . '/' . $id . '/' . $entry_file;

            // Absent = the graceful "load it if it's there" case — soft, never an error. Ids are
            // already validated: exact ids at the split above, glob matches come from the dir itself.
            if (is_file($lib_file)) {
                require_once $lib_file;
            }
        }

        $res_obj->status = true;

        return $res_obj;
    }
}
