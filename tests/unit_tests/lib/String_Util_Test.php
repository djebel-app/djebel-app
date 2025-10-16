<?php

use PHPUnit\Framework\TestCase;

class String_Util_Test extends TestCase {

    public function testGetFirstCharWithNormalString()
    {
        $result = Dj_App_String_Util::getFirstChar('hello');
        $this->assertEquals('h', $result);
    }

    public function testGetFirstCharWithSingleChar()
    {
        $result = Dj_App_String_Util::getFirstChar('x');
        $this->assertEquals('x', $result);
    }

    public function testGetFirstCharWithEmptyString()
    {
        $result = Dj_App_String_Util::getFirstChar('');
        $this->assertEmpty($result);
    }

    public function testGetFirstCharWithNull()
    {
        $result = Dj_App_String_Util::getFirstChar(null);
        $this->assertEmpty($result);
    }

    public function testGetFirstCharWithNumber()
    {
        $result = Dj_App_String_Util::getFirstChar('123abc');
        $this->assertEquals('1', $result);
    }

    public function testGetFirstCharWithSpecialChar()
    {
        $result = Dj_App_String_Util::getFirstChar('!@#test');
        $this->assertEquals('!', $result);
    }

    public function testGetFirstCharWithWhitespace()
    {
        $result = Dj_App_String_Util::getFirstChar(' hello');
        $this->assertEquals(' ', $result);
    }

    public function testGetLastCharWithNormalString()
    {
        $result = Dj_App_String_Util::getLastChar('hello');
        $this->assertEquals('o', $result);
    }

    public function testGetLastCharWithSingleChar()
    {
        $result = Dj_App_String_Util::getLastChar('x');
        $this->assertEquals('x', $result);
    }

    public function testGetLastCharWithEmptyString()
    {
        $result = Dj_App_String_Util::getLastChar('');
        $this->assertEmpty($result);
    }

    public function testGetLastCharWithNull()
    {
        $result = Dj_App_String_Util::getLastChar(null);
        $this->assertEmpty($result);
    }

    public function testGetLastCharWithNumber()
    {
        $result = Dj_App_String_Util::getLastChar('abc123');
        $this->assertEquals('3', $result);
    }

    public function testGetLastCharWithSpecialChar()
    {
        $result = Dj_App_String_Util::getLastChar('test!@#');
        $this->assertEquals('#', $result);
    }

    public function testGetLastCharWithWhitespace()
    {
        $result = Dj_App_String_Util::getLastChar('hello ');
        $this->assertEquals(' ', $result);
    }

    public function testTrimWithString()
    {
        $result = Dj_App_String_Util::trim('  hello  ');
        $this->assertEquals('hello', $result);
    }

    public function testTrimWithExtraChars()
    {
        $result = Dj_App_String_Util::trim('---hello---', '-');
        $this->assertEquals('hello', $result);
    }

    public function testTrimWithArray()
    {
        $result = Dj_App_String_Util::trim(['  hello  ', '  world  ']);
        $this->assertEquals(['hello', 'world'], $result);
    }

    public function testTrimWithArrayAndExtraChars()
    {
        $result = Dj_App_String_Util::trim(['--test--', '++data++'], ['-', '+']);
        $this->assertEquals(['test', 'data'], $result);
    }

    public function testToCamelCase()
    {
        $result = Dj_App_String_Util::toCamelCase('hello_world');
        $this->assertEquals('helloWorld', $result);
    }

    public function testToCamelCaseWithSpaces()
    {
        $result = Dj_App_String_Util::toCamelCase('hello world test');
        $this->assertEquals('helloWorldTest', $result);
    }

    public function testToCamelCaseWithEmpty()
    {
        $result = Dj_App_String_Util::toCamelCase('');
        $this->assertEmpty($result);
    }

    public function testFormatKey()
    {
        $result = Dj_App_String_Util::formatKey('Hello World');
        $this->assertEquals('hello_world', $result);
    }

    public function testFormatKeyWithSpecialChars()
    {
        $result = Dj_App_String_Util::formatKey('test@value');
        $this->assertEquals('test_value', $result);
    }

    public function testFormatKeyWithDashes()
    {
        $result = Dj_App_String_Util::formatKey('hello-world-test');
        $this->assertEquals('hello_world_test', $result);
    }

