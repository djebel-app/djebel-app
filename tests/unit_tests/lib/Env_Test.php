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

        putenv('DJEBEL_APP_ENV');
        putenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->backup_djebel_env === false) {
            putenv('DJEBEL_APP_ENV');
        } else {
            putenv('DJEBEL_APP_ENV=' . $this->backup_djebel_env);
        }

        if ($this->backup_app_env === false) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->backup_app_env);
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
}
