<?php

// A system plugin for the bootstrap tests. It marks the request's segments, so a test sees
// whether a request parse ran after this plugin loaded.
Dj_App_Hooks::addFilter('app.core.request.parse', ['Dj_App_Test_Sys_Fixture', 'filterRequestParse']);

class Dj_App_Test_Sys_Fixture {
    /**
     * Adds a marker segment to the parsed request.
     * @param array $data
     * @param array $ctx
     * @return array
     */
    public static function filterRequestParse($data, $ctx = []) {
        $data['segments'][] = 'sys_fixture_parsed';

        return $data;
    }
}
