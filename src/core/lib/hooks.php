<?php

/**
 * This file has several hooks related classes.
 */
class QS_App_WP5_App_Hooks_Exception extends Dj_App_Exception {}

/**
 * Provides means for filters & actions.
 */
class Dj_App_Hooks {
    const HOOK_RUN = 2;
    const HOOK_PROCESSED = 4;

    private static $actions = [];
    private static $filters = [];

    /**
     * This contains if the action has run regardless if it was processed or not
     * @var array
     */
    private static $executed_hooks = [];

    /**
     * Currently executing action name
     * @var string
     */
    private static $current_action = '';

    /**
     * Currently executing filter name
     * @var string
     */
    private static $current_filter = '';

    /**
     * Get the name of the currently executing action
     * @return string The formatted hook name, or empty string if none executing
     */
    public static function currentAction() {
        return self::$current_action;
    }

    /**
     * Get the name of the currently executing filter
     * @return string The formatted hook name, or empty string if none executing
     */
    public static function currentFilter() {
        return self::$current_filter;
    }

    /**
     * Check if a hook has been run.
     *
     * @param string $hook_name The hook name
     * @return bool True if the action/filter has been run or processed
     */
    public static function hasRun($hook_name) {
        $executed_hook_fmt = Dj_App_Hooks::formatHookName( $hook_name );
        return !empty(self::$executed_hooks[$executed_hook_fmt]);
    }

    /**
     * Get all executed hooks.
     * @return array
     */
    public static function getExecutedHooks() {
        $exeecuted_hooks = [];

        foreach (self::$executed_hooks as $hook => $status) {
            if ($status == self::HOOK_RUN) {
                $exeecuted_hooks[] = $hook;
            }
        }

        return $exeecuted_hooks;
    }

    /**
     * Check if an action has been registered.
     * 
     * @param string $hook_name The hook name
     * @return bool True if the action has been registered
     */
    public static function hasAction($hook_name) {
        $formatted_hook = self::formatHookName($hook_name);
        return !empty(self::$actions[$formatted_hook]);
    }

    /**
     * Check if a filter has been registered.
     * 
     * @param string $hook_name The hook name
     * @return bool True if the filter has been registered
     */
    public static function hasFilter($hook_name) {
        $formatted_hook = self::formatHookName($hook_name);
        return !empty(self::$filters[$formatted_hook]);
    }

    /**
     * Check if a hook (action or filter) has been registered.
     * 
     * @param string $hook_name The hook name
     * @return bool True if the hook has been registered (as action or filter)
     */
    public static function hasHook($hook_name) {
        return self::hasAction($hook_name) || self::hasFilter($hook_name);
    }

    /**
     * Capture the output of a hook.
     */
    public static function captureHookOutput( $hook_name, $params = [] ) {
        ob_start();
        self::doAction( $hook_name, $params );
        return ob_get_clean();
    }

    /**
     * Validates the hook and callbacks
     * 
     * @param array $ctx
     * @return Dj_App_Result
     */
    public static function checkAllowed($ctx)
    {
        $res_obj = new Dj_App_Result();

        try {
            $callback = empty($ctx['callback']) ? '' : $ctx['callback'];
            $hook_name = empty($ctx['hook_name']) ? '' : $ctx['hook_name'];
            $priority = empty($ctx['priority']) ? 10 : $ctx['priority'];

            if ($priority > 10000) {
                throw new Dj_App_Exception("Priority cannot exceed 10,000. Given priority: {$priority}");
            }

            if (empty($hook_name)) {
                throw new Dj_App_Exception("Empty hook name");
            } else if (!is_scalar($hook_name) && !is_array($hook_name)) {
                throw new Dj_App_Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.");
            }

            if (empty($callback)) {
                throw new Dj_App_Exception("Empty callback");
            } else if (is_scalar($callback)) {
                // Check if it's a predefined quick return first
                if (array_key_exists($callback, self::$allowed_predefined_quick_returns)) {
                    // Valid predefined return
                } else if (is_callable($callback)) {
                    // Valid callable string (like static method)
                } else {
                    throw new Dj_App_Exception("Invalid callback for filter: [$hook_name]");
                }
            } else if (is_array($callback)) {
                // Handle array callbacks like ['Class', 'method'] or [object, 'method']
                if (count($callback) != 2) {
                    throw new Dj_App_Exception("Invalid array callback format. Expected [class, method] or [object, method]: [$hook_name]");
                }
                
                if (!is_callable($callback)) {
                    throw new Dj_App_Exception("Invalid array callback for hook: [$hook_name]");
                }
            } else if (!is_callable($callback)) {
                throw new Dj_App_Exception("Invalid callback for hook: [$hook_name]");
            } else if ($callback instanceof \Closure) { // Check if it's an instance of Closure class
                // they are hard to remove, so nope.
                throw new Dj_App_Exception("Invalid callback: callbacks cannot be a closure: [$hook_name]");
            }

            $res_obj->status(1);
        } catch (\Exception $e) {
            $res_obj->msg = $e->getMessage();
        }

        return $res_obj;
    }

