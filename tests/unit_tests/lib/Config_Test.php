<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Dj_App_Config class (defined in index.php — it has no own lib file).
 * Per-source-file home: EVERY Dj_App_Config method's tests belong here, not in new
 * per-feature files. Fixtures live in unit_tests/data/.
 */
class Dj_App_Config_Test extends TestCase
{
    public function testLoadIniFileWithMissingFile()
    {
        $missing_file = DJEBEL_APP_TEST_DATA_DIR . '/config_missing_nope.ini';
        $result = Dj_App_Config::loadIniFile($missing_file);

        $this->assertSame([], $result);
    }

    public function testLoadIniFileWithMalformedIni()
    {
        // Regression: parse_ini_file returns FALSE on a malformed file (e.g. a '#'
        // pseudo-comment with parens — '#' is NOT an ini comment); passing that to
        // array_change_key_case() was a fatal TypeError. Must bail out empty instead.
        $bad_file = DJEBEL_APP_TEST_DATA_DIR . '/config_bad.ini';

        // @ mutes parse_ini_file's own syntax warning — the fatal is what's under test.
        $result = @Dj_App_Config::loadIniFile($bad_file);

        $this->assertSame([], $result);
    }

    public function testLoadIniFileSetsEnvVarsUppercased()
    {
        $env_file = DJEBEL_APP_TEST_DATA_DIR . '/config_env.ini';

        Dj_App_Config::loadIniFile($env_file);

        // The fixture key is lowercase — keys are uppercased before putenv.
        $env_val = getenv('DJEBEL_CONFIG_TEST_KEY');
        $this->assertEquals('cfg_val_1', $env_val);

        putenv('DJEBEL_CONFIG_TEST_KEY');
    }
}
