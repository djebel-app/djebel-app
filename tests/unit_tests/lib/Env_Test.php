<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Dj_App_Env class — per-source-file home for all its method tests.
 */
class Dj_App_Env_Test extends TestCase
{
    private $backup_djebel_env = false;
    private $backup_app_env = false;

    protected function setUp(): void
    {
        $this->backup_djebel_env = getenv('DJEBEL_APP_ENV');
        $this->backup_app_env = getenv('APP_ENV');

        // Cleared through the framework, not putenv: the resolved environment is remembered
        // for the request, and only this path drops it. A raw putenv would leave the previous
        // test's answer standing while the variable behind it was already gone.
        Dj_App_Env::set('APP_ENV', null);
        Dj_App_Env::set('DJEBEL_APP_ENV', null);
    }

    protected function tearDown(): void
    {
        // Restored through the framework for the same reason setUp() clears through it: a raw
        // putenv puts the variable back but leaves the resolved environment remembered, so the
        // next class to ask would be answered with this class's value for a name that is no
        // longer set. false means the variable was absent, and null is how set() removes one.
        $djebel_env = $this->backup_djebel_env === false ? null : $this->backup_djebel_env;
        Dj_App_Env::set('DJEBEL_APP_ENV', $djebel_env);

        $app_env = $this->backup_app_env === false ? null : $this->backup_app_env;
        Dj_App_Env::set('APP_ENV', $app_env);
    }

    /**
     * Whatever an install declares is normalized before anything compares against it, so a
     * name typed in caps or with stray whitespace still answers the predicates.
     */
    public function testGetAppEnvNormalizesTheDeclaredName()
    {
        Dj_App_Env::set('DJEBEL_APP_ENV', '  LIVE  ');

        $env_name = Dj_App_Env::getAppEnv();

        $this->assertEquals('live', $env_name);
        $this->assertTrue(Dj_App_Env::isLive());
        $this->assertFalse(Dj_App_Env::isDev());
    }

