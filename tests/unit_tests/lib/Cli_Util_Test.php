<?php

use PHPUnit\Framework\TestCase;

// Load CLI utility class (only loaded on demand for CLI tools)
require_once dirname(dirname(dirname(__DIR__))) . '/src/core/lib/cli_util.php';

class Cli_Util_Test extends TestCase {

    public function testNormalizeArgsWithHyphenatedArgumentsAndValues()
    {
        $input = ['--bundle-id=test', '--bundle-ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMixedHyphensAndUnderscores()
    {
        $input = ['--bundle-id=test', '--bundle_ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithAlreadyNormalizedArguments()
    {
        $input = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithFlagsWithoutValues()
    {
        $input = ['--help', '--verbose'];
        $expected = ['--help', '--verbose'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithHyphenatedFlags()
    {
        $input = ['--dry-run', '--force-update'];
        $expected = ['--dry_run', '--force_update'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithNonOptionArguments()
    {
        $input = ['help', 'test', '-h'];
        $expected = ['help', 'test', '-h'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsPreservesHyphensInValues()
    {
        $input = ['--bundle-id=my-test-bundle', '--dir=some-path'];
        $expected = ['--bundle_id=my-test-bundle', '--dir=some-path'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithEmptyArray()
    {
        $input = [];
        $expected = [];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMultipleHyphensInKeyName()
    {
        $input = ['--my-long-option-name=value'];
        $expected = ['--my_long_option_name=value'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMixedArgumentTypes()
    {
        $input = ['script.php', '--bundle-id=test', 'positional', '--dir=path', '--help'];
        $expected = ['script.php', '--bundle_id=test', 'positional', '--dir=path', '--help'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithEmptyValue()
    {
        $input = ['--bundle-id=', '--dir='];
        $expected = ['--bundle_id=', '--dir='];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithSpecialCharactersInValue()
    {
        $input = ['--description=This is a test!', '--path=/usr/local/bin'];
        $expected = ['--description=This is a test!', '--path=/usr/local/bin'];
        $result = Dj_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }
}
