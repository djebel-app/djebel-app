<?php

// Test fixture: a PARKED lib (zzz_skip_ prefix). Dj_App_Lib::loadLib() must skip it on a bulk
// "*"/glob load, so Djebel_Zzz_Skip_Disabled_Lib should never be defined by those loads.
if (!class_exists('Djebel_Zzz_Skip_Disabled_Lib')) {
    class Djebel_Zzz_Skip_Disabled_Lib {}
}