    /**
     * The filter is the whole point of the seam — a fleet answers for every install at once,
     * by host or by install dir — so its word outranks what the install declared for itself.
     */
    public function testGetAppEnvFilterOutranksTheDeclaredName()
    {
        Dj_App_Env::set('DJEBEL_APP_ENV', 'live');
        Dj_App_Hooks::addFilter(Dj_App_Env::FILTER_ENV_NAME, ['Dj_App_Env_Test', 'filterEnvNameToDev']);

        try {
            $env_name = Dj_App_Env::getAppEnv();

            $this->assertEquals('dev', $env_name);
            $this->assertTrue(Dj_App_Env::isDev());
            $this->assertFalse(Dj_App_Env::isLive());
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Env::FILTER_ENV_NAME, ['Dj_App_Env_Test', 'filterEnvNameToDev']);
            $this->assertTrue($removed, 'The env name filter leaked out of the test');
        }
    }

    /**
     * The resolved name is remembered for the whole request, so the drop inside set() is the
     * only thing keeping a later change from being answered with the earlier one.
     */
    public function testSetDropsTheRememberedEnvName()
    {
        Dj_App_Env::set('DJEBEL_APP_ENV', 'live');
        $this->assertEquals('live', Dj_App_Env::getAppEnv());

        Dj_App_Env::set('DJEBEL_APP_ENV', 'dev');
        $this->assertEquals('dev', Dj_App_Env::getAppEnv());
    }

    /**
     * The bootstrap primes REQUEST_METHOD and REQUEST_URI to fake a web request, so the CLI
     * check is the only thing left answering false here — drop it and this case fails.
     */
    public function testIsWebRequestIsFalseUnderCli()
    {
        $this->assertFalse(Dj_App_Env::isWebRequest(), 'The suite runs in CLI, so nothing is serving a request');
    }

    /**
     * The SAPI is matched exactly, so widening the comparison to catch 'cli-server' — the
     * built-in web server, which serves real HTTP — stops answering this one.
     *
     * The cli-server case itself cannot be exercised here: PHP_SAPI is a constant, so the
     * suite can only ever run under one of them.
     */
    public function testIsCliUnderTheCliSapi()
    {
        $this->assertTrue(Dj_App_Env::isCli(), 'The suite runs under the cli SAPI');
    }

    /**
     * @param string $cur_val
     * @param array $ctx
     * @return string
     */
    public static function filterEnvNameToDev($cur_val, $ctx = [])
    {
        return 'dev';
    }

    public function testGetEnvAlternatives()
    {
        try {
            Dj_App_Env::set('DJ_TEST_ENV_ALT_SECOND', 'second_val');

            // Array of alternatives — the first SET name wins, misses are skipped.
            $alt_names = [ 'DJ_TEST_ENV_ALT_MISSING', 'DJ_TEST_ENV_ALT_SECOND', ];
            $this->assertEquals('second_val', Dj_App_Env::getEnv($alt_names));

            // CSV spelling of the same alternatives.
            $this->assertEquals('second_val', Dj_App_Env::getEnv('DJ_TEST_ENV_ALT_MISSING,DJ_TEST_ENV_ALT_SECOND'));

            // ORDER: an earlier set name beats a later one.
            Dj_App_Env::set('DJ_TEST_ENV_ALT_FIRST', 'first_val');
            $ordered_names = [ 'DJ_TEST_ENV_ALT_FIRST', 'DJ_TEST_ENV_ALT_SECOND', ];
            $this->assertEquals('first_val', Dj_App_Env::getEnv($ordered_names));

            // set() with no value REMOVES — the variable is gone, not set to empty.
            Dj_App_Env::set('DJ_TEST_ENV_ALT_FIRST');
            $this->assertFalse(getenv('DJ_TEST_ENV_ALT_FIRST'));

            // All misses come back empty.
            $this->assertEmpty(Dj_App_Env::getEnv([ 'DJ_TEST_ENV_ALT_MISSING', ]));
        } finally {
            Dj_App_Env::set('DJ_TEST_ENV_ALT_FIRST');
            Dj_App_Env::set('DJ_TEST_ENV_ALT_SECOND');
        }
    }

    public function testGetEnvConstSingleKey()
    {
        putenv('APP_ENV=abc');

        $result = Dj_App_Env::getEnvConst('APP_ENV');
        $this->assertEquals('abc', $result);
    }

    public function testGetEnvConstCsvFallsBackToSecondKey()
    {
        putenv('APP_ENV=dev');

        $result = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');
        $this->assertEquals('dev', $result);
    }

    public function testGetEnvConstCsvFirstKeyWins()
    {
        putenv('DJEBEL_APP_ENV=live');
        putenv('APP_ENV=dev');

        $result = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV');
        $this->assertEquals('live', $result);
    }

    public function testGetEnvConstCsvUsesDefaultWhenNoneSet()
    {
        $result = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV', 'fallback');
        $this->assertEquals('fallback', $result);
    }

    public function testGetEnvConstPreservesZeroValue()
    {
        // A legit '0' value must win over the default AND stop the CSV fallback chain.
        putenv('DJEBEL_APP_ENV=0');
        putenv('APP_ENV=dev');

        $result = Dj_App_Env::getEnvConst('DJEBEL_APP_ENV,APP_ENV', 'fallback');
        $this->assertSame('0', $result);
    }

    public function testSetSingleKeyValue()
    {
        $result = Dj_App_Env::set('djebel_env_test_key', 'env_val_1');
        $this->assertTrue($result);

        $env_val = Dj_App_Env::getEnv('djebel_env_test_key');
        $this->assertEquals('env_val_1', $env_val);

        putenv('DJEBEL_ENV_TEST_KEY');
    }

    public function testSetArrayUppercasesKeys()
    {
        $env_vars = [ 'djebel_env_test_key' => 'env_val_2', ];

        $result = Dj_App_Env::set($env_vars);
        $this->assertTrue($result);

        // Lowercase key — stored uppercased, which is how getEnv() finds it.
        $env_val = Dj_App_Env::getEnv('djebel_env_test_key');
        $this->assertEquals('env_val_2', $env_val);

        putenv('DJEBEL_ENV_TEST_KEY');
    }

    public function testSetRejectsEmptyInput()
    {
        $env_vars = [];

        $result = Dj_App_Env::set($env_vars);
        $this->assertFalse($result);
    }
}