    /**
     * Adds an action hook with organized storage by hook name and priority
     * 
     * Usage:
     * ```php
     * // Single hook
     * Dj_App_Hooks::addAction('init', function() { echo 'init'; });
     * 
     * // Multiple hooks with same callback
     * Dj_App_Hooks::addAction(['init', 'admin_init'], function() { 
     *     echo 'both init and admin_init'; 
     * });
     * 
     * // With priority (default: 10)
     * Dj_App_Hooks::addAction('init', function() { echo 'later'; }, 20);
     * ```
     * 
     * @param string|array $hook_name Single hook name or array of hook names
     * @param callable $callback Function to execute
     * @param int $priority Execution priority (default: 10)
     * @throws Exception For invalid hook names or callbacks
     */
    public static function addAction($hook_name, $callback, $priority = 10) {
        $check_ctx = [];
        $check_ctx['hook_name'] = $hook_name;
        $check_ctx['callback'] = $callback;
        $check_ctx['priority'] = $priority;

        $check_res = Dj_App_Hooks::checkAllowed($check_ctx);

        if ($check_res->isError()) {
            throw new Dj_App_Exception($check_res->msg(), [ 'res' => $check_res, ]);
        }

        $hooks = (array)$hook_name;

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);
            
            if (!isset(self::$actions[$formatted_hook])) {
                self::$actions[$formatted_hook] = [];
            }
            
            if (!isset(self::$actions[$formatted_hook][$priority])) {
                self::$actions[$formatted_hook][$priority] = [];
            }

            // Generate a unique key for this callback
            $callback_key = self::generateCallbackHash($callback);

