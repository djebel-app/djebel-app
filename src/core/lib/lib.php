<?php

/**
 * Loads private app libraries on demand — lazy and hookless, unlike Dj_App_Plugins (which
 * scans a folder and registers plugins). A lib is a single class file living at
 * .ht_djebel/app/lib/<id>/lib.php and is required only when a caller actually needs it.
 */
class Dj_App_Lib {
    /**
     * Lazily loads a private app library on demand (from .ht_djebel/app/lib/<id>/lib.php).
     * Unlike Dj_App_Plugins::loadPlugins() this is a single, explicit, lazy require — no scan, no
     * meta, no hooks. $lib may be one id or an array of ids. A bad id or entry is a caller bug and
     * throws; a valid-but-absent lib is soft-skipped (the "load it if it's there" case).
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

        $ids = (array) $lib;
        $entry_file = empty($extra_opts['entry']) ? 'lib.php' : $extra_opts['entry'];
        $lib_dir = empty($extra_opts['dir']) ? '' : $extra_opts['dir'];

        $entry_file = basename($entry_file);
        $entry_ext = Dj_App_File_Util::getExt($entry_file);

        // A non-.php entry is a caller bug — fail loud.
        if ($entry_ext != 'php') {
            throw new Dj_App_Exception('Lib entry must be a .php file', ['entry' => $entry_file]);
        }

        if (empty($lib_dir)) {
            $lib_dir = Dj_App_Util::getCorePrivateDir(['app' => 'lib']);
        }

        foreach ($ids as $id) {
            // A malformed id is a programmer error / injection attempt — throw ASAP, never skip.
            if (!Dj_App_String_Util::isAlphaNumericExt($id)) {
                throw new Dj_App_Exception('Invalid lib id', ['id' => $id]);
            }

            $lib_file = $lib_dir . '/' . $id . '/' . $entry_file;

            // Absent = the graceful "load it if it's there" case — soft, like a missing plugin.php.
            if (is_file($lib_file)) {
                require_once $lib_file;
            }
        }

        $res_obj->status = true;

        return $res_obj;
    }
}
