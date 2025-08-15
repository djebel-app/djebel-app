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

    public function sampleAction()
    {

    }

    public static function staticTestMethod($value, $params = [], $hook_name = '') {
        return $value . '_processed';
    }

    public static function staticStringMethod($value, $params = [], $hook_name = '') {
        return strlen($value);
    }
}