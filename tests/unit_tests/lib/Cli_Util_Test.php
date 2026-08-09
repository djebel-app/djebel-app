<?php

use PHPUnit\Framework\TestCase;

// Load CLI utility class (only loaded on demand for CLI tools)
require_once dirname(dirname(dirname(__DIR__))) . '/src/core/lib/cli_util.php';

class Dj_App_Cli_Util_Test extends TestCase {

    public function testNormalizeArgsWithHyphenatedArgumentsAndValues()
    {
        $input = ['--bundle-id=test', '--bundle-ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMixedHyphensAndUnderscores()
    {
        $input = ['--bundle-id=test', '--bundle_ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithAlreadyNormalizedArguments()
    {
        $input = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $expected = ['--bundle_id=test', '--bundle_ver=1.0.0'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithFlagsWithoutValues()
    {
        $input = ['--help', '--verbose'];
        $expected = ['--help', '--verbose'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithHyphenatedFlags()
    {
        $input = ['--dry-run', '--force-update'];
        $expected = ['--dry_run', '--force_update'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithNonOptionArguments()
    {
        $input = ['help', 'test', '-h'];
        $expected = ['help', 'test', '-h'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsPreservesHyphensInValues()
    {
        $input = ['--bundle-id=my-test-bundle', '--dir=some-path'];
        $expected = ['--bundle_id=my-test-bundle', '--dir=some-path'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithEmptyArray()
    {
        $input = [];
        $expected = [];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMultipleHyphensInKeyName()
    {
        $input = ['--my-long-option-name=value'];
        $expected = ['--my_long_option_name=value'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithMixedArgumentTypes()
    {
        $input = ['script.php', '--bundle-id=test', 'positional', '--dir=path', '--help'];
        $expected = ['script.php', '--bundle_id=test', 'positional', '--dir=path', '--help'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithEmptyValue()
    {
        $input = ['--bundle-id=', '--dir='];
        $expected = ['--bundle_id=', '--dir='];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    public function testNormalizeArgsWithSpecialCharactersInValue()
    {
        $input = ['--description=This is a test!', '--path=/usr/local/bin'];
        $expected = ['--description=This is a test!', '--path=/usr/local/bin'];
        $result = Dj_App_Cli_Util::normalizeArgs($input);
        $this->assertEquals($expected, $result);
    }

    // parseArgs — every argument form a CLI tool actually receives. It previously
    // read ONLY --key=value and had no coverage at all, so these pin the behaviour
    // that callers were otherwise re-implementing for themselves.

    public function testParseArgsLongOptWithEquals()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--key=value']);
        $this->assertSame('value', $result['key']);
    }

    public function testParseArgsLongOptWithNextArgValue()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--key', 'value']);
        $this->assertSame('value', $result['key']);
    }

    public function testParseArgsLongOptFlagOnly()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--run']);
        $this->assertTrue($result['run']);
    }

    public function testParseArgsOptFollowedByOptIsFlag()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--first', '--second=v']);
        $expected = ['first' => true, 'second' => 'v'];
        $this->assertEquals($expected, $result);
    }

    public function testParseArgsShortOptWithNextArgValue()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['-k', 'value']);
        $this->assertSame('value', $result['k']);
    }

    public function testParseArgsShortOptWithEquals()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['-k=value']);
        $this->assertSame('value', $result['k']);
    }

    public function testParseArgsShortOptCluster()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['-abc']);
        $expected = ['a' => true, 'b' => true, 'c' => true];
        $this->assertEquals($expected, $result);
    }

    public function testParseArgsRepeatedLongOptCollectsArray()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--tag=a', '--tag=b']);
        $this->assertSame(['a', 'b'], $result['tag']);
    }

    public function testParseArgsPositionalArgsKeepNumericKeys()
    {
        // 'extra' must come BEFORE the flag: a value-less option consumes the next
        // non-option arg, so `--run extra` means run='extra', not a positional.
        $result = Dj_App_Cli_Util::parseArgs([], ['build', 'extra', '--run']);
        $this->assertSame('build', $result[0]);
        $this->assertSame('extra', $result[1]);
        $this->assertTrue($result['run']);
    }

    /**
     * NOTE an empty $args means "use the global argv", not "parse nothing" — so the
     * no-arguments case cannot be expressed and is not asserted here. Under phpunit
     * the fallback would parse phpunit's own command line.
     */
    public function testParseArgsUnrecognisedArgYieldsNoKeys()
    {
        $result = Dj_App_Cli_Util::parseArgs(['env' => 'live'], ['--nope=1']);

        $this->assertSame(['env' => 'live'], $result);
    }

    /**
     * The three outcomes empty() would have conflated. A '0' is a real value in
     * every one of these positions and must never read as absent or as a flag.
     */
    public function testParseArgsSeparatesEmptyZeroFlag()
    {
        $empty_result = Dj_App_Cli_Util::parseArgs([], ['--key=']);
        $this->assertSame('', $empty_result['key'], '--key= is an empty string value');

        $zero_result = Dj_App_Cli_Util::parseArgs([], ['--key=0']);
        $this->assertSame('0', $zero_result['key'], '--key=0 is the string 0');

        $flag_result = Dj_App_Cli_Util::parseArgs([], ['--key']);
        $this->assertTrue($flag_result['key'], 'bare --key is boolean true');
    }

    public function testParseArgsZeroSurvivesEverySpacedForm()
    {
        $long_result = Dj_App_Cli_Util::parseArgs([], ['--key', '0']);
        $this->assertSame('0', $long_result['key']);

        $short_result = Dj_App_Cli_Util::parseArgs([], ['-k', '0']);
        $this->assertSame('0', $short_result['k']);

        $positional_result = Dj_App_Cli_Util::parseArgs([], ['0']);
        $this->assertSame('0', $positional_result[0]);
    }

    /**
     * Values are BYTES, not ASCII — the parser must not corrupt multibyte input.
     */
    public function testParseArgsKeepsMultibyteValues()
    {
        $equals_result = Dj_App_Cli_Util::parseArgs([], ['--brand=БМВ']);
        $this->assertSame('БМВ', $equals_result['brand']);

        $spaced_result = Dj_App_Cli_Util::parseArgs([], ['--brand', 'БМВ']);
        $this->assertSame('БМВ', $spaced_result['brand']);
    }

    /**
     * The quote strip is defensive against a re-quoted WHOLE argument — a shell
     * normally removes quotes before argv ever sees them.
     *
     * ⚠️ It is deliberately NOT applied to the value after '=': `--key="v"` yields
     * `"v`, because the leading quote sits mid-string. The spaced form does strip
     * them. That asymmetry is inherited behaviour, documented here rather than
     * silently changed.
     */
    public function testParseArgsStripsQuotesAroundWholeArg()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['"--key=value"']);
        $this->assertSame('value', $result['key']);

        $spaced_result = Dj_App_Cli_Util::parseArgs([], ['--key', '"value"']);
        $this->assertSame('value', $spaced_result['key'], 'spaced form DOES strip quotes');
    }

    /**
     * djebel-specific: normalizeArgs turns --repo-dir into repo_dir, so a caller
     * reads one spelling regardless of which the operator typed.
     */
    public function testParseArgsNormalizesHyphenatedKeys()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--repo-dir', 'dist/apt']);
        $this->assertSame('dist/apt', $result['repo_dir']);
    }

    public function testParseArgsAppliesDefaultsForMissingParams()
    {
        $expected_params = ['env' => 'live', 'ver' => ''];
        $result = Dj_App_Cli_Util::parseArgs($expected_params, ['--ver=1.2.3']);

        $this->assertSame('1.2.3', $result['ver'], 'supplied value wins');
        $this->assertSame('live', $result['env'], 'untouched param keeps its default');
    }

    /**
     * A DEFAULT must be REPLACED by the first real value, never appended to — the
     * repeated-key collection applies only to keys the caller did not declare.
     */
    public function testParseArgsDefaultIsReplacedNotCollected()
    {
        $expected_params = ['env' => 'live'];
        $result = Dj_App_Cli_Util::parseArgs($expected_params, ['--env=staging']);

        $this->assertSame('staging', $result['env']);
    }

    public function testParseArgsDropsUnknownKeysWhenExpectedParamsGiven()
    {
        $expected_params = ['env' => 'live'];
        $result = Dj_App_Cli_Util::parseArgs($expected_params, ['--env=dev', '--bogus=x']);

        $this->assertSame('dev', $result['env']);
        $this->assertArrayNotHasKey('bogus', $result);
    }

    public function testParseArgsSkipsHelpFlags()
    {
        $result = Dj_App_Cli_Util::parseArgs([], ['--help']);
        $this->assertArrayNotHasKey('help', $result);
    }

    public function testStderrAliasParamsAndEarnedReturn()
    {
        // An empty message with no newline writes ZERO bytes — a legitimate write,
        // so the earned return is still true; each call exercises one param alias.
        $this->assertTrue(Dj_App_Cli_Util::stderr('', [ 'newline' => false, ]));
        $this->assertTrue(Dj_App_Cli_Util::stderr('', [ 'new_line' => false, ]));
        $this->assertTrue(Dj_App_Cli_Util::stderr('', [ 'nl' => false, ]));
    }
}
