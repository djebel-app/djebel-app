<?php
/*
plugin_name: Init Throw Fixture
*/

// Throws from app.core.init, so the bootstrap tests see djebel's error answer on a page and in
// headless.
Dj_App_Hooks::addAction('app.core.init', ['Dj_App_Test_Init_Throw_Fixture', 'throwOnInit']);

class Dj_App_Test_Init_Throw_Fixture {
    /**
     * Fails the request the way a broken plugin would.
     * @param array $ctx
     * @return void
     * @throws Dj_App_Exception
     */
    public static function throwOnInit($ctx = []) {
        throw new Dj_App_Exception('init throw fixture');
    }
}
