<?php
/*
plugin_name: Crash Fixture
*/

// Throws while it loads, so the bootstrap tests see what a crash prints.
throw new Dj_App_Exception('crash fixture');