            // Store callback with its unique key
            self::$actions[$formatted_hook][$priority][$callback_key] = $callback;
        }
    }

    /**
     * formats the hook name.  some characters from the beginning and end of the action name.
     * app.config.content_dir -> app/config/content_dir
     * @param string $hook_name
     * @return string
     */
    public static function formatHookName($hook_name) {
        // Handle null or empty values
        if (empty($hook_name)) {
            return '';
        }

        if (!is_scalar($hook_name)) {
            throw new Dj_App_Exception("Invalid hook name. It must be a scalar", [ 'hook_name' => $hook_name ] );
        }

        $hook_name = substr($hook_name, 0, 100);

        // Strip leading and trailing junk
        $hook_name = Dj_App_String_Util::trim($hook_name, "0123456789:");

        // Normalize separators: spaces, tabs, newlines, colons, dots -> /
        $separator_chars = [' ', "\t", "\n", "\r", ':', '.'];
        $separator_chars_str = implode('', $separator_chars);

        if (strpbrk($hook_name, $separator_chars_str) !== false) {
            $hook_name = str_replace($separator_chars, '/', $hook_name);
        }

        // Convert remaining non-word chars to _
        $hook_name = preg_replace( '#[^\w/:]+#si', '_', $hook_name );

        // Collapse consecutive duplicate characters
        $hook_name = Dj_App_String_Util::singlefy($hook_name, ['_', '/']);

        $hook_name = Dj_App_String_Util::trim($hook_name, '_/-');
        $hook_name = strtolower($hook_name);

        // if we have app/plugins/my_plugin/action -> app/plugin/my_plugin/action
        // Note: dots and dashes are already converted by this point
        if (strpos($hook_name, 's/') !== false) { // plural? - make it singular
            $replace_vars = [
                '/apps/' => '/app/',
                '/pages/' => '/page/',
                '/themes/' => '/theme/',
                '/plugins/' => '/plugin/',
            ];

            $hook_name = str_replace(array_keys($replace_vars), array_values($replace_vars), $hook_name);
        }

        return $hook_name;
    }

    /**
     * Executes all registered callbacks for a given action hook
     * 
     * @param string $executed_hook The hook to execute
     * @param array $params Parameters to pass to the callbacks
     * @throws Exception For invalid hook names
     */
    public static function doAction($executed_hook, $params = []) {
        if (!is_scalar($executed_hook)) {
            throw new Exception("Invalid hook name. We're expecting a scalar, something else was given.");
        }

        try {
            $executed_hook_fmt = self::formatHookName($executed_hook);

            // Set current action BEFORE executing
            self::$current_action = $executed_hook_fmt;

            // Mark as processed even if no callbacks exist
            self::$executed_hooks[$executed_hook_fmt] = self::HOOK_PROCESSED;

            // If no callbacks registered for this hook, return early
            if (empty(self::$actions[$executed_hook_fmt])) {
                return;
            }

            // Sort priorities only when executing
            ksort(self::$actions[$executed_hook_fmt]);

            // Execute callbacks in priority order
            foreach (self::$actions[$executed_hook_fmt] as $callbacks_by_priority) {
                foreach ($callbacks_by_priority as $callback) {
                    if (is_callable($callback)) {
                        call_user_func_array($callback, array(
                            $params,
                            $executed_hook, // comes as 2nd param -> $event
                        ));

                        // Mark as actually run only after successful execution
                        self::$executed_hooks[$executed_hook_fmt] = self::HOOK_RUN;
                    }
                }
            }
        } finally {
            self::$current_action = '';
        }
    }

    public static function applyFilters( $executed_hook, $cur_val = null, $params = [] ) {
        return Dj_App_Hooks::applyFilter( $executed_hook, $cur_val, $params );
    }

    /**
     * This method is supposed to work but test it jic.
     * Dj_App_Hooks::applyFilter();
     * @throws Exception
     */
    public static function applyFilter($executed_hook, $cur_val = null, $params = []) {
        if (!is_scalar($executed_hook)) {
            throw new Exception("Invalid filter name. We're expecting a scalar, something else was given.");
        }

        try {
            $executed_hook_fmt = self::formatHookName($executed_hook);

            // Set current filter BEFORE executing
            self::$current_filter = $executed_hook_fmt;

            // Mark as processed even if no callbacks exist
            self::$executed_hooks[$executed_hook_fmt] = self::HOOK_PROCESSED;

            // If no callbacks registered for this hook, return current value
            if (empty(self::$filters[$executed_hook_fmt])) {
                return $cur_val;
            }

            // Sort priorities only when executing
            ksort(self::$filters[$executed_hook_fmt]);

            // Execute callbacks in priority order
            foreach (self::$filters[$executed_hook_fmt] as $callbacks_by_priority) {
                foreach ($callbacks_by_priority as $callback) {
                    if (is_callable($callback)) {
                        $cur_val = call_user_func_array($callback, array(
                            $cur_val,
                            $params,
                            $executed_hook, // comes as 3rd param -> $event
                        ));

                        // Mark as actually run only after successful execution
                        self::$executed_hooks[$executed_hook_fmt] = self::HOOK_RUN;
                    } elseif (is_scalar($callback) && isset(self::$allowed_predefined_quick_returns[$callback])) {
                        $cur_val = self::$allowed_predefined_quick_returns[$callback];
                        self::$executed_hooks[$executed_hook_fmt] = self::HOOK_RUN;
                    }
                }
            }

            return $cur_val;
        } finally {
            self::$current_filter = '';
        }
    }

    const RETURN_ZERO = '__return_zero';
    const RETURN_TRUE = '__return_true';
    const RETURN_FALSE = '__return_false';
    const RETURN_NULL = '__return_null';
    const RETURN_EMPTY_STRING = '__return_empty_string';
    const RETURN_EMPTY_ARRAY = '__return_empty_array';

    private static $allowed_predefined_quick_returns = [
        self::RETURN_ZERO => 0,
        self::RETURN_TRUE => true,
        self::RETURN_FALSE => false,
        self::RETURN_NULL => null,
        self::RETURN_EMPTY_STRING => '',
        self::RETURN_EMPTY_ARRAY => [],
    ];

    /**
     * Adds a filter with organized storage by hook name and priority
     * 
     * Usage:
     * ```php
     * // Regular callback
     * Dj_App_Hooks::addFilter('content', function($content) { 
     *     return $content . ' filtered'; 
     * });
     * 
     * // Multiple filters
     * Dj_App_Hooks::addFilter(['title', 'content'], function($text) { 
     *     return strip_tags($text); 
     * });
     * 
     * // Using predefined returns
     * Dj_App_Hooks::addFilter('show_admin', Dj_App_Hooks::RETURN_FALSE);
     * ```
     * 
     * @param string|array $hook_name Single hook name or array of hook names
     * @param callable|string $callback Function to execute or predefined return value
     * @param int $priority Execution priority (default: 10)
     * @throws Dj_App_Exception For invalid hook names or callbacks
     */
    public static function addFilter($hook_name, $callback, $priority = 10) {
        $check_ctx = [];
        $check_ctx['type'] = 'filter';
        $check_ctx['callback'] = $callback;
        $check_ctx['hook_name'] = $hook_name;
        $check_ctx['priority'] = $priority;

        $check_res = Dj_App_Hooks::checkAllowed($check_ctx);

        if ($check_res->isError()) {
            throw new Dj_App_Exception($check_res->msg(), [ 'res' => $check_res, ]);
        }

        $hooks = (array)$hook_name;

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);
            
            if (!isset(self::$filters[$formatted_hook])) {
                self::$filters[$formatted_hook] = [];
            }
            
            if (!isset(self::$filters[$formatted_hook][$priority])) {
                self::$filters[$formatted_hook][$priority] = [];
            }

            // Generate a unique key for this callback
            $callback_key = self::generateCallbackHash($callback);

            // Store callback with its unique key
            self::$filters[$formatted_hook][$priority][$callback_key] = $callback;
        }
    }

    public static function getActions(): array
    {
        return self::$actions;
    }

    public static function setActions(array $actions): void
    {
        self::$actions = $actions;
    }

    public static function getFilters(): array
    {
        return self::$filters;
    }

    public static function setFilters(array $filters): void
    {
        self::$filters = $filters;
    }

    /**
     * Removes an action hook.
     * 
     * @param string|array $hook_name The hook name(s) to remove
     * @param callable $callback The callback to remove
     * @param int $priority The priority level to remove (optional)
     * @return bool True if removed, false if not found
     */
    public static function removeAction($hook_name, $callback, $priority = 10) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Exception("Invalid hook name. We're expecting a scalar or an array, something else was given.");
        }

        $hooks = (array)$hook_name;
        $removed = false;

        // Generate hash once for the callback we want to remove
        $callback_key = self::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);
            
            if (!isset(self::$actions[$formatted_hook][$priority])) {
                continue;
            }

            // Remove the specific callback if it exists for this hook
            if (isset(self::$actions[$formatted_hook][$priority][$callback_key])) {
                unset(self::$actions[$formatted_hook][$priority][$callback_key]);
                $removed = true;

                // Clean up empty arrays for this specific hook
                if (empty(self::$actions[$formatted_hook][$priority])) {
                    unset(self::$actions[$formatted_hook][$priority]);

                    // Remove empty priority arrays
                    if (empty(self::$actions[$formatted_hook])) {
                        unset(self::$actions[$formatted_hook]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Removes a filter hook.
     * 
     * @param string|array $hook_name The hook name(s) to remove
     * @param callable $callback The callback to remove
     * @param int $priority The priority level to remove (optional)
     * @return bool True if removed, false if not found
     */
    public static function removeFilter($hook_name, $callback, $priority = 10) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.");
        }

        $hooks = (array)$hook_name;
        $removed = false;

        // Generate hash once for the callback we want to remove
        $callback_key = self::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);
            
            if (!isset(self::$filters[$formatted_hook][$priority])) {
                continue;
            }

            // Remove the specific callback if it exists for this hook
            if (isset(self::$filters[$formatted_hook][$priority][$callback_key])) {
                unset(self::$filters[$formatted_hook][$priority][$callback_key]);
                $removed = true;

                // Clean up empty arrays for this specific hook
                if (empty(self::$filters[$formatted_hook][$priority])) {
                    unset(self::$filters[$formatted_hook][$priority]);
                    
                    if (empty(self::$filters[$formatted_hook])) {
                        unset(self::$filters[$formatted_hook]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Generates a unique hash for a callback
     * 
     * @param callable|string $callback The callback to hash
     * @return string Unique identifier for the callback
     */
    private static function generateCallbackHash($callback) {
        // Handle predefined string returns
        if (is_string($callback)) {
            return $callback;
        }

        // Handle closure/object methods
        if (is_object($callback)) {
            return spl_object_hash($callback);
        }

        // Handle array callbacks [class/object, method]
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                // Instance method: [object, 'method']
                return spl_object_hash($callback[0]) . '::' . $callback[1];
            } else {
                // Static method: ['Class', 'method']
                return $callback[0] . '::' . $callback[1];
            }
        }

        // Handle string function names
        if (is_string($callback) && function_exists($callback)) {
            return 'function::' . $callback;
        }

        // Fallback for any other callable
        return 'callback::' . serialize($callback);
    }
}
