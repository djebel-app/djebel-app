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
        $this->assertFalse($result);
    }

    public function testFormatStringIdWithNull()
    {
        $result = Dj_App_String_Util::formatStringId(null);
        $this->assertFalse($result);
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
}