    public function testFormatKeyWithEmpty()
    {
        $result = Dj_App_String_Util::formatKey('');
        $this->assertEmpty($result);
    }

    public function testIsAlphaNumericExtWithValid()
    {
        $result = Dj_App_String_Util::isAlphaNumericExt('hello123');
        $this->assertTrue($result);
    }

    public function testIsAlphaNumericExtWithUnderscore()
    {
        $result = Dj_App_String_Util::isAlphaNumericExt('hello_world');
        $this->assertTrue($result);
    }

    public function testIsAlphaNumericExtWithDash()
    {
        $result = Dj_App_String_Util::isAlphaNumericExt('hello-world');
        $this->assertTrue($result);
    }

    public function testIsAlphaNumericExtWithInvalidChars()
    {
        $result = Dj_App_String_Util::isAlphaNumericExt('hello@world');
        $this->assertFalse($result);
    }

    public function testIsAlphaNumericExtWithEmpty()
    {
        $result = Dj_App_String_Util::isAlphaNumericExt('');
        $this->assertFalse($result);
    }

    public function testFormatSlug()
    {
        $result = Dj_App_String_Util::formatSlug('Hello World');
        $this->assertEquals('hello-world', $result);
    }

    public function testFormatSlugWithUnderscores()
    {
        $result = Dj_App_String_Util::formatSlug('hello_world_test');
        $this->assertEquals('hello-world-test', $result);
    }

    public function testFormatSlugWithSpecialChars()
    {
        $result = Dj_App_String_Util::formatSlug('test@#$value');
        $this->assertEquals('test-value', $result);
    }

    public function testFormatStringIdBasic()
    {
        $result = Dj_App_String_Util::formatStringId('Hello World');
        $this->assertEquals('hello_world', $result);
    }

    public function testFormatStringIdWithEmpty()
    {
        $result = Dj_App_String_Util::formatStringId('');
        $this->assertEmpty($result);
    }

    public function testFormatStringIdWithNull()
    {
        $result = Dj_App_String_Util::formatStringId(null);
        $this->assertEmpty($result);
    }

    public function testFormatStringIdWithUppercaseFlag()
    {
        $result = Dj_App_String_Util::formatStringId('hello', Dj_App_String_Util::UPPERCASE);
        $this->assertEquals('HELLO', $result);
    }

    public function testFormatStringIdWithKeepCaseFlag()
    {
        $result = Dj_App_String_Util::formatStringId('HelloWorld', Dj_App_String_Util::KEEP_CASE);
        $this->assertEquals('HelloWorld', $result);
    }

