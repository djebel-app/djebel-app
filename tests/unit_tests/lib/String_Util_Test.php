<?php

use PHPUnit\Framework\TestCase;

class String_Util_Test extends TestCase {

    public function testContainsCaseInsensitive()
    {
        $this->assertTrue(Dj_App_String_Util::contains('Hello World', 'hello'));
        $this->assertTrue(Dj_App_String_Util::contains('Hello World', 'WORLD'));
        $this->assertTrue(Dj_App_String_Util::contains('Hello World', 'lo Wo'));
        $this->assertTrue(Dj_App_String_Util::contains('Hello World', 'Hello'));
        $this->assertFalse(Dj_App_String_Util::contains('Hello World', 'xyz'));
    }

    public function testContainsEmptyValues()
    {
        $this->assertFalse(Dj_App_String_Util::contains('', 'test'));
        $this->assertFalse(Dj_App_String_Util::contains('test', ''));
        $this->assertFalse(Dj_App_String_Util::contains('', ''));
        $this->assertFalse(Dj_App_String_Util::contains(null, 'test'));
    }

    public function testContainsRealWorldPatterns()
    {
        // HTML attribute checking (like stripos in html.php)
        $attr = "class='btn' id='submit_btn' checked";
        $this->assertTrue(Dj_App_String_Util::contains($attr, 'id='));
        $this->assertTrue(Dj_App_String_Util::contains($attr, 'checked'));
        $this->assertFalse(Dj_App_String_Util::contains($attr, 'disabled'));

        // Hook name checking
        $this->assertTrue(Dj_App_String_Util::contains('app/plugins/test', 's/'));
        $this->assertFalse(Dj_App_String_Util::contains('app/plugin/test', 's/'));
    }

    public function testReplaceMergeTags()
    {
        $template = '%title% | %site_title%';
        $replace_map = [
            '%title%' => 'Hosting',
            '%site_title%' => 'fsite.net',
        ];

        $result = Dj_App_String_Util::replaceMergeTags($template, $replace_map);

        $this->assertEquals('Hosting | fsite.net', $result);
    }

    public function testReplaceMergeTagsWithNoTags()
    {
        $template = 'Hosting title';
        $replace_map = [
            '%title%' => 'Ignored',
        ];

        $result = Dj_App_String_Util::replaceMergeTags($template, $replace_map);

        $this->assertEquals('Hosting title', $result);
    }

    public function testReplaceMergeTagsWithEmptyTemplate()
    {
        $template = '';
        $replace_map = [
            '%title%' => 'Hosting',
        ];

        $result = Dj_App_String_Util::replaceMergeTags($template, $replace_map);

        $this->assertEquals('', $result);
    }

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

    public function testSinglefyWithScalarMultiCharString()
    {
        // Multi-char string gets str_split into individual chars
        $result = Dj_App_String_Util::singlefy('app///core___hook', '/_');
        $this->assertEquals('app/core_hook', $result);
    }

    public function testSinglefyScalarVsArraySameResult()
    {
        $input = 'test///value___name---end';

        // String arg
        $result_str = Dj_App_String_Util::singlefy($input, '/_-');

        // Array arg
        $result_arr = Dj_App_String_Util::singlefy($input, [ '/', '_', '-', ]);

        $this->assertEquals($result_arr, $result_str);
        $this->assertEquals('test/value_name-end', $result_str);
    }

    public function testSinglefyScalarTwoChars()
    {
        // Underscore + dash as string
        $result = Dj_App_String_Util::singlefy('my___cool---plugin', '_-');
        $this->assertEquals('my_cool-plugin', $result);
    }

    public function testSinglefyScalarPageSlugPattern()
    {
        // Real-world: page slug cleanup with underscore + dash
        $result = Dj_App_String_Util::singlefy('my___page---slug', '_-');
        $this->assertEquals('my_page-slug', $result);
    }

    public function testSinglefyScalarHookPattern()
    {
        // Real-world: hook name cleanup with slash + underscore
        $result = Dj_App_String_Util::singlefy('app///core___hook//test', '/_');
        $this->assertEquals('app/core_hook/test', $result);
    }

    // --- formatPageSlug Tests ---

    public function testFormatPageSlugBasic()
    {
        $result = Dj_App_String_Util::formatPageSlug('about');
        $this->assertEquals('about', $result);
    }

