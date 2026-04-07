<?php

use PHPUnit\Framework\TestCase;

class Hooks_Test extends TestCase {
    public function testAddFilter() {
        Dj_App_Hooks::addFilter( 'app.core.test.return_false', Dj_App_Hooks::RETURN_FALSE );
        $this->assertFalse(Dj_App_Hooks::hasRun('app.core.test.return_false'));
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.return_false', true );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.return_false'));
        $this->assertFalse($res);

        // now we remove the filter and the default value should be returned
        Dj_App_Hooks::removeFilter( 'app.core.test.return_false', Dj_App_Hooks::RETURN_FALSE );
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.return_false', true );
        $this->assertTrue($res);

        Dj_App_Hooks::addAction( 'app.core.test.just_run', [ $this, 'sampleAction' ] );
        $this->assertFalse(Dj_App_Hooks::hasRun('app.core.test.just_run'));
        Dj_App_Hooks::doAction( 'app.core.test.just_run' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.just_run'));

        Dj_App_Hooks::addFilter( 'app.core.test.return_true', Dj_App_Hooks::RETURN_TRUE );
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.return_true', true );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.return_true'));
    }

    public function testAddFilterWithStaticMethodCallbacks() {
        // Test string format static method callback
        Dj_App_Hooks::addFilter( 'app.core.test.static_string', ['Hooks_Test', 'staticStringMethod'] );
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.static_string', 'test' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.static_string'));
        $this->assertEquals(4, $res);

        // Test array format static method callback
        Dj_App_Hooks::addFilter( 'app.core.test.static_array', ['Hooks_Test', 'staticTestMethod'] );
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.static_array', 'test' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.static_array'));
        $this->assertEquals('test_processed', $res);

        // Clean up
        Dj_App_Hooks::removeFilter( 'app.core.test.static_string', ['Hooks_Test', 'staticStringMethod'] );
        Dj_App_Hooks::removeFilter( 'app.core.test.static_array', ['Hooks_Test', 'staticTestMethod'] );
    }

    public function testCheckAllowedEmptyHookName() {
        $ctx = [
            'hook_name' => '',
            'callback' => 'test_callback'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Empty hook name', $result->msg());
    }

    public function testCheckAllowedInvalidHookNameType() {
        $ctx = [
            'hook_name' => new stdClass(),
            'callback' => 'test_callback'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid filter name. We\'re expecting a scalar or an array, something else was given.', $result->msg());
    }

    public function testCheckAllowedEmptyCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => ''
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Empty callback', $result->msg());
    }

    public function testCheckAllowedInvalidScalarCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => 'invalid_predefined_return'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid callback for filter: [test_hook]', $result->msg());
    }

    public function testCheckAllowedNonCallableCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => 'non_existent_function'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid callback for filter: [test_hook]', $result->msg());
    }

    public function testCheckAllowedClosureCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => function() { return true; }
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid callback: callbacks cannot be a closure: [test_hook]', $result->msg());
    }

    public function testCheckAllowedValidPredefinedReturn() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => Dj_App_Hooks::RETURN_FALSE
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidFunctionCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => 'strlen'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidArrayCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => [$this, 'sampleAction']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidCallableFunction() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => 'strlen'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidStringHookName() {
        $ctx = [
            'hook_name' => 'valid_hook_name',
            'callback' => Dj_App_Hooks::RETURN_TRUE
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidArrayHookName() {
        $ctx = [
            'hook_name' => ['hook1', 'hook2'],
            'callback' => Dj_App_Hooks::RETURN_TRUE
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedAllPredefinedReturns() {
        $predefined_returns = [
            Dj_App_Hooks::RETURN_ZERO,
            Dj_App_Hooks::RETURN_TRUE,
            Dj_App_Hooks::RETURN_FALSE,
            Dj_App_Hooks::RETURN_NULL,
            Dj_App_Hooks::RETURN_EMPTY_STRING,
            Dj_App_Hooks::RETURN_EMPTY_ARRAY
        ];
        
        foreach ($predefined_returns as $return) {
            $ctx = [
                'hook_name' => 'test_hook',
                'callback' => $return
            ];
            
            $result = Dj_App_Hooks::checkAllowed($ctx);
            
            $this->assertInstanceOf('Dj_App_Result', $result);
            $this->assertTrue($result->isSuccess(), "Failed for callback: $return");
            $this->assertEquals(1, $result->status());
        }
    }



    public function testCheckAllowedMissingContextKeys() {
        $ctx = [];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Empty hook name', $result->msg());
    }

    public function testCheckAllowedNullValues() {
        $ctx = [
            'hook_name' => null,
            'callback' => null
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Empty hook name', $result->msg());
    }

    public function testCheckAllowedValidStaticMethodCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => 'Dj_App_Util::injectBodyClasses'
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedValidArrayStaticMethodCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => ['Dj_App_Util', 'injectBodyClasses']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testCheckAllowedInvalidArrayCallbackTooManyElements() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => ['Class', 'method', 'extra']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid array callback format. Expected [class, method] or [object, method]: [test_hook]', $result->msg());
    }

    public function testCheckAllowedInvalidArrayCallbackTooFewElements() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => ['Class']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid array callback format. Expected [class, method] or [object, method]: [test_hook]', $result->msg());
    }

    public function testCheckAllowedInvalidArrayCallbackNotCallable() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => ['NonExistentClass', 'nonExistentMethod']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isError());
        $this->assertEquals('Invalid array callback for hook: [test_hook]', $result->msg());
    }

    public function testCheckAllowedValidObjectMethodCallback() {
        $ctx = [
            'hook_name' => 'test_hook',
            'callback' => [$this, 'sampleAction']
        ];
        
        $result = Dj_App_Hooks::checkAllowed($ctx);
        
        $this->assertInstanceOf('Dj_App_Result', $result);
        $this->assertTrue($result->isSuccess());
        $this->assertEquals(1, $result->status());
    }

    public function testOriginalStaticMethodCallbackFormat() {
        // Test the exact format that was causing the original error
        Dj_App_Hooks::addFilter( 'app.page.full_content', 'Hooks_Test::staticTestMethod' );
        $res = Dj_App_Hooks::applyFilter( 'app.page.full_content', 'test' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.page.full_content'));
        $this->assertEquals('test_processed', $res);

        // Clean up
        Dj_App_Hooks::removeFilter( 'app.page.full_content', 'Hooks_Test::staticTestMethod' );
    }

    public function testStaticMethodAsStringCallback() {
        // Test static method as string format (the original issue)
        Dj_App_Hooks::addFilter( 'app.core.test.static_string', 'Hooks_Test::staticTestMethod' );
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.static_string', 'hello' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.static_string'));
        $this->assertEquals('hello_processed', $res);

        // Test with a different static method - use a unique hook name to avoid interference
        Dj_App_Hooks::addFilter( 'unique.test.static_string', 'Hooks_Test::staticStringMethod' );
        $res = Dj_App_Hooks::applyFilter( 'unique.test.static_string', 'abc' );
        $this->assertTrue(Dj_App_Hooks::hasRun('unique.test.static_string'));
        $this->assertEquals(3, $res, "Expected 3, got: " . var_export($res, true));

        // Test with the exact format from the original error
        Dj_App_Hooks::addFilter( 'app.page.full_content', 'Hooks_Test::staticTestMethod' );
        $res = Dj_App_Hooks::applyFilter( 'app.page.full_content', 'original_test' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.page.full_content'));
        $this->assertEquals('original_test_processed', $res);

        // Clean up
        Dj_App_Hooks::removeFilter( 'app.core.test.static_string', 'Hooks_Test::staticTestMethod' );
        Dj_App_Hooks::removeFilter( 'unique.test.static_string', 'Hooks_Test::staticStringMethod' );
        Dj_App_Hooks::removeFilter( 'app.page.full_content', 'Hooks_Test::staticTestMethod' );
    }

    public function testSpecificStaticArrayFilter() {
        // Test the exact pattern requested
        Dj_App_Hooks::addFilter( 'app.core.test.static_array', 'Hooks_Test::staticTestMethod' );
        
        // Verify the filter was added and works
        $res = Dj_App_Hooks::applyFilter( 'app.core.test.static_array', 'test_value' );
        $this->assertTrue(Dj_App_Hooks::hasRun('app.core.test.static_array'));
        $this->assertEquals('test_value_processed', $res);
        
        // Test with different input
        $res2 = Dj_App_Hooks::applyFilter( 'app.core.test.static_array', 'another_value' );
        $this->assertEquals('another_value_processed', $res2);
        
        // Clean up
        Dj_App_Hooks::removeFilter( 'app.core.test.static_array', 'Hooks_Test::staticTestMethod' );
        
        // Verify cleanup worked
        $res3 = Dj_App_Hooks::applyFilter( 'app.core.test.static_array', 'test_value' );
        $this->assertEquals('test_value', $res3); // Should return original value without processing
    }

    public function testHasAction() {
        // Test that hasAction returns false for non-existent actions
        $this->assertFalse(Dj_App_Hooks::hasAction('non_existent_action'));
        
        // Add an action
        Dj_App_Hooks::addAction('test_has_action', [$this, 'sampleAction']);
        
        // Test that hasAction returns true for existing actions
        $this->assertTrue(Dj_App_Hooks::hasAction('test_has_action'));
        
        // Test with different hook name formats
        $this->assertTrue(Dj_App_Hooks::hasAction('TEST_HAS_ACTION'));
        $this->assertTrue(Dj_App_Hooks::hasAction('test-has-action'));
        // Note: test.has.action becomes test/has/action, but we need to add it with the correct format first
        Dj_App_Hooks::addAction('test/has/action', [$this, 'sampleAction']);
        $this->assertTrue(Dj_App_Hooks::hasAction('test/has/action'));
        Dj_App_Hooks::removeAction('test/has/action', [$this, 'sampleAction']);
        
        // Clean up
        Dj_App_Hooks::removeAction('test_has_action', [$this, 'sampleAction']);
        
        // Verify cleanup worked
        $this->assertFalse(Dj_App_Hooks::hasAction('test_has_action'));
    }

    public function testHasFilter() {
        // Test that hasFilter returns false for non-existent filters
        $this->assertFalse(Dj_App_Hooks::hasFilter('non_existent_filter'));
        
        // Add a filter
        Dj_App_Hooks::addFilter('test_has_filter', 'Hooks_Test::staticTestMethod');
        
        // Test that hasFilter returns true for existing filters
        $this->assertTrue(Dj_App_Hooks::hasFilter('test_has_filter'));
        
        // Test with different hook name formats
        $this->assertTrue(Dj_App_Hooks::hasFilter('TEST_HAS_FILTER'));
        $this->assertTrue(Dj_App_Hooks::hasFilter('test-has-filter'));
        // Note: test.has.filter becomes test/has/filter, but we need to add it with the correct format first
        Dj_App_Hooks::addFilter('test/has/filter', 'Hooks_Test::staticTestMethod');
        $this->assertTrue(Dj_App_Hooks::hasFilter('test/has/filter'));
        Dj_App_Hooks::removeFilter('test/has/filter', 'Hooks_Test::staticTestMethod');
        
        // Clean up
        Dj_App_Hooks::removeFilter('test_has_filter', 'Hooks_Test::staticTestMethod');
        
        // Verify cleanup worked
        $this->assertFalse(Dj_App_Hooks::hasFilter('test_has_filter'));
    }

    public function testHasActionWithMultipleCallbacks() {
        // Add multiple actions to the same hook
        Dj_App_Hooks::addAction('multi_action', [$this, 'sampleAction']);
        Dj_App_Hooks::addAction('multi_action', 'Hooks_Test::staticTestMethod');
        
        // Should return true even with multiple callbacks
        $this->assertTrue(Dj_App_Hooks::hasAction('multi_action'));
        
        // Remove one callback
        Dj_App_Hooks::removeAction('multi_action', [$this, 'sampleAction']);
        
        // Should still return true as there's another callback
        $this->assertTrue(Dj_App_Hooks::hasAction('multi_action'));
        
        // Remove the last callback
        Dj_App_Hooks::removeAction('multi_action', 'Hooks_Test::staticTestMethod');
        
        // Should now return false
        $this->assertFalse(Dj_App_Hooks::hasAction('multi_action'));
    }

    public function testHasFilterWithMultipleCallbacks() {
        // Add multiple filters to the same hook
        Dj_App_Hooks::addFilter('multi_filter', 'Hooks_Test::staticTestMethod');
        Dj_App_Hooks::addFilter('multi_filter', 'Hooks_Test::staticStringMethod');
        
        // Should return true even with multiple callbacks
        $this->assertTrue(Dj_App_Hooks::hasFilter('multi_filter'));
        
        // Remove one callback
        Dj_App_Hooks::removeFilter('multi_filter', 'Hooks_Test::staticTestMethod');
        
        // Should still return true as there's another callback
        $this->assertTrue(Dj_App_Hooks::hasFilter('multi_filter'));
        
        // Remove the last callback
        Dj_App_Hooks::removeFilter('multi_filter', 'Hooks_Test::staticStringMethod');
        
        // Should now return false
        $this->assertFalse(Dj_App_Hooks::hasFilter('multi_filter'));
    }

    public function testHasActionWithDifferentPriorities() {
        // Add actions with different priorities
        Dj_App_Hooks::addAction('priority_action', [$this, 'sampleAction'], 10);
        Dj_App_Hooks::addAction('priority_action', 'Hooks_Test::staticTestMethod', 20);
        
        // Should return true regardless of priority
        $this->assertTrue(Dj_App_Hooks::hasAction('priority_action'));
        
        // Clean up
        Dj_App_Hooks::removeAction('priority_action', [$this, 'sampleAction'], 10);
        Dj_App_Hooks::removeAction('priority_action', 'Hooks_Test::staticTestMethod', 20);
        
        // Should now return false
        $this->assertFalse(Dj_App_Hooks::hasAction('priority_action'));
    }

    public function testHasFilterWithDifferentPriorities() {
        // Add filters with different priorities
        Dj_App_Hooks::addFilter('priority_filter', 'Hooks_Test::staticTestMethod', 10);
        Dj_App_Hooks::addFilter('priority_filter', 'Hooks_Test::staticStringMethod', 20);
        
        // Should return true regardless of priority
        $this->assertTrue(Dj_App_Hooks::hasFilter('priority_filter'));
        
        // Clean up
        Dj_App_Hooks::removeFilter('priority_filter', 'Hooks_Test::staticTestMethod', 10);
        Dj_App_Hooks::removeFilter('priority_filter', 'Hooks_Test::staticStringMethod', 20);
        
        // Should now return false
        $this->assertFalse(Dj_App_Hooks::hasFilter('priority_filter'));
    }

    public function testHasActionEdgeCases() {
        // Test with empty string
        $this->assertFalse(Dj_App_Hooks::hasAction(''));
        
        // Test with null (should be converted to string)
        $this->assertFalse(Dj_App_Hooks::hasAction(null));
        
        // Test with numeric hook name
        Dj_App_Hooks::addAction('123', [$this, 'sampleAction']);
        $this->assertTrue(Dj_App_Hooks::hasAction('123'));
        
        // Clean up
        Dj_App_Hooks::removeAction('123', [$this, 'sampleAction']);
    }

    public function testHasFilterEdgeCases() {
        // Test with empty string
        $this->assertFalse(Dj_App_Hooks::hasFilter(''));
        
        // Test with null (should be converted to string)
        $this->assertFalse(Dj_App_Hooks::hasFilter(null));
        
        // Test with numeric hook name
        Dj_App_Hooks::addFilter('456', 'Hooks_Test::staticTestMethod');
        $this->assertTrue(Dj_App_Hooks::hasFilter('456'));
        
        // Clean up
        Dj_App_Hooks::removeFilter('456', 'Hooks_Test::staticTestMethod');
    }

    public function testHasHook() {
        // Test that hasHook returns false for non-existent hooks
        $this->assertFalse(Dj_App_Hooks::hasHook('non_existent_hook'));
        
        // Add an action
        Dj_App_Hooks::addAction('test_has_hook', [$this, 'sampleAction']);
        
        // Test that hasHook returns true for existing actions
        $this->assertTrue(Dj_App_Hooks::hasHook('test_has_hook'));
        
        // Clean up action
        Dj_App_Hooks::removeAction('test_has_hook', [$this, 'sampleAction']);
        
        // Add a filter
        Dj_App_Hooks::addFilter('test_has_hook', 'Hooks_Test::staticTestMethod');
        
        // Test that hasHook returns true for existing filters
        $this->assertTrue(Dj_App_Hooks::hasHook('test_has_hook'));
        
        // Clean up filter
        Dj_App_Hooks::removeFilter('test_has_hook', 'Hooks_Test::staticTestMethod');
        
        // Verify cleanup worked
        $this->assertFalse(Dj_App_Hooks::hasHook('test_has_hook'));
    }

    public function testHasHookWithBothActionAndFilter() {
        // Add both action and filter to the same hook name
        Dj_App_Hooks::addAction('dual_hook', [$this, 'sampleAction']);
        Dj_App_Hooks::addFilter('dual_hook', 'Hooks_Test::staticTestMethod');
        
        // Should return true since both exist
        $this->assertTrue(Dj_App_Hooks::hasHook('dual_hook'));
        
        // Remove action, filter should still exist
        Dj_App_Hooks::removeAction('dual_hook', [$this, 'sampleAction']);
        $this->assertTrue(Dj_App_Hooks::hasHook('dual_hook'));
        
        // Remove filter, nothing should exist
        Dj_App_Hooks::removeFilter('dual_hook', 'Hooks_Test::staticTestMethod');
        $this->assertFalse(Dj_App_Hooks::hasHook('dual_hook'));
    }

    public function testHasHookWithDifferentFormats() {
        // Add an action
        Dj_App_Hooks::addAction('format_test', [$this, 'sampleAction']);
        
        // Test with different hook name formats
        $this->assertTrue(Dj_App_Hooks::hasHook('FORMAT_TEST'));
        $this->assertTrue(Dj_App_Hooks::hasHook('format-test'));
        // Note: format.test becomes format/test, but we need to add it with the correct format first
        Dj_App_Hooks::addAction('format/test', [$this, 'sampleAction']);
        $this->assertTrue(Dj_App_Hooks::hasHook('format/test'));
        Dj_App_Hooks::removeAction('format/test', [$this, 'sampleAction']);
        
        // Clean up
        Dj_App_Hooks::removeAction('format_test', [$this, 'sampleAction']);
    }

    public function testHasHookEdgeCases() {
        // Test with empty string
        $this->assertFalse(Dj_App_Hooks::hasHook(''));
        
        // Test with null
        $this->assertFalse(Dj_App_Hooks::hasHook(null));
        
        // Test with numeric hook name
        Dj_App_Hooks::addAction('789', [$this, 'sampleAction']);
        $this->assertTrue(Dj_App_Hooks::hasHook('789'));
        
        // Clean up
        Dj_App_Hooks::removeAction('789', [$this, 'sampleAction']);
    }

    public function testHasHookPerformance() {
        // Test that hasHook is efficient and doesn't cause issues with many hooks
        for ($i = 0; $i < 10; $i++) {
            Dj_App_Hooks::addAction("perf_test_$i", [$this, 'sampleAction']);
        }
        
        // Check all hooks exist
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue(Dj_App_Hooks::hasHook("perf_test_$i"));
        }
        
        // Check non-existent hook
        $this->assertFalse(Dj_App_Hooks::hasHook('non_existent_perf_test'));
        
        // Clean up
        for ($i = 0; $i < 10; $i++) {
            Dj_App_Hooks::removeAction("perf_test_$i", [$this, 'sampleAction']);
        }
    }

    public function testFormatHookNameBasic()
    {
        // Test basic formatting - spaces are converted to /, case to lowercase
        $this->assertEquals('my/hook', Dj_App_Hooks::formatHookName('my hook'));
        $this->assertEquals('my/hook', Dj_App_Hooks::formatHookName('MY HOOK'));
        $this->assertEquals('my/hook', Dj_App_Hooks::formatHookName('My Hook'));
        $this->assertEquals('test/hook/name', Dj_App_Hooks::formatHookName('test hook name'));

        // Test with underscores (underscores are preserved)
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my_hook'));
        $this->assertEquals('test_hook_name', Dj_App_Hooks::formatHookName('TEST_HOOK_NAME'));
    }

    public function testFormatHookNameLeadingTrailingJunk()
    {
        // Test leading junk removal (spaces, tabs, newlines, special chars)
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('   my_hook'));
        $this->assertEquals('123my_hook', Dj_App_Hooks::formatHookName('123my_hook'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('___my_hook'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('---my_hook'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName(':::my_hook'));

        // Test trailing junk removal
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my_hook   '));
        $this->assertEquals('my_hook123', Dj_App_Hooks::formatHookName('my_hook123'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my_hook___'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my_hook---'));

        // Test both leading and trailing (digits preserved)
        $this->assertEquals('123_my_hook_456', Dj_App_Hooks::formatHookName('123___my_hook___456'));
    }

    public function testFormatHookNameSeparatorNormalization()
    {
        // Spaces, colons, dots all normalize to /
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app core hook'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app:core:hook'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app.core.hook'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app : core . hook'));

        // Mixed separators
        $this->assertEquals('app/core/test/hook', Dj_App_Hooks::formatHookName('app.core:test hook'));
    }

    public function testFormatHookNameNonWordCharReplacement()
    {
        // Test non-word characters get replaced with underscore
        $this->assertEquals('my_hook_test', Dj_App_Hooks::formatHookName('my@hook#test'));
        $this->assertEquals('my_hook_test', Dj_App_Hooks::formatHookName('my!hook$test'));
        $this->assertEquals('my_hook_test', Dj_App_Hooks::formatHookName('my%hook^test'));
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my&hook'));
    }

    public function testFormatHookNameDuplicateSeparatorCollapsing()
    {
        // Test duplicate underscores collapse
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my___hook'));
        $this->assertEquals('my_hook_test', Dj_App_Hooks::formatHookName('my____hook____test'));

        // Test duplicate slashes collapse
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app///core///hook'));

        // Test mixed duplicate separators
        $this->assertEquals('app/core_hook', Dj_App_Hooks::formatHookName('app///core___hook'));
    }

    public function testFormatHookNamePluralization()
    {
        // Pluralization now works: /plugins/ -> /plugin/, /themes/ -> /theme/, etc.
        // It runs after dots/dashes are converted to /

        // With dots (converted to slashes) - pluralization works
        $this->assertEquals('app/plugin/my_plugin/action', Dj_App_Hooks::formatHookName('app.plugins.my-plugin.action'));
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app.plugins.test'));
        $this->assertEquals('app/theme/my_theme/action', Dj_App_Hooks::formatHookName('app.themes.my-theme.action'));
        $this->assertEquals('app/theme/setup', Dj_App_Hooks::formatHookName('app.themes.setup'));

        // Test all plural forms
        $this->assertEquals('app/page/render', Dj_App_Hooks::formatHookName('app.pages.render'));
        $this->assertEquals('app/app/init', Dj_App_Hooks::formatHookName('app.apps.init'));

        // With dashes - converted to underscores, so no slashes for pluralization to match
        $this->assertEquals('app_plugins_my_plugin_action', Dj_App_Hooks::formatHookName('app-plugins-my-plugin-action'));
        $this->assertEquals('app_themes_test', Dj_App_Hooks::formatHookName('app-themes-test'));

        // Test that 's' is preserved when NOT in /word/ pattern
        $this->assertEquals('apps_test', Dj_App_Hooks::formatHookName('apps_test'));
        $this->assertEquals('test/apps', Dj_App_Hooks::formatHookName('test.apps'));
        $this->assertEquals('status_check', Dj_App_Hooks::formatHookName('status_check'));
    }

    public function testFormatHookNameEdgeCases()
    {
        // Test empty string
        $this->assertEquals('', Dj_App_Hooks::formatHookName(''));

        // Test only special characters
        $this->assertEquals('', Dj_App_Hooks::formatHookName('!!!@@@###'));
        $this->assertEquals('', Dj_App_Hooks::formatHookName('...:::///'));

        // Test only numbers (digits are preserved as valid hook content)
        $this->assertEquals('123456', Dj_App_Hooks::formatHookName('123456'));

        // Test single character
        $this->assertEquals('a', Dj_App_Hooks::formatHookName('a'));
        $this->assertEquals('z', Dj_App_Hooks::formatHookName('Z'));

        // Test very long hook name (100 char limit)
        $long_hook = str_repeat('test_hook_', 20); // 200 chars
        $result = Dj_App_Hooks::formatHookName($long_hook);
        $this->assertLessThanOrEqual(100, strlen($result));
    }

    public function testFormatHookNameRealWorldExamples()
    {
        // Test typical WordPress-style hooks with pluralization
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('app.core.init'));
        $this->assertEquals('app/plugin/loaded', Dj_App_Hooks::formatHookName('app.plugins.loaded'));
        $this->assertEquals('app/theme/setup', Dj_App_Hooks::formatHookName('app.themes.setup'));

        // Test hooks with dashes (common in theme/plugin names)
        $this->assertEquals('app/plugin/my_plugin/action', Dj_App_Hooks::formatHookName('app.plugins.my-plugin.action'));
        $this->assertEquals('app/theme/my_theme/hook', Dj_App_Hooks::formatHookName('app.themes.my-theme.hook'));

        // Test mixed formats
        $this->assertEquals('app/page/content/render', Dj_App_Hooks::formatHookName('APP.PAGE.CONTENT.RENDER'));
        $this->assertEquals('app/core/theme/loaded', Dj_App_Hooks::formatHookName('app:core:theme:loaded'));
    }

    public function testFormatHookNamePreservesStructure()
    {
        // Test that valid hook names are properly formatted
        $this->assertEquals('app/plugin/test_action', Dj_App_Hooks::formatHookName('app.plugin.test_action'));
        $this->assertEquals('app/core/my_hook', Dj_App_Hooks::formatHookName('app.core.my_hook'));

        // Test underscores in hook names are preserved (not converted to slash)
        $this->assertEquals('my_custom_hook', Dj_App_Hooks::formatHookName('my_custom_hook'));
        $this->assertEquals('test_hook_name', Dj_App_Hooks::formatHookName('test_hook_name'));
    }

    public function testFormatHookNameComplexCases()
    {
        // Test complex real-world scenarios with pluralization
        $this->assertEquals('app/plugin/my_plugin/filter/pre_set_property',
            Dj_App_Hooks::formatHookName('app.plugins.my-plugin.filter.pre_set_property'));

        $this->assertEquals('123app/page/full_content',
            Dj_App_Hooks::formatHookName('  123app.page.full_content___  '));

        $this->assertEquals('app/theme/current_theme_dir',
            Dj_App_Hooks::formatHookName('APP.THEMES.CURRENT-THEME-DIR'));

        // Test with multiple consecutive special chars
        $this->assertEquals('my_hook_test',
            Dj_App_Hooks::formatHookName('my@@@hook###test'));
    }

    public function testIsHookBasicMatching() {
        // Test exact match
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', 'app/core/init'));
        $this->assertTrue(Dj_App_Hooks::isHook('my_hook', 'my_hook'));

        // Test non-matching hooks
        $this->assertFalse(Dj_App_Hooks::isHook('app/core/init', 'app/core/shutdown'));
        $this->assertFalse(Dj_App_Hooks::isHook('my_hook', 'other_hook'));
    }

    public function testIsHookDifferentFormats() {
        // Test that different formats normalize to the same hook
        // Dots become slashes
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', 'app.core.init'));
        $this->assertTrue(Dj_App_Hooks::isHook('app.core.init', 'app/core/init'));

        // Colons become slashes
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', 'app:core:init'));
        $this->assertTrue(Dj_App_Hooks::isHook('app:core:init', 'app.core.init'));

        // Spaces become slashes
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', 'app core init'));

        // Mixed formats
        $this->assertTrue(Dj_App_Hooks::isHook('app.core:init', 'app/core/init'));
    }

    public function testIsHookCaseInsensitive() {
        // Test case insensitivity
        $this->assertTrue(Dj_App_Hooks::isHook('APP/CORE/INIT', 'app/core/init'));
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', 'APP/CORE/INIT'));
        $this->assertTrue(Dj_App_Hooks::isHook('App.Core.Init', 'app/core/init'));
        $this->assertTrue(Dj_App_Hooks::isHook('MY_HOOK', 'my_hook'));
    }

    public function testIsHookWithPluralization() {
        // Test pluralization normalization: plugins -> plugin, themes -> theme
        $this->assertTrue(Dj_App_Hooks::isHook('app/plugins/test', 'app.plugin.test'));
        $this->assertTrue(Dj_App_Hooks::isHook('app.plugin.test', 'app/plugins/test'));
        $this->assertTrue(Dj_App_Hooks::isHook('app/themes/setup', 'app.theme.setup'));
        $this->assertTrue(Dj_App_Hooks::isHook('app.pages.render', 'app/page/render'));
    }

    public function testIsHookEmptyValues() {
        // Test empty hook returns false
        $this->assertFalse(Dj_App_Hooks::isHook('', 'app/core/init'));
        $this->assertFalse(Dj_App_Hooks::isHook('app/core/init', ''));
        $this->assertFalse(Dj_App_Hooks::isHook('', ''));

        // Test null values return false
        $this->assertFalse(Dj_App_Hooks::isHook(null, 'app/core/init'));
        $this->assertFalse(Dj_App_Hooks::isHook('app/core/init', null));
        $this->assertFalse(Dj_App_Hooks::isHook(null, null));
    }

    public function testIsHookWithLeadingTrailingJunk() {
        // Test that leading/trailing junk is stripped before comparison
        $this->assertTrue(Dj_App_Hooks::isHook('  app/core/init  ', 'app/core/init'));
        $this->assertTrue(Dj_App_Hooks::isHook('app/core/init', '___app/core/init___'));
        $this->assertFalse(Dj_App_Hooks::isHook('123app/core/init456', 'app/core/init'));
        $this->assertTrue(Dj_App_Hooks::isHook('123app/core/init456', '123app/core/init456'));
        $this->assertTrue(Dj_App_Hooks::isHook('---app/core/init---', 'app/core/init'));
    }

    public function testIsHookWithSpecialCharacters() {
        // Test hooks with dashes (converted to underscores)
        $this->assertTrue(Dj_App_Hooks::isHook('my-hook-name', 'my_hook_name'));
        $this->assertTrue(Dj_App_Hooks::isHook('app.plugins.my-plugin.action', 'app/plugin/my_plugin/action'));
    }

    public function testIsHookRealWorldExamples() {
        // Test real-world hook comparison scenarios (like the original use case)
        $hook = 'qs_app/chats/messages/action/insert';
        $expected = 'qs_app/chats/messages/action/insert';

        $this->assertTrue(Dj_App_Hooks::isHook($hook, $expected));

        // Test with dot notation expected hook
        $this->assertTrue(Dj_App_Hooks::isHook($hook, 'qs_app.chats.messages.action.insert'));

        // Test with different hooks
        $this->assertFalse(Dj_App_Hooks::isHook($hook, 'qs_app/chats/messages/action/update'));
        $this->assertFalse(Dj_App_Hooks::isHook($hook, 'qs_app/chats/messages/action/delete'));

        // Test plugin hooks
        $this->assertTrue(Dj_App_Hooks::isHook(
            'app/plugin/static_content/post_loaded',
            'app.plugins.static-content.post-loaded'
        ));

        // Test theme hooks
        $this->assertTrue(Dj_App_Hooks::isHook(
            'app/theme/my_theme/setup',
            'app.themes.my-theme.setup'
        ));
    }

    public function testIsHookCallbackScenario() {
        // Simulate the callback scenario where $hook is passed as parameter
        $callback_hook = 'app/plugin/test/action';

        // Check if we're in the right hook
        $this->assertTrue(Dj_App_Hooks::isHook($callback_hook, 'app.plugin.test.action'));

        // Check we're not in a different hook
        $this->assertFalse(Dj_App_Hooks::isHook($callback_hook, 'app.plugin.other.action'));
    }

    public function testIsHookEdgeCases() {
        // Test hooks that are similar but different
        $this->assertFalse(Dj_App_Hooks::isHook('app/core', 'app/core/init'));
        $this->assertFalse(Dj_App_Hooks::isHook('app/core/init', 'app/core'));
        $this->assertFalse(Dj_App_Hooks::isHook('app/core/init/extra', 'app/core/init'));

        // Test single word hooks
        $this->assertTrue(Dj_App_Hooks::isHook('init', 'init'));
        $this->assertTrue(Dj_App_Hooks::isHook('INIT', 'init'));
        $this->assertFalse(Dj_App_Hooks::isHook('init', 'shutdown'));

        // Test numeric components
        $this->assertTrue(Dj_App_Hooks::isHook('app/v2/api', 'app.v2.api'));
    }

    public function testFormatHookNameSlashSeparator()
    {
        // Forward slash is a valid separator and passes through unchanged
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app/plugin/test'));
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('app/core/init'));

        // Double slashes collapse to single
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app//plugin//test'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app///core///hook'));

        // Leading and trailing slashes are trimmed
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('/app/core/init'));
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('app/core/init/'));
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('/app/core/init/'));
        $this->assertEquals('app/core/init', Dj_App_Hooks::formatHookName('///app/core/init///'));
    }

    public function testFormatHookNameDotSeparator()
    {
        // Dots normalize to /
        $this->assertEquals('app/plugin/simple_newsletter/verify_form_end',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.verify_form_end'));

        $this->assertEquals('app/plugin/simple_newsletter/verify_form_start',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.verify_form_start'));

        $this->assertEquals('app/plugin/simple_newsletter/form_start',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.form_start'));

        $this->assertEquals('app/plugin/simple_newsletter/form_end',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.form_end'));

        $this->assertEquals('app/plugin/simple_newsletter/data',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.data'));

        $this->assertEquals('app/plugin/simple_newsletter/verification_email',
            Dj_App_Hooks::formatHookName('app.plugin.simple_newsletter.verification_email'));
    }

    public function testFormatHookNameMixedSeparators()
    {
        // All separator styles produce the same canonical / output
        $this->assertEquals('app/plugin/test/action',
            Dj_App_Hooks::formatHookName('app.plugin.test.action'));

        $this->assertEquals('app/plugin/test/action',
            Dj_App_Hooks::formatHookName('app/plugin/test/action'));

        $this->assertEquals('app/plugin/test/action',
            Dj_App_Hooks::formatHookName('app.plugin/test.action'));

        $this->assertEquals('app/plugin/test/action',
            Dj_App_Hooks::formatHookName('app/plugin.test/action'));

        // Slash + dot adjacent normalizes properly
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('app/.plugin./test'));
    }

    public function testFormatHookNameSpacesAroundSeparators()
    {
        // Spaces around slashes
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app / plugin / test'));
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app /plugin/ test'));

        // Spaces around dots
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app . plugin . test'));
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('app .plugin. test'));

        // Leading and trailing spaces
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('  app/plugin/test  '));
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName('  app.plugin.test  '));

        // Tabs and newlines around separators
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName("app\t.\tplugin\t.\ttest"));
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName("app\n.\nplugin\n.\ntest"));

        // Mixed spaces and separators
        $this->assertEquals('app/plugin/simple_newsletter/data',
            Dj_App_Hooks::formatHookName(' app . plugin . simple_newsletter . data '));
    }

    public function testHookRegistrationWithSlashNames()
    {
        // Register with / separator
        Dj_App_Hooks::addFilter('app/plugin/slash_test', 'Hooks_Test::staticTestMethod');
        $this->assertTrue(Dj_App_Hooks::hasFilter('app/plugin/slash_test'));

        // Same hook accessed via dot notation
        $this->assertTrue(Dj_App_Hooks::hasFilter('app.plugin.slash_test'));

        $res = Dj_App_Hooks::applyFilter('app.plugin.slash_test', 'hello');
        $this->assertEquals('hello_processed', $res);

        Dj_App_Hooks::removeFilter('app/plugin/slash_test', 'Hooks_Test::staticTestMethod');
    }

    public function testHookRegistrationWithDotNames()
    {
        // Register with . separator
        Dj_App_Hooks::addFilter('app.plugin.dot_test', 'Hooks_Test::staticTestMethod');
        $this->assertTrue(Dj_App_Hooks::hasFilter('app.plugin.dot_test'));

        // Same hook accessed via slash notation
        $this->assertTrue(Dj_App_Hooks::hasFilter('app/plugin/dot_test'));

        $res = Dj_App_Hooks::applyFilter('app/plugin/dot_test', 'world');
        $this->assertEquals('world_processed', $res);

        Dj_App_Hooks::removeFilter('app.plugin.dot_test', 'Hooks_Test::staticTestMethod');
    }

    public function testFormatHookNameMessyInput()
    {
        // Messy slashes
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('/ // / app / plugin / test ///'));

        // Leading/trailing dots
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('...app.plugin.test...'));

        // Double dots
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('app..plugin..test'));

        // Mixed dots and slashes junk
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('...///app...plugin///test...///'));

        // Spaces + slashes + dots mixed
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('  / . app . / plugin / . test .  /  '));

        // Tabs and newlines with slashes
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName("\t/\tapp\t/\tplugin\t/\ttest\t/\t"));

        // Just dots and slashes = empty
        $this->assertEquals('',
            Dj_App_Hooks::formatHookName('...///...'));

        // Realistic messy newsletter hook
        $this->assertEquals('app/plugin/simple_newsletter/data',
            Dj_App_Hooks::formatHookName('  /app.plugin.simple_newsletter.data/  '));

        // Colons mixed with dots and slashes
        $this->assertEquals('app/plugin/test',
            Dj_App_Hooks::formatHookName('::.app::plugin..:test.::'));

        // Digits preserved with separators
        $this->assertEquals('123/app/plugin/test/456',
            Dj_App_Hooks::formatHookName('123/app.plugin.test/456'));

        // Dashes between segments become underscores
        $this->assertEquals('app/plugin/my_cool_plugin/action',
            Dj_App_Hooks::formatHookName('app.plugin.my-cool-plugin.action'));
    }

    public function testFormatHookNameDigitsPreserved()
    {
        // Digits at edges are preserved
        $this->assertEquals('123/app/plugin/test', Dj_App_Hooks::formatHookName('123.app.plugin.test'));
        $this->assertEquals('app/plugin/test/456', Dj_App_Hooks::formatHookName('app.plugin.test.456'));
        $this->assertEquals('app/v2/api', Dj_App_Hooks::formatHookName('app.v2.api'));
        $this->assertEquals('app/error/404', Dj_App_Hooks::formatHookName('app.error.404'));
        $this->assertEquals('app/plugin/test/2', Dj_App_Hooks::formatHookName('app/plugin/test/2'));

        // Pure numbers
        $this->assertEquals('123', Dj_App_Hooks::formatHookName('123'));
        $this->assertEquals('42', Dj_App_Hooks::formatHookName('  42  '));
    }

    public function testFormatHookNameColonHandling()
    {
        // Colons normalize to / and trim from edges
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName(':::app:core:hook:::'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName(':app:core:hook:'));

        // Colons mixed with dots and slashes
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app:core.hook'));
        $this->assertEquals('app/core/hook', Dj_App_Hooks::formatHookName('app:core/hook'));
    }

    public function testFormatHookNameDashHandling()
    {
        // Dashes become underscores (non-word char)
        $this->assertEquals('my_cool_plugin', Dj_App_Hooks::formatHookName('my-cool-plugin'));
        $this->assertEquals('app/plugin/my_theme/setup', Dj_App_Hooks::formatHookName('app.plugin.my-theme.setup'));

        // Leading/trailing dashes trimmed
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('---my_hook---'));

        // Multiple dashes collapse
        $this->assertEquals('my_hook', Dj_App_Hooks::formatHookName('my---hook'));
    }

    public function testFormatHookNameSpecialCharCombinations()
    {
        // @ # $ % ^ & * all become _
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app@hook'));
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app#hook'));
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app$hook'));
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app%hook'));
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app^hook'));
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app&hook'));

        // Multiple special chars collapse to single _
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('app@#$%hook'));

        // Special chars at edges get trimmed
        $this->assertEquals('app_hook', Dj_App_Hooks::formatHookName('!!!app_hook!!!'));
    }

    public function testFormatHookNameWhitespaceVariations()
    {
        // Tabs
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName("\tapp\tplugin\ttest\t"));

        // Newlines
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName("\napp\nplugin\ntest\n"));

        // Carriage returns
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName("\rapp\rplugin\rtest\r"));

        // Mixed whitespace
        $this->assertEquals('app/plugin/test', Dj_App_Hooks::formatHookName(" \t\n app \t\n plugin \t\n test \t\n "));
    }

    public function sampleAction() {

    }

    public static function staticTestMethod($value, $params = [], $hook_name = '') {
        return $value . '_processed';
    }

    public static function staticStringMethod($value, $params = [], $hook_name = '') {
        return strlen($value);
    }

    // ============================================================
    // Deferred Action Tests
    // ============================================================

    public static $deferred_call_log = [];

    protected function tearDown(): void {
        // Full reset between deferred-action tests so static state from one test
        // doesn't leak into the next. The setters default to [] = clear/reset.
        self::$deferred_call_log = [];
        Dj_App_Hooks::setDeferredActionsData();
        Dj_App_Hooks::setDeferredActions();
        Dj_App_Hooks::setActions();
    }

    /**
     * Simulates what bootstrap (index.php) does in the shutdown phase: fires
     * 'app/shutdown' for any listeners, then drains the captured deferred queue.
     * The drain logic itself lives in Dj_App_Hooks::runDeferredActions() so it's
     * a single source of truth shared between bootstrap and tests.
     */
    private function simulateShutdown() {
        Dj_App_Hooks::doAction('app/shutdown');
        Dj_App_Hooks::runDeferredActions();
    }

    public static function deferredCallback($params, $hook = '') {
        self::$deferred_call_log[] = [
            'params' => $params,
            'hook' => $hook,
        ];
    }

    public static function deferredCallbackB($params, $hook = '') {
        self::$deferred_call_log[] = [
            'params' => $params,
            'hook' => $hook,
            'tag' => 'B',
        ];
    }

    public function testAddDeferredActionDoesNotRunImmediately() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.no_run_yet', $callback, 50);

        // Hook hasn't fired yet — nothing should have run
        $this->assertEmpty(self::$deferred_call_log);
    }

    public function testDeferredActionQueuesOnTriggerHook() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.trigger', $callback, 50);

        $params = [ 'msg' => 'hello', 'id' => 42, ];
        Dj_App_Hooks::doAction('app.test.deferred.trigger', $params);

        // Trigger fired but app/shutdown hasn't — callback should NOT have run yet
        $this->assertEmpty(self::$deferred_call_log);
    }

    public function testDeferredActionRunsOnShutdownHook() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.runs', $callback, 50);

        $trigger_params = [ 'msg' => 'hello', 'id' => 42, ];
        Dj_App_Hooks::doAction('app.test.deferred.runs', $trigger_params);

        // Simulate bootstrap firing app/shutdown + draining deferred queue
        $this->simulateShutdown();

        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals($trigger_params, self::$deferred_call_log[0]['params']);
    }

    public function testDeferredActionPreservesOriginalParams() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.preserves_params', $callback, 50);

        $expected_params = [
            'chat_id' => 5,
            'sender' => 'alice',
            'message' => 'hi there',
        ];
        Dj_App_Hooks::doAction('app.test.deferred.preserves_params', $expected_params);
        $this->simulateShutdown();

        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals($expected_params, self::$deferred_call_log[0]['params']);
    }

    public function testMultipleDeferredCallbacksOnSameTrigger() {
        self::$deferred_call_log = [];

        $callback_a = [ 'Hooks_Test', 'deferredCallback', ];
        $callback_b = [ 'Hooks_Test', 'deferredCallbackB', ];

        Dj_App_Hooks::addDeferredAction('app.test.deferred.multi', $callback_a, 50);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.multi', $callback_b, 50);

        $params = [ 'data' => 'test', ];
        Dj_App_Hooks::doAction('app.test.deferred.multi', $params);
        $this->simulateShutdown();

        // Both callbacks should have run with the same params
        $this->assertCount(2, self::$deferred_call_log);
        $this->assertEquals($params, self::$deferred_call_log[0]['params']);
        $this->assertEquals($params, self::$deferred_call_log[1]['params']);
    }

    public function testMultipleTriggersInSameRequest() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.trig_a', $callback, 50);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.trig_b', $callback, 50);

        $params_a = [ 'from' => 'A', ];
        $params_b = [ 'from' => 'B', ];
        Dj_App_Hooks::doAction('app.test.deferred.trig_a', $params_a);
        Dj_App_Hooks::doAction('app.test.deferred.trig_b', $params_b);
        $this->simulateShutdown();

        // Each callback should have its own captured params
        $this->assertCount(2, self::$deferred_call_log);
        $this->assertEquals('A', self::$deferred_call_log[0]['params']['from']);
        $this->assertEquals('B', self::$deferred_call_log[1]['params']['from']);
    }

    public function testTriggerHookFiredMultipleTimes() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.repeat', $callback, 50);

        $params_first = [ 'n' => 1, ];
        $params_second = [ 'n' => 2, ];
        Dj_App_Hooks::doAction('app.test.deferred.repeat', $params_first);
        Dj_App_Hooks::doAction('app.test.deferred.repeat', $params_second);
        $this->simulateShutdown();

        // Each fire should queue a separate item
        $this->assertCount(2, self::$deferred_call_log);
        $this->assertEquals(1, self::$deferred_call_log[0]['params']['n']);
        $this->assertEquals(2, self::$deferred_call_log[1]['params']['n']);
    }

    public function testDeferredActionWithoutTriggerDoesNothing() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.never_fires', $callback, 50);

        // Trigger hook never fires — only shutdown
        Dj_App_Hooks::doAction('app/shutdown');

        $this->assertEmpty(self::$deferred_call_log);
    }

    // ============================================================
    // addDeferredAction / removeDeferredAction storage tests
    // ============================================================

    public function testAddDeferredActionStoresInBothActionsAndDeferredActions() {
        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.store_both', $callback, 50);

        $actions = Dj_App_Hooks::getActions();
        $deferred = Dj_App_Hooks::getDeferredActions();

        // Stored in $actions (so doAction can detect+skip during normal pass)
        $this->assertArrayHasKey('app/test/deferred/store_both', $actions);
        $this->assertArrayHasKey(50, $actions['app/test/deferred/store_both']);

        // Stored in $deferred_actions (so doAction in DEFERRED mode reads it directly)
        $this->assertArrayHasKey('app/test/deferred/store_both', $deferred);
        $this->assertArrayHasKey(50, $deferred['app/test/deferred/store_both']);
    }

    public function testAddDeferredActionWithMultipleHooks() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        $hooks = [
            'app.test.deferred.multi_a',
            'app.test.deferred.multi_b',
            'app.test.deferred.multi_c',
        ];
        Dj_App_Hooks::addDeferredAction($hooks, $callback, 50);

        $deferred = Dj_App_Hooks::getDeferredActions();

        // All three hooks should have the deferred entry
        $this->assertArrayHasKey('app/test/deferred/multi_a', $deferred);
        $this->assertArrayHasKey('app/test/deferred/multi_b', $deferred);
        $this->assertArrayHasKey('app/test/deferred/multi_c', $deferred);

        // Fire each hook with distinct params, then drain
        Dj_App_Hooks::doAction('app.test.deferred.multi_a', [ 'h' => 'a', ]);
        Dj_App_Hooks::doAction('app.test.deferred.multi_b', [ 'h' => 'b', ]);
        Dj_App_Hooks::doAction('app.test.deferred.multi_c', [ 'h' => 'c', ]);
        $this->simulateShutdown();

        $this->assertCount(3, self::$deferred_call_log);
        $this->assertEquals('a', self::$deferred_call_log[0]['params']['h']);
        $this->assertEquals('b', self::$deferred_call_log[1]['params']['h']);
        $this->assertEquals('c', self::$deferred_call_log[2]['params']['h']);
    }

    public function testRemoveDeferredActionClearsBothRegistries() {
        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.remove_both', $callback, 50);

        // Sanity check — both stores have it
        $actions_before = Dj_App_Hooks::getActions();
        $deferred_before = Dj_App_Hooks::getDeferredActions();
        $this->assertArrayHasKey('app/test/deferred/remove_both', $actions_before);
        $this->assertArrayHasKey('app/test/deferred/remove_both', $deferred_before);

        // Remove
        Dj_App_Hooks::removeDeferredAction('app.test.deferred.remove_both', $callback, 50);

        // Both stores should be cleaned up (empty inner arrays get unset by removeAction)
        $actions_after = Dj_App_Hooks::getActions();
        $deferred_after = Dj_App_Hooks::getDeferredActions();
        $this->assertArrayNotHasKey('app/test/deferred/remove_both', $actions_after);
        $this->assertArrayNotHasKey('app/test/deferred/remove_both', $deferred_after);
    }

    public function testRemoveDeferredActionPreventsExecution() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.remove_prevents', $callback, 50);

        // Remove BEFORE the trigger fires
        Dj_App_Hooks::removeDeferredAction('app.test.deferred.remove_prevents', $callback, 50);

        // Now fire and drain — callback should NOT run
        Dj_App_Hooks::doAction('app.test.deferred.remove_prevents', [ 'msg' => 'should not run', ]);
        $this->simulateShutdown();

        $this->assertEmpty(self::$deferred_call_log);
    }

    public function testRemoveDeferredActionDoesNotAffectOtherCallbacksAtSameHook() {
        self::$deferred_call_log = [];

        $callback_a = [ 'Hooks_Test', 'deferredCallback', ];
        $callback_b = [ 'Hooks_Test', 'deferredCallbackB', ];

        Dj_App_Hooks::addDeferredAction('app.test.deferred.remove_one', $callback_a, 50);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.remove_one', $callback_b, 50);

        // Remove only callback_a
        Dj_App_Hooks::removeDeferredAction('app.test.deferred.remove_one', $callback_a, 50);

        // Fire and drain — only callback_b should run
        Dj_App_Hooks::doAction('app.test.deferred.remove_one', [ 'msg' => 'one left', ]);
        $this->simulateShutdown();

        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals('B', self::$deferred_call_log[0]['tag']);
    }

    public function testRemoveDeferredActionDoesNotAffectOtherHooks() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.iso_a', $callback, 50);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.iso_b', $callback, 50);

        // Remove ONLY from iso_a
        Dj_App_Hooks::removeDeferredAction('app.test.deferred.iso_a', $callback, 50);

        // Fire both hooks; only iso_b's deferred callback should run
        Dj_App_Hooks::doAction('app.test.deferred.iso_a', [ 'h' => 'a', ]);
        Dj_App_Hooks::doAction('app.test.deferred.iso_b', [ 'h' => 'b', ]);
        $this->simulateShutdown();

        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals('b', self::$deferred_call_log[0]['params']['h']);
    }

    public function testRemoveDeferredActionAfterCaptureStillSkipsExecution() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.remove_after_cap', $callback, 50);

        // Fire trigger — params get captured into $deferred_actions_data
        Dj_App_Hooks::doAction('app.test.deferred.remove_after_cap', [ 'msg' => 'captured', ]);

        // Now remove the deferred entry from $deferred_actions
        Dj_App_Hooks::removeDeferredAction('app.test.deferred.remove_after_cap', $callback, 50);

        // Drain — the captured (hook, params) replays via doAction(DEFERRED), but the
        // entry is gone from $deferred_actions, so the loop has nothing to run
        $this->simulateShutdown();

        $this->assertEmpty(self::$deferred_call_log);
    }

    public function testRemoveDeferredActionReturnsTrueWhenFound() {
        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.return_true', $callback, 50);

        $removed = Dj_App_Hooks::removeDeferredAction('app.test.deferred.return_true', $callback, 50);

        $this->assertTrue($removed);
    }

    public function testRemoveDeferredActionReturnsFalseWhenNotFound() {
        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        // Never registered

        $removed = Dj_App_Hooks::removeDeferredAction('app.test.deferred.never_added', $callback, 50);

        $this->assertFalse($removed);
    }

    // ============================================================
    // Mixed sync + deferred behavior tests
    // ============================================================

    public static function syncCallback($params, $hook = '') {
        self::$deferred_call_log[] = [
            'params' => $params,
            'hook' => $hook,
            'tag' => 'sync',
        ];
    }

    public function testSyncCallbacksRunInlineWhileDeferredCallbacksWaitForShutdown() {
        self::$deferred_call_log = [];

        $sync_cb = [ 'Hooks_Test', 'syncCallback', ];
        $deferred_cb = [ 'Hooks_Test', 'deferredCallback', ];

        Dj_App_Hooks::addAction('app.test.deferred.mixed', $sync_cb, 10);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.mixed', $deferred_cb, 50);

        Dj_App_Hooks::doAction('app.test.deferred.mixed', [ 'msg' => 'mixed', ]);

        // Sync ran inline, deferred has NOT yet
        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals('sync', self::$deferred_call_log[0]['tag']);

        // After shutdown, deferred runs too
        $this->simulateShutdown();

        $this->assertCount(2, self::$deferred_call_log);
        $this->assertEquals('sync', self::$deferred_call_log[0]['tag']);
        $this->assertArrayNotHasKey('tag', self::$deferred_call_log[1]);
    }

    public function testDeferredCallbacksRunInPriorityOrderDuringDrain() {
        self::$deferred_call_log = [];

        $callback_a = [ 'Hooks_Test', 'deferredCallback', ];
        $callback_b = [ 'Hooks_Test', 'deferredCallbackB', ];

        // Add B first at higher priority (20), A second at lower priority (10)
        Dj_App_Hooks::addDeferredAction('app.test.deferred.priority', $callback_b, 20);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.priority', $callback_a, 10);

        Dj_App_Hooks::doAction('app.test.deferred.priority', [ 'msg' => 'order', ]);
        $this->simulateShutdown();

        // Priority 10 (callback_a) should run before priority 20 (callback_b)
        $this->assertCount(2, self::$deferred_call_log);
        $this->assertArrayNotHasKey('tag', self::$deferred_call_log[0]); // callback_a — no tag
        $this->assertEquals('B', self::$deferred_call_log[1]['tag']);    // callback_b — tag B
    }

    public function testDeferredCallbackReceivesHookNameAsSecondArg() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.hook_arg', $callback, 50);

        Dj_App_Hooks::doAction('app.test.deferred.hook_arg', [ 'msg' => 'x', ]);
        $this->simulateShutdown();

        $this->assertCount(1, self::$deferred_call_log);
        // The 2nd arg is the hook name. simulateShutdown calls doAction with the
        // formatted hook name (since that's what's stored in $deferred_actions_data).
        $this->assertEquals('app/test/deferred/hook_arg', self::$deferred_call_log[0]['hook']);
    }

    public function testSameCallbackDeferredOnHookANormalOnHookBStaysIsolated() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'syncCallback', ];

        // Same callback: deferred on hook A, normal on hook B
        Dj_App_Hooks::addDeferredAction('app.test.deferred.iso_def', $callback, 50);
        Dj_App_Hooks::addAction('app.test.deferred.iso_norm', $callback, 50);

        // Fire hook B (normal) — callback should run inline
        Dj_App_Hooks::doAction('app.test.deferred.iso_norm', [ 'h' => 'normal', ]);
        $this->assertCount(1, self::$deferred_call_log);
        $this->assertEquals('normal', self::$deferred_call_log[0]['params']['h']);

        // Fire hook A (deferred) — callback should NOT run yet
        Dj_App_Hooks::doAction('app.test.deferred.iso_def', [ 'h' => 'deferred', ]);
        $this->assertCount(1, self::$deferred_call_log); // still just the normal one

        // After shutdown, the deferred one fires too
        $this->simulateShutdown();
        $this->assertCount(2, self::$deferred_call_log);
        $this->assertEquals('deferred', self::$deferred_call_log[1]['params']['h']);
    }

    public function testDoActionDeferredModeReadsFromDeferredActions() {
        self::$deferred_call_log = [];

        $sync_cb = [ 'Hooks_Test', 'syncCallback', ];
        $deferred_cb = [ 'Hooks_Test', 'deferredCallback', ];

        // Mixed callbacks at the same hook
        Dj_App_Hooks::addAction('app.test.deferred.read_def', $sync_cb, 10);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.read_def', $deferred_cb, 50);

        // Call doAction directly with type=DEFERRED — should run ONLY the deferred one,
        // skipping the sync callback even though both are registered for this hook.
        $opts = [ 'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED, ];
        Dj_App_Hooks::doAction('app.test.deferred.read_def', [ 'msg' => 'deferred only', ], $opts);

        $this->assertCount(1, self::$deferred_call_log);
        $this->assertArrayNotHasKey('tag', self::$deferred_call_log[0]); // deferredCallback has no tag
    }

    public function testDoActionDeferredModeDoesNotCaptureForLaterDrain() {
        self::$deferred_call_log = [];

        $callback = [ 'Hooks_Test', 'deferredCallback', ];
        Dj_App_Hooks::addDeferredAction('app.test.deferred.no_recap', $callback, 50);

        // Call doAction in DEFERRED mode directly — fires the callback once,
        // but should NOT enqueue another capture (otherwise infinite loop).
        $opts = [ 'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED, ];
        Dj_App_Hooks::doAction('app.test.deferred.no_recap', [ 'msg' => 'once', ], $opts);

        $this->assertCount(1, self::$deferred_call_log);

        // The deferred queue should still be empty (no capture happened)
        $this->assertEmpty(Dj_App_Hooks::getDeferredActionsData());

        // simulateShutdown should not re-fire the callback
        $this->simulateShutdown();
        $this->assertCount(1, self::$deferred_call_log);
    }

    public function testMultipleDeferredCallbacksAtDifferentPrioritiesSameHook() {
        self::$deferred_call_log = [];

        $cb1 = [ 'Hooks_Test', 'deferredCallback', ];
        $cb2 = [ 'Hooks_Test', 'deferredCallbackB', ];
        $cb3 = [ 'Hooks_Test', 'syncCallback', ];

        Dj_App_Hooks::addDeferredAction('app.test.deferred.prio_mix', $cb1, 50);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.prio_mix', $cb2, 30);
        Dj_App_Hooks::addDeferredAction('app.test.deferred.prio_mix', $cb3, 10);

        Dj_App_Hooks::doAction('app.test.deferred.prio_mix', [ 'fire' => 1, ]);
        $this->simulateShutdown();

        // Priority order: 10 (sync), 30 (B), 50 (deferredCallback)
        $this->assertCount(3, self::$deferred_call_log);
        $this->assertEquals('sync', self::$deferred_call_log[0]['tag']);
        $this->assertEquals('B',    self::$deferred_call_log[1]['tag']);
        $this->assertArrayNotHasKey('tag', self::$deferred_call_log[2]); // deferredCallback
    }
}