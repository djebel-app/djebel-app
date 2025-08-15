<?php

use PHPUnit\Framework\TestCase;

class Page_Test extends TestCase {
    private $page_obj;
    private static $hook_test_data = [];

    public function setUp(): void {
        $this->page_obj = Dj_App_Page::getInstance();
        // Clear any existing data for clean tests
        $this->clearPageData();
        // Clear hook test data
        self::$hook_test_data = [];
    }

    public function tearDown(): void {
        // Clean up after each test
        $this->clearPageData();
    }

    /**
     * Clear the page data for clean tests
     */
    private function clearPageData() {
        // Use reflection to access the private data property
        $reflection = new ReflectionClass($this->page_obj);
        $data_property = $reflection->getProperty('data');
        $data_property->setAccessible(true);
        $data_property->setValue($this->page_obj, []);
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
        $this->assertEquals('', $this->page_obj->null_prop);
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
        Dj_App_Hooks::removeFilter('app.page.filter.pre_set_property', [$this, 'captureSetHookData']);
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
        Dj_App_Hooks::removeFilter('app.page.filter.pre_set_property_meta_title', [$this, 'modifyMetaTitleHook']);
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
        Dj_App_Hooks::removeAction('app.page.action.pre_unset_property', [$this, 'captureUnsetHookData']);
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
        Dj_App_Hooks::removeAction('app.page.action.pre_unset_property_special_prop', [$this, 'captureSpecificUnsetHook']);
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
        $this->assertEquals('', $this->page_obj->empty_string);

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
        return 'Modified: ' . $val;
    }

    public function captureUnsetHookData($ctx) {
        self::$hook_test_data['unset_called'] = true;
        self::$hook_test_data['unset_key'] = $ctx['key'];
    }

    public function captureSpecificUnsetHook($ctx) {
        self::$hook_test_data['specific_unset_called'] = true;
    }
}
