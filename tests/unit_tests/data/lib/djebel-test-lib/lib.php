<?php

// Test fixture: a minimal lib entry loaded by Dj_App_Plugins::loadLib().
// Guarded declaration mirrors the real lib convention (a pre-defined/custom impl wins).
if (!class_exists('Djebel_Test_Lib')) {
    class Djebel_Test_Lib {}
}
