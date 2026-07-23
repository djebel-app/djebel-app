<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Result_Test extends TestCase {

    public function setUp(): void {
    }

    public function tearDown(): void {
    }

    public function testCodeWithEmptyDoesNotChangeStoredCode()
    {
        $result_obj = new Dj_App_Result();

        // Set an initial code
        $result_obj->code('initial_code');

        // Call code() with empty string — should return existing code unchanged
        $returned = $result_obj->code('');

        $this->assertEquals('INITIAL_CODE', $returned);
    }

    public function testCodeFastPathCleanInputPassesThrough()
    {
        // Already alphanumeric + underscore — fast path skips the regex.
        // Behavior: trim leading/trailing _- and uppercase.
        $result_obj = new Dj_App_Result();
        $result_obj->code('user_not_found');

        $this->assertEquals('USER_NOT_FOUND', $result_obj->code());
    }

    public function testCodeFastPathPureAlphanumericPassesThrough()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('error404');

        $this->assertEquals('ERROR404', $result_obj->code());
    }

    public function testCodeSlowPathDirtyInputGetsSanitized()
    {
        // Special chars trigger the regex — non-word chars become _
        $result_obj = new Dj_App_Result();
        $result_obj->code('user@not#found');

        $this->assertEquals('USER_NOT_FOUND', $result_obj->code());
    }

    public function testCodeSlowPathSpacesGetReplaced()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('user not found');

        $this->assertEquals('USER_NOT_FOUND', $result_obj->code());
    }

    public function testCodeTrimsLeadingAndTrailingUnderscoresDashesSpaces()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('___error_code___');

        $this->assertEquals('ERROR_CODE', $result_obj->code());
    }

    public function testCodeReturnsUppercaseRegardlessOfInputCase()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('MixedCase_Code');

        $this->assertEquals('MIXEDCASE_CODE', $result_obj->code());
    }

    public function testCodeFastAndSlowPathsProduceSameResultForEquivalentInputs()
    {
        // Both should produce the same output — fast path (clean) and slow path (dirty)
        // are behaviorally identical.
        $result_obj_fast = new Dj_App_Result();
        $result_obj_fast->code('error_code');

        $result_obj_slow = new Dj_App_Result();
        $result_obj_slow->code('error@code');

        $this->assertEquals($result_obj_fast->code(), $result_obj_slow->code());
        $this->assertEquals('ERROR_CODE', $result_obj_fast->code());
    }

    public function testCodeWithIntegerInput()
    {
        // is_scalar accepts int — isAlphaNumericExt fast path returns true,
        // skipping the regex; the int gets implicitly stringified by trim/strtoupper.
        $result_obj = new Dj_App_Result();
        $result_obj->code('404');

        $this->assertEquals('404', $result_obj->code());
    }

    public function testJsonSerializeReturnsOnlyTheSystemKeys()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['x' => 1]);

        $struct = $result_obj->jsonSerialize();
        $expected_keys = ['status', 'msg', 'code', 'data'];

        $this->assertSame($expected_keys, array_keys($struct));
    }

    public function testJsonSerializeDoesNotExposePrivateMembers()
    {
        $result_obj = new Dj_App_Result();

        $struct = $result_obj->jsonSerialize();

        $this->assertArrayNotHasKey('expected_system_keys_regex', $struct);
    }

    public function testJsonEncodeDoesNotLeakPrivateFields()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['versions' => ['1.2.0' => ['channel' => 'stable']]]);

        $json = json_encode($result_obj);

        // A raw (array) cast would emit the mangled private property
        // "\0Dj_App_Result\0expected_system_keys_regex" (an internal regex).
        $this->assertStringNotContainsString('expected_system_keys_regex', $json);
        $this->assertStringNotContainsString("\0", $json);
    }

    public function testJsonEncodePayloadLandsUnderTheDataKey()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['versions' => ['1.2.0' => ['channel' => 'stable']]]);

        $decoded = json_decode(json_encode($result_obj), true);

        $this->assertTrue($decoded['status']);
        $this->assertArrayHasKey('data', $decoded);
        $this->assertSame(['1.2.0' => ['channel' => 'stable']], $decoded['data']['versions']);
    }
}
