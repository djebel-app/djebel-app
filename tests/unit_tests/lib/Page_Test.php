<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Page_Test extends TestCase {
    private $page_obj;
    private static $hook_test_data = [];

    public function setUp(): void {
        $this->page_obj = Dj_App_Page::getInstance();
        self::$hook_test_data = [];
    }

    public function tearDown(): void {
        $this->page_obj->clear();
    }

    /**
     * Test basic property assignment using __set magic method
     */
    public function testPropertyAssignment() {
        // Test setting a property
        $this->page_obj->meta_title = 'Test Page Title';
        $this->assertEquals('Test Page Title', $this->page_obj->meta_title);

        // Test setting multiple properties
        $this->page_obj->meta_description = 'Test Description';
        $this->page_obj->custom_property = 'Custom Value';
        
        $this->assertEquals('Test Description', $this->page_obj->meta_description);
        $this->assertEquals('Custom Value', $this->page_obj->custom_property);
    }

    /**
     * Test property assignment with different data types
     */
    public function testPropertyAssignmentDataTypes() {
        // Test string
        $this->page_obj->string_prop = 'string value';
        $this->assertEquals('string value', $this->page_obj->string_prop);

        // Test integer
        $this->page_obj->int_prop = 123;
        $this->assertEquals(123, $this->page_obj->int_prop);

        // Test boolean
        $this->page_obj->bool_prop = true;
        $this->assertTrue($this->page_obj->bool_prop);

        // Test array
        $test_array = ['key1' => 'value1', 'key2' => 'value2'];
        $this->page_obj->array_prop = $test_array;
        $this->assertEquals($test_array, $this->page_obj->array_prop);

        // Test null (note: due to fallback behavior, null values become empty strings)
        $this->page_obj->null_prop = null;
        $this->assertEmpty($this->page_obj->null_prop);
    }

    /**
     * Test the __isset magic method
     */
    public function testPropertyExists() {
        // Test non-existent property
        $this->assertFalse(isset($this->page_obj->nonexistent));

        // Test after setting property
        $this->page_obj->test_property = 'test value';
        $this->assertTrue(isset($this->page_obj->test_property));

        // Test special computed properties
        $this->assertTrue(isset($this->page_obj->page));
        $this->assertTrue(isset($this->page_obj->full_page));
    }

    /**
     * Test the __unset magic method
     */
    public function testPropertyUnset() {
        // Set a property first
        $this->page_obj->removable_prop = 'will be removed';
        $this->assertTrue(isset($this->page_obj->removable_prop));
        $this->assertEquals('will be removed', $this->page_obj->removable_prop);

        // Unset the property
        unset($this->page_obj->removable_prop);
        
        // Verify it's removed
        $this->assertFalse(isset($this->page_obj->removable_prop));
    }

    /**
     * Test property overwriting
     */
    public function testPropertyOverwrite() {
        // Set initial value
        $this->page_obj->overwrite_test = 'initial value';
        $this->assertEquals('initial value', $this->page_obj->overwrite_test);

        // Overwrite with new value
        $this->page_obj->overwrite_test = 'new value';
        $this->assertEquals('new value', $this->page_obj->overwrite_test);
    }

    /**
     * Test hook integration for __set
     */
    public function testSetHookIntegration() {
        // Add a hook to capture the filter call
        Dj_App_Hooks::addFilter('app.page.filter.pre_set_property', [$this, 'captureSetHookData']);

        // Set a property to trigger the hook
        $this->page_obj->hook_test = 'hook test value';

        // Verify hook was called
        $this->assertTrue(isset(self::$hook_test_data['called']));
        $this->assertEquals('hook_test', self::$hook_test_data['key']);
        $this->assertEquals('hook test value', self::$hook_test_data['value']);
        $this->assertEquals('hook test value', $this->page_obj->hook_test);

        // Clean up
        $is_removed = Dj_App_Hooks::removeFilter('app.page.filter.pre_set_property', [$this, 'captureSetHookData']);
        $this->assertTrue($is_removed, 'the set listener was removed');
    }

    /**
     * Test specific property hook integration
     */
    public function testSpecificPropertyHookIntegration() {
        // Add a specific property hook
        Dj_App_Hooks::addFilter('app.page.filter.pre_set_property_meta_title', [$this, 'modifyMetaTitleHook']);

        // Set the meta_title property
        $this->page_obj->meta_title = 'Original Title';

        // Verify the specific hook was called and modified the value
        $this->assertTrue(isset(self::$hook_test_data['specific_called']));
        $this->assertEquals('Modified: Original Title', $this->page_obj->meta_title);

        // Clean up
        $is_removed = Dj_App_Hooks::removeFilter('app.page.filter.pre_set_property_meta_title', [$this, 'modifyMetaTitleHook']);
        $this->assertTrue($is_removed, 'the meta title listener was removed');
    }

    /**
     * Test hook integration for __unset
     */
    public function testUnsetHookIntegration() {
        // Add a hook to capture the action call
        Dj_App_Hooks::addAction('app.page.action.pre_unset_property', [$this, 'captureUnsetHookData']);

        // Set and then unset a property
        $this->page_obj->unset_test = 'will be removed';
        unset($this->page_obj->unset_test);

        // Verify hook was called
        $this->assertTrue(isset(self::$hook_test_data['unset_called']));
        $this->assertEquals('unset_test', self::$hook_test_data['unset_key']);
        $this->assertFalse(isset($this->page_obj->unset_test));

        // Clean up
        $is_removed = Dj_App_Hooks::removeAction('app.page.action.pre_unset_property', [$this, 'captureUnsetHookData']);
        $this->assertTrue($is_removed, 'the unset listener was removed');
    }

    /**
     * Test specific property unset hook integration
     */
    public function testSpecificPropertyUnsetHookIntegration() {
        // Add a specific property unset hook
        Dj_App_Hooks::addAction('app.page.action.pre_unset_property_special_prop', [$this, 'captureSpecificUnsetHook']);

        // Set and then unset the special property
        $this->page_obj->special_prop = 'special value';
        unset($this->page_obj->special_prop);

        // Verify the specific hook was called
        $this->assertTrue(isset(self::$hook_test_data['specific_unset_called']));
        $this->assertFalse(isset($this->page_obj->special_prop));

        // Clean up
        $is_removed = Dj_App_Hooks::removeAction('app.page.action.pre_unset_property_special_prop', [$this, 'captureSpecificUnsetHook']);
        $this->assertTrue($is_removed, 'the special unset listener was removed');
    }

    /**
     * Test singleton pattern
     */
    public function testSingletonPattern() {
        $instance_1 = Dj_App_Page::getInstance();
        $instance_2 = Dj_App_Page::getInstance();
        
        $this->assertSame($instance_1, $instance_2);
        
        // Test that properties persist across getInstance calls
        $instance_1->singleton_test = 'singleton value';
        $this->assertEquals('singleton value', $instance_2->singleton_test);
    }

    /**
     * Test computed properties (page and full_page)
     */
    public function testComputedProperties() {
        // These properties should always be available even if not explicitly set
        $this->assertTrue(isset($this->page_obj->page));
        $this->assertTrue(isset($this->page_obj->full_page));
        
        // The values should be strings (even if empty)
        $this->assertIsString($this->page_obj->page);
        $this->assertIsString($this->page_obj->full_page);
    }

    /**
     * Test edge cases and error conditions
     */
    public function testEdgeCases() {
        // Test setting empty string
        $this->page_obj->empty_string = '';
        $this->assertTrue(isset($this->page_obj->empty_string));
        $this->assertEmpty($this->page_obj->empty_string);

        // Test setting zero
        $this->page_obj->zero_value = 0;
        $this->assertTrue(isset($this->page_obj->zero_value));
        $this->assertEquals(0, $this->page_obj->zero_value);

        // Test setting false
        $this->page_obj->false_value = false;
        $this->assertTrue(isset($this->page_obj->false_value));
        $this->assertFalse($this->page_obj->false_value);
    }

    /**
     * Test backward compatibility with existing get/set methods
     */
    public function testBackwardCompatibility() {
        // Test that magic methods work alongside existing get() method
        $this->page_obj->compat_test = 'magic method value';
        
        // Should be accessible via both magic method and get() method
        $this->assertEquals('magic method value', $this->page_obj->compat_test);
        $this->assertEquals('magic method value', $this->page_obj->get('compat_test'));
    }

    /**
     * Pins that marking a page as an error makes the response answer with that code, and that the
     * visitor gets text for it even when the caller gave none.
     */
    public function testSetErrorAnswersWithTheCode() {
        $req_obj = Dj_App_Request::getInstance();
        $req_obj->setResponseCode(418);

        $error_params = [
            'code' => 410,
        ];

        $set_res = $this->page_obj->setError($error_params);
        $error = $this->page_obj->getError();
        $response_code = $req_obj->getResponseCode();

        $this->assertTrue($set_res);
        $this->assertEquals(410, $error['code']);
        $this->assertNotEmpty($error['title'], 'a default title for the code');
        $this->assertNotEmpty($error['msg'], 'a default message for the code');
        $this->assertEquals(410, $response_code, 'the response answers with the error code');
    }

    /**
     * Pins that a 404 with no text of its own reads as a missing page.
     */
    public function testSetErrorDefaultsTheNotFoundText() {
        $error_params = [
            'code' => 404,
        ];

        $this->page_obj->setError($error_params);
        $error = $this->page_obj->getError();

        $this->assertEquals('Page not found', $error['title']);
        $this->assertEquals('The requested page can not be found.', $error['msg']);
    }

    /**
     * Pins that the text a caller gives is what the visitor reads, in place of the default.
     */
    public function testSetErrorKeepsTheGivenText() {
        $error_params = [
            'code' => 404,
            'title' => 'No such post',
            'msg' => 'That post was moved.',
        ];

        $this->page_obj->setError($error_params);
        $error = $this->page_obj->getError();

        $this->assertEquals('No such post', $error['title']);
        $this->assertEquals('That post was moved.', $error['msg']);
    }

    /**
     * Pins that a code that is not an error code is refused before anything changes: a page
     * marked with a 200 or a redirect code would tell the theme it is an error page.
     */
    public function testSetErrorRefusesANonErrorCode() {
        $bad_codes = [ 0, 200, 302, 600, ];

        foreach ($bad_codes as $bad_code) {
            $exception_obj = null;

            $error_params = [
                'code' => $bad_code,
            ];

            try {
                $this->page_obj->setError($error_params);
            } catch (Dj_App_Exception $e) {
                $exception_obj = $e;
            }

            $this->assertNotNull($exception_obj, "code $bad_code is refused");

            $exception_data = $exception_obj->getData();
            $error = $this->page_obj->getError();

            $this->assertEquals('core.page.set_error.invalid_code', $exception_data['code']);
            $this->assertEmpty($error, "code $bad_code leaves the page without an error");
        }
    }

    /**
     * Pins that clear() drops the error with the rest of the page, so one render's error never
     * reaches the next one in the same process.
     */
    public function testClearDropsTheError() {
        $error_params = [
            'code' => 500,
        ];

        $this->page_obj->setError($error_params);
        $this->page_obj->clear();

        $error = $this->page_obj->getError();

        $this->assertEmpty($error);
    }

    /**
     * Hook callback methods for testing
     */
    public function captureSetHookData($val, $ctx) {
        self::$hook_test_data['called'] = true;
        self::$hook_test_data['key'] = $ctx['key'];
        self::$hook_test_data['value'] = $val;
        return $val; // Return unmodified
    }

    public function modifyMetaTitleHook($val, $ctx) {
        self::$hook_test_data['specific_called'] = true;
        $modified_val = 'Modified: ' . $val;

        return $modified_val;
    }

    public function captureUnsetHookData($ctx) {
        self::$hook_test_data['unset_called'] = true;
        self::$hook_test_data['unset_key'] = $ctx['key'];
    }

    public function captureSpecificUnsetHook($ctx) {
        self::$hook_test_data['specific_unset_called'] = true;
    }
}
