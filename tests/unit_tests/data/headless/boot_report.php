<?php

// Boots djebel from the dir given as the first argument, the way an API or a site would, then
// prints one report line after a marker: which fixture plugins loaded, which init action fired,
// and the request's segments. Whatever the boot itself printed comes before the marker. A boot
// that exits (the static-file 404) never reaches the report.
$djebel_dir = $argv[1];

require $djebel_dir . '/index.php';

$fixture_classes = [
    'Dj_App_Test_Sys_Fixture',
    'Dj_App_Test_Regular_Fixture',
    'Dj_App_Test_Private_Fixture',
    'Dj_App_Test_Url_Fixture',
];

$loaded_classes = [];

foreach ($fixture_classes as $fixture_class) {
    if (class_exists($fixture_class, false)) {
        $loaded_classes[] = $fixture_class;
    }
}

$core_init_fired = Dj_App_Hooks::hasRun('app.core.init');
$headless_init_fired = Dj_App_Hooks::hasRun('app.core.headless.init');
$req_obj = Dj_App_Request::getInstance();
$segments = $req_obj->segments();

$report = [
    'loaded_classes' => $loaded_classes,
    'core_init_fired' => $core_init_fired,
    'headless_init_fired' => $headless_init_fired,
    'segments' => $segments,
];

$report_json = json_encode($report);

echo "\nDJ_BOOT_REPORT:" . $report_json . "\n";
