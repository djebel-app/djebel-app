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
}