    public function testFormatPageSlugWithDashes()
    {
        $result = Dj_App_String_Util::formatPageSlug('my-page');
        $this->assertEquals('my-page', $result);
    }

    public function testFormatPageSlugWithUnderscores()
    {
        $result = Dj_App_String_Util::formatPageSlug('my_page');
        $this->assertEquals('my_page', $result);
    }

    public function testFormatPageSlugEmpty()
    {
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug(''));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('/'));
    }

    public function testFormatPageSlugSpecialChars()
    {
        $result = Dj_App_String_Util::formatPageSlug('my page!@#test');
        $this->assertEquals('my_page_test', $result);
    }

    public function testFormatPageSlugConsecutiveSeparators()
    {
        $result = Dj_App_String_Util::formatPageSlug('my___page---test');
        $this->assertEquals('my_page-test', $result);
    }

    public function testFormatPageSlugLeadingTrailingSeparators()
    {
        $result = Dj_App_String_Util::formatPageSlug('__my_page__');
        $this->assertEquals('my_page', $result);
    }

    public function testFormatPageSlugAlphaNumericFastPath()
    {
        // Already clean - isAlphaNumericExt fast-path
        $result = Dj_App_String_Util::formatPageSlug('contact-us');
        $this->assertEquals('contact-us', $result);
    }

    public function testFormatPageSlugMaxLength()
    {
        $long_page = str_repeat('a', 200);
        $result = Dj_App_String_Util::formatPageSlug($long_page);
        $this->assertEquals(100, strlen($result));
    }

    public function testFormatPageSlugWithNumbers()
    {
        $result = Dj_App_String_Util::formatPageSlug('page123');
        $this->assertEquals('page123', $result);
    }

    public function testFormatPageSlugWithDots()
    {
        $result = Dj_App_String_Util::formatPageSlug('my.page.test');
        $this->assertEquals('my_page_test', $result);
    }

    public function testFormatPageSlugWithSpaces()
    {
        $result = Dj_App_String_Util::formatPageSlug('my page test');
        $this->assertEquals('my_page_test', $result);
    }

    public function testFormatPageSlugMixedCase()
    {
        // preserves case
        $result = Dj_App_String_Util::formatPageSlug('MyPage');
        $this->assertEquals('MyPage', $result);
    }

    public function testFormatPageSlugWeirdInputs()
    {
        // slashes get converted to _ then trimmed/singified
        $this->assertEquals('page1_subpage1', Dj_App_String_Util::formatPageSlug('/page1/subpage1/'));
        $this->assertEquals('page1', Dj_App_String_Util::formatPageSlug('/page1/'));
        $this->assertEquals('page1_subpage1_deep', Dj_App_String_Util::formatPageSlug('/page1/subpage1/deep'));
        $this->assertEquals('page1', Dj_App_String_Util::formatPageSlug('///page1///'));

        // multiple slashes only
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('///'));

        // dots and mixed separators
        $this->assertEquals('page_v2_test', Dj_App_String_Util::formatPageSlug('page.v2.test'));
        $this->assertEquals('my_page_here', Dj_App_String_Util::formatPageSlug('my...page...here'));

        // spaces and tabs
        $this->assertEquals('hello_world', Dj_App_String_Util::formatPageSlug('  hello   world  '));
        $this->assertEquals('tab_separated', Dj_App_String_Util::formatPageSlug("\ttab\tseparated\t"));

        // special chars galore
        $this->assertEquals('page', Dj_App_String_Util::formatPageSlug('!!!page!!!'));
        $this->assertEquals('a_b_c', Dj_App_String_Util::formatPageSlug('@a#b$c%'));
        $this->assertEquals('test_page_1', Dj_App_String_Util::formatPageSlug('test&page=1'));

        // unicode / non-ascii
        $this->assertEquals('caf', Dj_App_String_Util::formatPageSlug('café'));
        $this->assertEquals('ber-uns', Dj_App_String_Util::formatPageSlug('über-uns'));

        // only special chars → empty after trim
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('!!!@@@###'));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('...'));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('   '));

        // dashes and underscores mixed
        $this->assertEquals('my-page_test', Dj_App_String_Util::formatPageSlug('--my-page__test--'));
        $this->assertEquals('a-b_c', Dj_App_String_Util::formatPageSlug('---a---b___c---'));

        // query string style
        $this->assertEquals('page_id_5_sort_name', Dj_App_String_Util::formatPageSlug('page?id=5&sort=name'));

        // null byte and control chars
        $this->assertEquals('page_test', Dj_App_String_Util::formatPageSlug("page\0test"));

        // single char
        $this->assertEquals('a', Dj_App_String_Util::formatPageSlug('a'));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('.'));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('_'));
        $this->assertEmpty(Dj_App_String_Util::formatPageSlug('-'));
    }

    public function testFormatPageSlugDeepDirPaths()
    {
        // deep directory-style paths → flattened to one string
        $this->assertEquals('en_blog_my-post_comments', Dj_App_String_Util::formatPageSlug('/en/blog/my-post/comments'));
        $this->assertEquals('a_b_c_d_e_f', Dj_App_String_Util::formatPageSlug('/a/b/c/d/e/f'));
        $this->assertEquals('users_123_profile_settings', Dj_App_String_Util::formatPageSlug('/users/123/profile/settings'));
        $this->assertEquals('app_v2_api_endpoint', Dj_App_String_Util::formatPageSlug('/app/v2/api/endpoint/'));
        $this->assertEquals('2024_03_my-article', Dj_App_String_Util::formatPageSlug('/2024/03/my-article'));
        $this->assertEquals('en_products_shoes_nike-air', Dj_App_String_Util::formatPageSlug('en/products/shoes/nike-air/'));
        $this->assertEquals('blog_category_sub_cat_post-title', Dj_App_String_Util::formatPageSlug('blog/category/sub_cat/post-title'));

        // deep with trailing/leading slashes and doubles
        $this->assertEquals('a_b_c', Dj_App_String_Util::formatPageSlug('///a///b///c///'));
        $this->assertEquals('one_two_three', Dj_App_String_Util::formatPageSlug('//one//two//three//'));
    }

    public function testFormatPageSlugDotsInPaths()
    {
        // dots as separators (like file extensions or domain-style)
        $this->assertEquals('index_html', Dj_App_String_Util::formatPageSlug('index.html'));
        $this->assertEquals('page_php', Dj_App_String_Util::formatPageSlug('page.php'));
        $this->assertEquals('archive_tar_gz', Dj_App_String_Util::formatPageSlug('archive.tar.gz'));
        $this->assertEquals('my_site_com_about', Dj_App_String_Util::formatPageSlug('my.site.com/about'));
        $this->assertEquals('v1_2_3', Dj_App_String_Util::formatPageSlug('v1.2.3'));
        $this->assertEquals('config_backup_2024_01_15', Dj_App_String_Util::formatPageSlug('config.backup.2024.01.15'));

        // dots + slashes mixed
        $this->assertEquals('en_blog_post_v2_draft', Dj_App_String_Util::formatPageSlug('/en/blog/post.v2.draft'));
        $this->assertEquals('api_v1_users_list_json', Dj_App_String_Util::formatPageSlug('/api/v1/users/list.json'));
        $this->assertEquals('assets_css_main_min_css', Dj_App_String_Util::formatPageSlug('/assets/css/main.min.css'));

        // dots + dashes + slashes all together
        $this->assertEquals('my-app_v2_api_user-profile_settings', Dj_App_String_Util::formatPageSlug('/my-app/v2.api/user-profile/settings'));
        $this->assertEquals('blog_2024_my-post_final_v3', Dj_App_String_Util::formatPageSlug('blog/2024/my-post.final.v3'));

        // consecutive dots in paths
        $this->assertEquals('path_to_file', Dj_App_String_Util::formatPageSlug('/path/../to/./file'));
        $this->assertEquals('a_b_c', Dj_App_String_Util::formatPageSlug('a...b...c'));
    }

    public function testFormatPageSlugSpecialCharsAggressive()
    {
        // HTML tags
        $this->assertEquals('b_hello_b', Dj_App_String_Util::formatPageSlug('<b>hello</b>'));
        $this->assertEquals('script_alert_1_script', Dj_App_String_Util::formatPageSlug('<script>alert(1)</script>'));
        $this->assertEquals('a_href_x_click_a', Dj_App_String_Util::formatPageSlug('<a href="x">click</a>'));
        $this->assertEquals('img_src_x_onerror_alert_1', Dj_App_String_Util::formatPageSlug('<img src=x onerror=alert(1)>'));

        // URL-encoded strings
        $this->assertEquals('hello_20world', Dj_App_String_Util::formatPageSlug('hello%20world'));
        $this->assertEquals('path_2Fto_2Fpage', Dj_App_String_Util::formatPageSlug('path%2Fto%2Fpage'));
        $this->assertEquals('3Cscript_3E', Dj_App_String_Util::formatPageSlug('%3Cscript%3E'));

        // SQL injection style
        $this->assertEquals('1_OR_1_1', Dj_App_String_Util::formatPageSlug("1' OR '1'='1"));
        $this->assertEquals('DROP_TABLE_users', Dj_App_String_Util::formatPageSlug("'; DROP TABLE users; --"));
        $this->assertEquals('UNION_SELECT_FROM_users', Dj_App_String_Util::formatPageSlug("UNION SELECT * FROM users"));

        // backticks, quotes, brackets
        $this->assertEquals('page', Dj_App_String_Util::formatPageSlug('`page`'));
        $this->assertEquals('page', Dj_App_String_Util::formatPageSlug('"page"'));
        $this->assertEquals('page', Dj_App_String_Util::formatPageSlug("'page'"));
        $this->assertEquals('arr_0', Dj_App_String_Util::formatPageSlug('arr[0]'));
        $this->assertEquals('obj_key', Dj_App_String_Util::formatPageSlug('{obj}{key}'));

        // newlines, carriage returns in paths
        $this->assertEquals('line1_line2', Dj_App_String_Util::formatPageSlug("line1\nline2"));
        $this->assertEquals('line1_line2', Dj_App_String_Util::formatPageSlug("line1\r\nline2"));
        $this->assertEquals('a_b_c', Dj_App_String_Util::formatPageSlug("a\n\n\nb\r\r\rc"));

        // emoji and multibyte
        $this->assertNotEmpty(Dj_App_String_Util::formatPageSlug('page-👍-test'));
        $this->assertEquals('page-_-test', Dj_App_String_Util::formatPageSlug('page-★-test'));
        $this->assertEquals('page-_-test', Dj_App_String_Util::formatPageSlug('page-→-test'));

        // path traversal attempts — leading dots/underscores get trimmed
        $this->assertEquals('etc_passwd', Dj_App_String_Util::formatPageSlug('../../../../etc/passwd'));
        $this->assertEquals('windows_system32', Dj_App_String_Util::formatPageSlug('..\\..\\windows\\system32'));
        $this->assertEquals('etc_shadow', Dj_App_String_Util::formatPageSlug('/./../../etc/shadow'));

        // protocol/scheme attempts
        $this->assertEquals('javascript_alert_1', Dj_App_String_Util::formatPageSlug('javascript:alert(1)'));
        $this->assertEquals('data_text_html_b_xss_b', Dj_App_String_Util::formatPageSlug('data:text/html,<b>xss</b>'));
        $this->assertEquals('file_etc_passwd', Dj_App_String_Util::formatPageSlug('file:///etc/passwd'));

        // mixed chaos
        $this->assertEquals('p_ge_wi_h_ll-s_rts', Dj_App_String_Util::formatPageSlug('<p@ge/wi%h/àll-sörts!>'));
        $this->assertEquals('x_y', Dj_App_String_Util::formatPageSlug('  ///x...///...y///  '));
    }

    // --- Auth Code Tests ---

    public function testGenerateAuthSalt()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();
        $this->assertIsString($salt);
        $this->assertStringStartsWith('dj_auth_', $salt);
        $this->assertNotEmpty($salt);
    }

    public function testGenerateAuthSaltWithCustomPrefix()
    {
        $params = [ 'prefix' => 'my_plugin_', ];
        $salt = Dj_App_String_Util::generateAuthSalt($params);
        $this->assertStringStartsWith('my_plugin_', $salt);
    }

    public function testGenerateAuthSaltWithContext()
    {
        $salt_a = Dj_App_String_Util::generateAuthSalt([ 'context' => 'plugin_a', ]);
        $salt_b = Dj_App_String_Util::generateAuthSalt([ 'context' => 'plugin_b', ]);
        $this->assertNotEquals($salt_a, $salt_b);
    }

    public function testGenerateAuthCode()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();

        $params = [
            'email' => 'test@example.com',
            'salt' => $salt,
        ];

        $code = Dj_App_String_Util::generateAuthCode($params);
        $this->assertIsString($code);
        $this->assertEquals(4, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code);
    }

    public function testGenerateAuthCodeDeterministic()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();
        $fixed_ts = 1700000000;

        $params = [
            'email' => 'test@example.com',
            'salt' => $salt,
            'timestamp' => $fixed_ts,
        ];

        // Same inputs = same code
        $code_1 = Dj_App_String_Util::generateAuthCode($params);
        $code_2 = Dj_App_String_Util::generateAuthCode($params);
        $this->assertEquals($code_1, $code_2);
    }

    public function testGenerateAuthCodeDifferentEmails()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();
        $fixed_ts = 1700000000;

        $code_a = Dj_App_String_Util::generateAuthCode([
            'email' => 'alice@example.com',
            'salt' => $salt,
            'timestamp' => $fixed_ts,
        ]);

        $code_b = Dj_App_String_Util::generateAuthCode([
            'email' => 'bob@example.com',
            'salt' => $salt,
            'timestamp' => $fixed_ts,
        ]);

        $this->assertNotEquals($code_a, $code_b);
    }

    public function testGenerateAuthCodeEmptyEmail()
    {
        $params = [
            'email' => '',
            'salt' => 'some_salt',
        ];

        $code = Dj_App_String_Util::generateAuthCode($params);
        $this->assertEmpty($code);
    }

    public function testGenerateAuthCodeEmptySalt()
    {
        $params = [
            'email' => 'test@example.com',
            'salt' => '',
        ];

        $code = Dj_App_String_Util::generateAuthCode($params);
        $this->assertEmpty($code);
    }

    public function testGenerateAuthCodeCustomLength()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();

        $params = [
            'email' => 'test@example.com',
            'salt' => $salt,
            'length' => 6,
        ];

        $code = Dj_App_String_Util::generateAuthCode($params);
        $this->assertEquals(6, strlen($code));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testVerifyAuthCode()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();

        $gen_params = [
            'email' => 'test@example.com',
            'salt' => $salt,
        ];

        $code = Dj_App_String_Util::generateAuthCode($gen_params);

        // Valid code should verify
        $verify_params = [
            'email' => 'test@example.com',
            'code' => $code,
            'salt' => $salt,
        ];

        $result = Dj_App_String_Util::verifyAuthCode($verify_params);
        $this->assertTrue($result);
    }

    public function testVerifyAuthCodeInvalid()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();

        $verify_params = [
            'email' => 'test@example.com',
            'code' => '0000',
            'salt' => $salt,
        ];

        $result = Dj_App_String_Util::verifyAuthCode($verify_params);
        $this->assertFalse($result);
    }

    public function testVerifyAuthCodeMissing()
    {
        $this->assertFalse(Dj_App_String_Util::verifyAuthCode([]));

        $salt = Dj_App_String_Util::generateAuthSalt();

        // Missing code
        $this->assertFalse(Dj_App_String_Util::verifyAuthCode([
            'email' => 'test@example.com',
            'salt' => $salt,
        ]));

        // Missing email
        $this->assertFalse(Dj_App_String_Util::verifyAuthCode([
            'code' => '1234',
            'salt' => $salt,
        ]));
    }

    public function testVerifyAuthCodeStripsSpaces()
    {
        $salt = Dj_App_String_Util::generateAuthSalt();

        $gen_params = [
            'email' => 'test@example.com',
            'salt' => $salt,
        ];

        $code = Dj_App_String_Util::generateAuthCode($gen_params);

        // Format with spaces and verify it still works
        $format_params = [ 'code' => $code, ];
        $code_formatted = Dj_App_String_Util::formatAuthCode($format_params);

        $verify_params = [
            'email' => 'test@example.com',
            'code' => $code_formatted,
            'salt' => $salt,
        ];

        $result = Dj_App_String_Util::verifyAuthCode($verify_params);
        $this->assertTrue($result);
    }

    public function testFormatAuthCode()
    {
        $params = [ 'code' => '4829', ];
        $result = Dj_App_String_Util::formatAuthCode($params);
        $this->assertEquals('48 29', $result);
    }

    public function testFormatAuthCodeSixDigit()
    {
        $params = [
            'code' => '482913',
            'chunk_size' => 3,
        ];

        $result = Dj_App_String_Util::formatAuthCode($params);
        $this->assertEquals('482 913', $result);
    }

    public function testFormatAuthCodeEmpty()
    {
        $params = [ 'code' => '', ];
        $result = Dj_App_String_Util::formatAuthCode($params);
        $this->assertEmpty($result);
    }

    public function testCleanAuthCode()
    {
        $params = [ 'code' => '48 29', ];
        $result = Dj_App_String_Util::cleanAuthCode($params);
        $this->assertEquals('4829', $result);
    }

    public function testCleanAuthCodeWithDashes()
    {
        $params = [ 'code' => '48-29', ];
        $result = Dj_App_String_Util::cleanAuthCode($params);
        $this->assertEquals('4829', $result);
    }

    public function testCleanAuthCodeEmpty()
    {
        $params = [ 'code' => '', ];
        $result = Dj_App_String_Util::cleanAuthCode($params);
        $this->assertEmpty($result);
    }

    // --- escapeShortcodeBrackets Tests ---

    public function testEscapeShortcodeBracketsBasic()
    {
        $result = Dj_App_String_Util::escapeShortcodeBrackets('[shortcode_name]');

        $this->assertEquals('&#91;shortcode_name]', $result);
    }

    public function testEscapeShortcodeBracketsMultiple()
    {
        $result = Dj_App_String_Util::escapeShortcodeBrackets('[foo] and [bar]');

        $this->assertEquals('&#91;foo] and &#91;bar]', $result);
    }

    public function testEscapeShortcodeBracketsPreservesArraySyntax()
    {
        // [ followed by digit or $ should NOT be escaped
        $result = Dj_App_String_Util::escapeShortcodeBrackets('$items[0] = "test"');

        $this->assertEquals('$items[0] = "test"', $result);
    }

    public function testEscapeShortcodeBracketsPreservesDollarVar()
    {
        $result = Dj_App_String_Util::escapeShortcodeBrackets('$data[$key]');

        $this->assertEquals('$data[$key]', $result);
    }

    public function testEscapeShortcodeBracketsMixed()
    {
        // Mix of shortcode-like and array-like brackets
        $result = Dj_App_String_Util::escapeShortcodeBrackets('$arr[0] and [shortcode] and $x[$y]');

        $this->assertStringContainsString('$arr[0]', $result);
        $this->assertStringContainsString('&#91;shortcode]', $result);
        $this->assertStringContainsString('$x[$y]', $result);
    }

    public function testEscapeShortcodeBracketsNoBrackets()
    {
        $input = 'no brackets here';
        $result = Dj_App_String_Util::escapeShortcodeBrackets($input);

        $this->assertEquals($input, $result);
    }

    public function testEscapeShortcodeBracketsEmpty()
    {
        $result = Dj_App_String_Util::escapeShortcodeBrackets('');

        $this->assertEmpty($result);
    }

    public function testEscapeShortcodeBracketsEmptyBrackets()
    {
        // [] with nothing after [ — should NOT be escaped
        $result = Dj_App_String_Util::escapeShortcodeBrackets('items[] = 5');

        $this->assertEquals('items[] = 5', $result);
    }

    public function testEscapeShortcodeBracketsTrailingBracket()
    {
        // [ at end of string — no next char
        $result = Dj_App_String_Util::escapeShortcodeBrackets('test[');

        $this->assertEquals('test[', $result);
    }

    public function testEscapeShortcodeBracketsWithParams()
    {
        $result = Dj_App_String_Util::escapeShortcodeBrackets('[djebel_static_content content_id="blog" results_per_page="15"]');

        $this->assertStringStartsWith('&#91;', $result);
        $this->assertStringContainsString('djebel_static_content', $result);
    }

    public function testEscapeShortcodeBracketsIdempotent()
    {
        // Already escaped content should not be double-escaped
        $input = '&#91;shortcode_name]';
        $result = Dj_App_String_Util::escapeShortcodeBrackets($input);

        // &#91; starts with &, not a letter, so [ before & won't match
        // and there's no [ in the input at all
        $this->assertEquals($input, $result);
    }
}