    public function testJsonEncodeArray()
    {
        $data = ['name' => 'test', 'value' => 123];
        $result = Dj_App_String_Util::jsonEncode($data);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testJsonEncodeObject()
    {
        $obj = new stdClass();
        $obj->name = 'test';
        $obj->value = 456;

        $result = Dj_App_String_Util::jsonEncode($obj);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('name', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testJsonDecodeValidArray()
    {
        $json = '["item1","item2","item3"]';
        $result = Dj_App_String_Util::jsonDecode($json);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('item1', $result[0]);
    }

    public function testJsonDecodeValidObject()
    {
        $json = '{"name":"test","value":123}';
        $result = Dj_App_String_Util::jsonDecode($json);

        $this->assertIsArray($result);
        $this->assertEquals('test', $result['name']);
        $this->assertEquals(123, $result['value']);
    }

    public function testJsonDecodeNonJson()
    {
        $result = Dj_App_String_Util::jsonDecode('not json');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testJsonDecodeEmpty()
    {
        $result = Dj_App_String_Util::jsonDecode('');
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testCutWithDefaults()
    {
        $string = str_repeat('abcdefghij', 100);
        $result = Dj_App_String_Util::cut($string);

        $this->assertEquals(512, strlen($result));
        $this->assertEquals(substr($string, 0, 512), $result);
    }

    public function testCutWithCustomLength()
    {
        $string = 'hello world test';
        $result = Dj_App_String_Util::cut($string, 5);

        $this->assertEquals('hello', $result);
    }

    public function testCutWithStartIndex()
    {
        $string = 'hello world test';
        $result = Dj_App_String_Util::cut($string, 5, 6);

        $this->assertEquals('world', $result);
    }

    public function testCutWithStartIndexAndLength()
    {
        $string = '0123456789ABCDEFGHIJ';
        $result = Dj_App_String_Util::cut($string, 10, 5);

        $this->assertEquals('56789ABCDE', $result);
    }

    public function testCutWithEmptyString()
    {
        $result = Dj_App_String_Util::cut('');
        $this->assertEmpty($result);
    }

    public function testCutWithNull()
    {
        $result = Dj_App_String_Util::cut(null);
        $this->assertEmpty($result);
    }

    public function testCutExceedsLength()
    {
        $string = 'short';
        $result = Dj_App_String_Util::cut($string, 100);

        $this->assertEquals('short', $result);
    }

    public function testCutFromBeginning()
    {
        $string = 'abcdefghijklmnop';
        $result = Dj_App_String_Util::cut($string, 5, 0);

        $this->assertEquals('abcde', $result);
    }

    public function testCutFromMiddle()
    {
        $string = 'The quick brown fox';
        $result = Dj_App_String_Util::cut($string, 5, 4);

        $this->assertEquals('quick', $result);
    }

    public function testCutSingleCharacter()
    {
        $string = 'hello';
        $result = Dj_App_String_Util::cut($string, 1, 0);

        $this->assertEquals('h', $result);
    }

    public function testCutLargeString()
    {
        $large_string = str_repeat('test', 1000);
        $result = Dj_App_String_Util::cut($large_string, 512);

        $this->assertEquals(512, strlen($result));
    }

    public function testCutWithZeroLength()
    {
        $string = 'hello world';
        $result = Dj_App_String_Util::cut($string, 0);

        $this->assertEmpty($result);
    }

    public function testCutWithNegativeLength()
    {
        $string = 'hello world';
        $result = Dj_App_String_Util::cut($string, -5);

        $this->assertEquals('hello ', $result);
    }

    public function testCutEntireString()
    {
        $string = 'complete';
        $result = Dj_App_String_Util::cut($string, 1000);

        $this->assertEquals('complete', $result);
    }

    public function testCutWithWhitespace()
    {
        $string = '   hello   ';
        $result = Dj_App_String_Util::cut($string, 5, 3);

        $this->assertEquals('hello', $result);
    }

    public function testCutWithSpecialChars()
    {
        $string = '!@#$%^&*()';
        $result = Dj_App_String_Util::cut($string, 3, 2);

        $this->assertEquals('#$%', $result);
    }

    public function testNormalizeNewLinesWithWindowsLineEndings()
    {
        $text = "Line 1\r\nLine 2\r\nLine 3";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Line 1\nLine 2\nLine 3", $result);
        $this->assertStringNotContainsString("\r", $result);
    }

    public function testNormalizeNewLinesWithOldMacLineEndings()
    {
        $text = "Line 1\rLine 2\rLine 3";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Line 1\nLine 2\nLine 3", $result);
        $this->assertStringNotContainsString("\r", $result);
    }

    public function testNormalizeNewLinesWithUnixLineEndings()
    {
        $text = "Line 1\nLine 2\nLine 3";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Line 1\nLine 2\nLine 3", $result);
    }

    public function testNormalizeNewLinesWithMixedLineEndings()
    {
        $text = "Line 1\r\nLine 2\rLine 3\nLine 4";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Line 1\nLine 2\nLine 3\nLine 4", $result);
        $this->assertStringNotContainsString("\r", $result);
    }

    public function testNormalizeNewLinesWithNullBytes()
    {
        $text = "Text\0with\0nulls\r\nand\r\nlines";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Textwithnulls\nand\nlines", $result);
        $this->assertStringNotContainsString("\0", $result);
        $this->assertStringNotContainsString("\r", $result);
    }

    public function testNormalizeNewLinesWithEmptyString()
    {
        $result = Dj_App_String_Util::normalizeNewLines('');
        $this->assertEmpty($result);
    }

    public function testNormalizeNewLinesWithNull()
    {
        $result = Dj_App_String_Util::normalizeNewLines(null);
        $this->assertEmpty($result);
    }

    public function testNormalizeNewLinesWithNoNewLines()
    {
        $text = "Just a simple text with no line endings";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals($text, $result);
    }

    public function testNormalizeNewLinesWithMultipleConsecutiveNewLines()
    {
        $text = "Line 1\r\n\r\nLine 2\n\n\nLine 3";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Line 1\n\nLine 2\n\n\nLine 3", $result);
    }

    public function testNormalizeNewLinesWithOnlyNewLines()
    {
        $text = "\r\n\r\n\r\n";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("\n\n\n", $result);
    }

    public function testNormalizeNewLinesWithTrailingAndLeadingNewLines()
    {
        $text = "\r\nStart\r\nMiddle\r\nEnd\r\n";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("\nStart\nMiddle\nEnd\n", $result);
    }

    public function testNormalizeNewLinesWithSpecialCharacters()
    {
        $text = "Special!@#$%\r\nChars&*()\rEverywhere\nTest";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $this->assertEquals("Special!@#$%\nChars&*()\nEverywhere\nTest", $result);
    }

    public function testNormalizeNewLinesRealWorldExample()
    {
        $text = "---\r\ntitle: Test\r\nauthor: John\r\n---\r\n# Heading\r\n\r\nContent here.";
        $result = Dj_App_String_Util::normalizeNewLines($text);

        $expected = "---\ntitle: Test\nauthor: John\n---\n# Heading\n\nContent here.";
        $this->assertEquals($expected, $result);
    }

    public function testSinglefyWithSingleCharString()
    {
        $result = Dj_App_String_Util::singlefy('app///core', '/');
        $this->assertEquals('app/core', $result);
    }

    public function testSinglefyWithSingleCharUnderscore()
    {
        $result = Dj_App_String_Util::singlefy('my___hook', '_');
        $this->assertEquals('my_hook', $result);
    }

    public function testSinglefyWithArrayOfChars()
    {
        $result = Dj_App_String_Util::singlefy('app///core___hook', ['/', '_']);
        $this->assertEquals('app/core_hook', $result);
    }

    public function testSinglefyWithNoDuplicates()
    {
        $result = Dj_App_String_Util::singlefy('test', '/');
        $this->assertEquals('test', $result);
    }

    public function testSinglefyWithEmptyString()
    {
        $result = Dj_App_String_Util::singlefy('', '/');
        $this->assertEquals('', $result);
    }

    public function testSinglefyWithEmptyChars()
    {
        $result = Dj_App_String_Util::singlefy('test//string', '');
        $this->assertEquals('test//string', $result);
    }

    public function testSinglefyWithMultipleConsecutiveDuplicates()
    {
        $result = Dj_App_String_Util::singlefy('test////value', '/');
        $this->assertEquals('test/value', $result);
    }

    public function testSinglefyWithMixedDuplicates()
    {
        $result = Dj_App_String_Util::singlefy('a//b___c--d', ['/', '_', '-']);
        $this->assertEquals('a/b_c-d', $result);
    }

    public function testSinglefyWithOnlyDuplicates()
    {
        $result = Dj_App_String_Util::singlefy('//////', '/');
        $this->assertEquals('/', $result);
    }

    public function testSinglefyWithDuplicatesAtStart()
    {
        $result = Dj_App_String_Util::singlefy('___test', '_');
        $this->assertEquals('_test', $result);
    }

    public function testSinglefyWithDuplicatesAtEnd()
    {
        $result = Dj_App_String_Util::singlefy('test___', '_');
        $this->assertEquals('test_', $result);
    }

    public function testSinglefyWithDash()
    {
        $result = Dj_App_String_Util::singlefy('test---value', '-');
        $this->assertEquals('test-value', $result);
    }

    public function testSinglefyWithDot()
    {
        $result = Dj_App_String_Util::singlefy('test...value', '.');
        $this->assertEquals('test.value', $result);
    }

    public function testSinglefyRealWorldHookExample()
    {
        $hook_name = 'app///plugin___test//hook';
        $result = Dj_App_String_Util::singlefy($hook_name, ['/', '_']);
        $this->assertEquals('app/plugin_test/hook', $result);
    }

    public function testSinglefyPreservesNonDuplicates()
    {
        $result = Dj_App_String_Util::singlefy('a/b_c/d_e', ['/', '_']);
        $this->assertEquals('a/b_c/d_e', $result);
    }
}
