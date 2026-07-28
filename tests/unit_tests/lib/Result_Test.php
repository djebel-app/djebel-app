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

        $this->assertEquals('initial_code', $returned);
    }

    public function testCodeFastPathCleanInputPassesThrough()
    {
        // Already alphanumeric + underscore — fast path skips the regex.
        // Behavior: trim leading/trailing separators and lowercase.
        $result_obj = new Dj_App_Result();
        $result_obj->code('user_not_found');

        $this->assertEquals('user_not_found', $result_obj->code());
    }

    public function testCodeFastPathPureAlphanumericPassesThrough()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('error404');

        $this->assertEquals('error404', $result_obj->code());
    }

    public function testCodeSlowPathDirtyInputGetsSanitized()
    {
        // Special chars trigger the regex — non-word chars become _
        $result_obj = new Dj_App_Result();
        $result_obj->code('user@not#found');

        $this->assertEquals('user_not_found', $result_obj->code());
    }

    public function testCodeSlowPathSpacesGetReplaced()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('user not found');

        $this->assertEquals('user_not_found', $result_obj->code());
    }

    public function testCodeTrimsLeadingAndTrailingUnderscoresDashesSpaces()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('___error_code___');

        $this->assertEquals('error_code', $result_obj->code());
    }

    public function testCodeReturnsLowercaseRegardlessOfInputCase()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('MixedCase_Code');

        $this->assertEquals('mixedcase_code', $result_obj->code());
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
        $this->assertEquals('error_code', $result_obj_fast->code());
    }

    public function testCodeWithIntegerInput()
    {
        // is_scalar accepts int — isAlphaNumericExt fast path returns true,
        // skipping the regex; the int gets implicitly stringified by trim/strtolower.
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

    public function testCodeKeepsTheDotsThatSeparateTheNamespace()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('app.oterm.dl.unknown_endpoint');

        // The dots survive as SEPARATORS. Collapsing them to underscores would give
        // app_oterm_dl_unknown_endpoint, where nothing distinguishes a namespace
        // boundary from an underscore inside the name itself.
        $this->assertSame('app.oterm.dl.unknown_endpoint', $result_obj->code());
    }

    public function testCodeTrimsStrayEdgeDots()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('.app.oterm.dl.saved.');

        $this->assertSame('app.oterm.dl.saved', $result_obj->code());
    }

    public function testCodeStillReplacesCharsThatAreNotSeparators()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->code('app.oterm/dl:saved');

        // Allowing the dot widened the set by exactly one character — a slash or a
        // colon is still not part of a code and is still replaced.
        $this->assertSame('app.oterm_dl_saved', $result_obj->code());
    }

    // NOTE — these decode with the RAW json_decode(), not Dj_App_String_Util::jsonDecode(),
    // and that is deliberate on two counts. A serializer must not be verified with its own
    // deserializer: symmetric bugs in the pair cancel out and the test still passes. And
    // jsonDecode() returns [] for input it cannot parse where the raw function returns
    // NULL, so assertIsArray() against it would hold even if toJson() emitted garbage —
    // it would make testToJsonRecoversFromNonUtf8Payload below assert nothing at all.
    // (The pre-existing tests above already decode the same way.)

    public function testToJsonMatchesJsonEncodeWithTheAppDefaultFlags()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['versions' => ['1.2.0' => ['channel' => 'stable']]]);

        $expected_json = json_encode($result_obj, Dj_App_String_Util::APP_DEFAULT_JSON_ENCODE_FLAGS);

        // toJson() is shorthand, NOT a second serialization path — if these ever
        // diverge, a Result means one thing in a log and another on the wire.
        $this->assertSame($expected_json, $result_obj->toJson());
    }

    public function testToJsonEmitsOnlyTheSystemKeysInOrder()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->msg('ok');
        $result_obj->code('app.oterm.dl.published');
        $result_obj->data(['file' => 'oterm-1.2.0-linux-x64.zip']);

        $result_struct = json_decode($result_obj->toJson(), true);
        $expected_keys = ['status', 'msg', 'code', 'data'];

        $this->assertSame($expected_keys, array_keys($result_struct));
    }

    public function testToJsonDoesNotLeakPrivateFields()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['x' => 1]);

        $json = $result_obj->toJson();

        $this->assertStringNotContainsString('expected_system_keys_regex', $json);
        $this->assertStringNotContainsString("\0", $json);
    }

    public function testToJsonAcceptsCompactFlags()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);
        $result_obj->data(['x' => 1]);

        $compact_json = $result_obj->toJson(0);

        // A wire body wants no pretty-print padding; the DEFAULT is pretty, so this
        // pins that the flags argument is actually honoured rather than ignored.
        $this->assertStringNotContainsString("\n", $compact_json);
        $this->assertSame(json_encode($result_obj, 0), $compact_json);
    }

    public function testToJsonRoundTripsThroughJsonDecode()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(false);
        $result_obj->msg('Unknown step: bogus');
        $result_obj->code('app.oterm.dl.unknown_step');
        $result_obj->data(['valid_steps' => ['build', 'deb']]);

        $result_struct = json_decode($result_obj->toJson(), true);

        $this->assertFalse($result_struct['status']);
        $this->assertSame('Unknown step: bogus', $result_struct['msg']);
        $this->assertSame(['build', 'deb'], $result_struct['data']['valid_steps']);

        // The code went through the setter, so it carries its NORMALIZED form — the
        // uppercase/underscore shape code() guarantees, not the string passed in.
        $this->assertSame($result_obj->code(), $result_struct['code']);
    }

    public function testToJsonOnAnEmptyResultStillEmitsTheStruct()
    {
        $result_obj = new Dj_App_Result();

        $result_struct = json_decode($result_obj->toJson(), true);
        $expected_keys = ['status', 'msg', 'code', 'data'];

        // An empty Result must still be valid JSON with the full shape: a caller
        // reading data['x'] on a fresh result should find a missing key, not a parse
        // error or a bare "null" body.
        $this->assertIsArray($result_struct);
        $this->assertSame($expected_keys, array_keys($result_struct));
    }

    public function testToJsonRecoversFromNonUtf8Payload()
    {
        $result_obj = new Dj_App_Result();
        $result_obj->status(true);

        // A raw byte that is not valid UTF-8 — a filename off a foreign filesystem, a
        // stderr chunk from a tool. Plain json_encode() returns FALSE here and a caller
        // echoing it would send an EMPTY body; jsonEncode() re-encodes instead, which
        // is the reason toJson() delegates rather than calling json_encode directly.
        $result_obj->data(['name' => "bad\xB1byte"]);

        $json = $result_obj->toJson();

        $this->assertNotEmpty($json);
        $this->assertIsArray(json_decode($json, true));
    }
}
