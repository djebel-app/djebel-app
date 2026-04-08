<?php

/**
 * This file has several hooks related classes.
 */

/**
 * Provides means for filters & actions.
 */
class Dj_App_Hooks {
    const HOOK_RUN = 2;
    const HOOK_PROCESSED = 4;
    const DEFAULT_PRIORITY = 20;

    const ACTION_TYPE_NORMAL = 1;   // default — skips deferred callbacks, queues their params
    const ACTION_TYPE_DEFERRED = 2; // shutdown replay — runs ONLY deferred callbacks

    private static $actions = [];
    private static $filters = [];

    /**
     * Registry of deferred actions. Full mirror of $actions:
     *   $deferred_actions[$formatted_hook][$priority][$action_id] = $callback;
     *
     * The callback is stored here too so the DEFERRED-mode pass in doAction() can
     * load callbacks directly from $deferred_actions WITHOUT consulting $actions.
     * One loop, one source per call.
     *
     * Example:
     *   $deferred_actions['app/messages/insert'][50]['MyClass::sendPush'] = [$obj, 'sendPush'];
     *
     * Per-hook (not global) so the same callback may still run synchronously on a
     * different hook. The action_id is the callback fingerprint (same hash used as
     * key in $actions); priority lives in the key path so ksort applies naturally.
     *
     * Note: only consulted by doAction(). applyFilter() is unaffected by deferral —
     * filters are synchronous because their return value is needed immediately, so
     * deferral applies to actions only.
     * @var array
     */
    private static $deferred_actions = [];

    /**
     * Captured params for deferred actions, keyed by hook:
     *   $deferred_actions_data[$hook_fmt] = [ $params1, $params2, ... ];
     *
     * One entry per doAction() fire that skipped at least one deferred callback for
     * the hook. The drain (in doAction's finally on app/shutdown) iterates this and
     * replays each (hook, params) via doAction(hook, params, type=DEFERRED) — which
     * fans out to ALL deferred callbacks for that hook with each captured params set.
     * @var array
     */
    private static $deferred_actions_data = [];

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
     * Get the name of the currently executing action, or check if specific action is running
     * @param string $hook_name Optional hook name to check
     * @return string|bool Hook name if no param, true/false if hook name provided
     */
    public static function currentAction($hook_name = '') {
        if (empty($hook_name)) {
            return self::$current_action;
        }

        $hook_name_fmt = self::formatHookName($hook_name);
        return self::$current_action === $hook_name_fmt;
    }

    /**
     * Get the name of the currently executing filter, or check if specific filter is running
     * @param string $hook_name Optional hook name to check
     * @return string|bool Hook name if no param, true/false if hook name provided
     */
    public static function currentFilter($hook_name = '') {
        if (empty($hook_name)) {
            return self::$current_filter;
        }

        $hook_name_fmt = self::formatHookName($hook_name);
        return self::$current_filter === $hook_name_fmt;
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
     * Check if a hook matches a given hook name after formatting both
     *
     * This is useful for comparing hooks in callbacks where you need to verify
     * the current hook matches an expected hook name.
     *
     * Example:
     * ```php
     * // Instead of:
     * if ($hook != Dj_App_Hooks::formatHookName('qs_app/chats/messages/action/insert')) {
     *     return;
     * }
     *
     * // Use:
     * if (!Dj_App_Hooks::isHook($hook, 'qs_app/chats/messages/action/insert')) {
     *     return;
     * }
     * ```
     *
     * @param string $hook The hook to check (e.g., the current hook passed to callback)
     * @param string $expected_hook The expected hook name to compare against
     * @return bool True if the hooks match after formatting
     */
    public static function isHook($hook, $expected_hook) {
        if (empty($hook) || empty($expected_hook)) {
            return false;
        }

        $hook_fmt = self::formatHookName($hook);
        $expected_hook_fmt = self::formatHookName($expected_hook);

        $is_match = $hook_fmt === $expected_hook_fmt;

        return $is_match;
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
     * Dj_App_Hooks::addAction('init', [ $obj, 'onInit', ]);
     *
     * // Multiple hooks with same callback
     * Dj_App_Hooks::addAction(['init', 'admin_init'], [ $obj, 'onInit', ]);
     *
     * // With priority (default: 20)
     * Dj_App_Hooks::addAction('init', [ $obj, 'onInit', ], 30);
     *
     * // Register as DEFERRED — runs after $req_obj->finishRequest() on app/shutdown
     * $opts = [ 'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED, ];
     * Dj_App_Hooks::addAction('app/messages/insert', [ $obj, 'sendPush', ], 50, $opts);
     * ```
     *
     * @param string|array $hook_name Single hook name or array of hook names
     * @param callable $callback Function to execute
     * @param int $priority Execution priority (default: 20)
     * @param array $opts Optional flags. Supported keys:
     *   - 'type' => self::ACTION_TYPE_NORMAL (default) | self::ACTION_TYPE_DEFERRED
     *     DEFERRED also records the callback in $deferred_actions so doAction()
     *     skips it during normal execution and replays it on app/shutdown.
     * @throws Exception For invalid hook names or callbacks
     */
    public static function addAction($hook_name, $callback, $priority = self::DEFAULT_PRIORITY, $opts = []) {
        $check_ctx = [];
        $check_ctx['hook_name'] = $hook_name;
        $check_ctx['callback'] = $callback;
        $check_ctx['priority'] = $priority;

        $check_res = Dj_App_Hooks::checkAllowed($check_ctx);

        if ($check_res->isError()) {
            throw new Dj_App_Exception($check_res->msg(), [ 'res' => $check_res, ]);
        }

        $type = empty($opts['type']) ? self::ACTION_TYPE_NORMAL : $opts['type'];

        // Generate the action_id (callback fingerprint) once — same callable for all hooks.
        $action_id = self::generateCallbackHash($callback);

        $hooks = (array) $hook_name;

        // PHP auto-vivifies missing intermediate keys on nested writes — single
        // opcode per assignment, no isset+init dance needed.
        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);

            self::$actions[$formatted_hook][$priority][$action_id] = $callback;

            // Mirror into $deferred_actions so doAction() in DEFERRED mode reads it directly.
            if ($type === self::ACTION_TYPE_DEFERRED) {
                self::$deferred_actions[$formatted_hook][$priority][$action_id] = $callback;
            }
        }
    }

    /**
     * Register a callback for a hook that runs DEFERRED — after the HTTP response is sent.
     * Use for slow background tasks (push notifications, email, analytics, cleanup).
     *
     * Thin wrapper over addAction() with type=DEFERRED. The callback is stored in BOTH
     * $actions (so doAction can detect+skip it during the normal pass) and $deferred_actions
     * (so doAction in DEFERRED mode can read just the deferred ones). When 'app/shutdown'
     * fires in NORMAL mode, doAction's finally drains the captured queue by replaying each
     * (hook, params) via doAction(..., type=DEFERRED).
     *
     * Bootstrap should call $req_obj->finishRequest() before firing 'app/shutdown'
     * so this background work runs after the client connection has been closed.
     *
     * Note: deferral applies to ACTIONS only. Filters are synchronous because the return
     * value is needed immediately — deferring a filter doesn't make sense.
     *
     * Usage:
     *   Dj_App_Hooks::addDeferredAction('qs_app/chats/messages/action/insert', [$this, 'sendPush'], 50);
     *
     * @param string|array $hook_name Hook name(s)
     * @param callable $callback Class method or function — NO closures
     * @param int $priority Execution priority (default: 20)
     */
    public static function addDeferredAction($hook_name, $callback, $priority = self::DEFAULT_PRIORITY) {
        $opts = [
            'type' => self::ACTION_TYPE_DEFERRED,
        ];

        self::addAction($hook_name, $callback, $priority, $opts);
    }

    /**
     * Removes a deferred action — removes both the underlying action AND the fingerprint
     * that marks it as deferred. After this call, the callback will no longer be invoked
     * on the hook (deferred or otherwise).
     *
     * @param string|array $hook_name Hook name(s)
     * @param callable $callback The callback to remove
     * @param int $priority The priority level (default: 20)
     * @return bool True if at least one fingerprint was removed
     */
    public static function removeDeferredAction($hook_name, $callback, $priority = self::DEFAULT_PRIORITY) {
        $opts = [
            'type' => self::ACTION_TYPE_DEFERRED,
        ];

        return self::removeAction($hook_name, $callback, $priority, $opts);
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

        // Static caches for hot-path arrays — allocated once per process, not per call.
        // formatHookName runs on every add/remove/doAction/applyFilter, so per-call
        // array literals add up at scale. $plural_map uses strtr (which accepts a
        // key→value array directly) to avoid array_keys/array_values per call.
        static $separator_chars = [ ' ', "\t", "\n", "\r", ':', '.', ];
        static $separator_chars_str = " \t\n\r:.";
        static $alnum_extra_chars = [ '_', '/', ];
        static $singlefy_chars = [ '_', '-', '/', ];
        static $plural_map = [
            '/apps/' => '/app/',
            '/pages/' => '/page/',
            '/themes/' => '/theme/',
            '/plugins/' => '/plugin/',
        ];

        // Cap at 100 chars but skip the substr call when not needed (the common case
        // for canonical hook names like 'app/page/content' which are well under 100).
        if (strlen($hook_name) > 100) {
            $hook_name = substr($hook_name, 0, 100);
        }

        // Normalize separators: spaces, tabs, newlines, colons, dots -> /
        if (strpbrk($hook_name, $separator_chars_str) !== false) {
            $hook_name = str_replace($separator_chars, '/', $hook_name);
        }

        // Sanitize via the shared helper — fast-paths clean input (the common case
        // for canonical hook names like 'app/page/content'), regex only on dirty.
        $hook_name = Dj_App_String_Util::sanitizeAlphaNumericExt($hook_name, $alnum_extra_chars);
        $hook_name = Dj_App_String_Util::singlefy($hook_name, $singlefy_chars);
        $hook_name = Dj_App_String_Util::trim($hook_name, '_/-');
        $hook_name = strtolower($hook_name);

        // if we have app/plugins/my_plugin/action -> app/plugin/my_plugin/action
        // Note: dots and dashes are already converted by this point
        if (strpos($hook_name, 's/') !== false) { // plural? - make it singular
            $hook_name = strtr($hook_name, $plural_map);
        }

        return $hook_name;
    }

    /**
     * Executes all registered callbacks for a given action hook
     *
     * @param string $executed_hook The hook to execute
     * @param array $params Parameters to pass to the callbacks
     * @param array $opts Optional flags. Supported keys:
     *   - 'type' => self::ACTION_TYPE_NORMAL (default) | self::ACTION_TYPE_DEFERRED
     *     NORMAL: skips deferred callbacks and captures (hook, params) into the
     *             deferred queue so they can run later.
     *     DEFERRED: runs ONLY callbacks marked as deferred for this hook (used by
     *             the inline drain when 'app/shutdown' is fired).
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function doAction($executed_hook, $params = [], $opts = []) {
        if (!is_scalar($executed_hook)) {
            throw new Dj_App_Hooks_Exception("Invalid hook name. We're expecting a scalar, something else was given.", [
                'hook_name' => $executed_hook,
                'type' => gettype($executed_hook),
            ]);
        }

        try {
            $executed_hook_fmt = self::formatHookName($executed_hook);

            // Set current action BEFORE executing
            self::$current_action = $executed_hook_fmt;

            // Mark as processed even if no callbacks exist
            self::$executed_hooks[$executed_hook_fmt] = self::HOOK_PROCESSED;

            // SOURCE: DEFERRED reads from $deferred_actions, NORMAL reads from $actions.
            // PHP COW: assigning the static to a local is a refcount bump, not a copy.
            // $source_actions starts as the whole registry, then narrows to this hook's callbacks.
            $type = empty($opts['type']) ? self::ACTION_TYPE_NORMAL : $opts['type'];
            $source_actions = $type === self::ACTION_TYPE_DEFERRED ? self::$deferred_actions : self::$actions;

            if (empty($source_actions[$executed_hook_fmt])) {
                return;
            }

            $source_actions = $source_actions[$executed_hook_fmt];
            ksort($source_actions);

            // NORMAL mode + this hook has deferred callbacks → capture (hook, params) NOW,
            // once, before the loop. We know the loop WILL skip them (they're registered),
            // so there's no need for a flag inside the loop. $deferred_for_hook caches the
            // per-hook deferred set so the loop's isset check is O(1).
            $deferred_for_hook = [];

            if ($type === self::ACTION_TYPE_NORMAL && !empty(self::$deferred_actions[$executed_hook_fmt])) {
                self::$deferred_actions_data[$executed_hook_fmt][] = $params;
                $deferred_for_hook = self::$deferred_actions[$executed_hook_fmt];
            }

            // ONE loop. is_callable() is NOT checked here — addAction() validates via
            // checkAllowed() at registration time. Skipping that check on the hot path
            // matters when the framework powers 10M+ sites.
            foreach ($source_actions as $priority => $callbacks_at_priority) {
                foreach ($callbacks_at_priority as $action_id => $callback) {
                    // Skip deferred ones — they were captured above and will fan out
                    // via the DEFERRED replay on app/shutdown. Early continue to avoid
                    // a nested if around the execute.
                    if (isset($deferred_for_hook[$priority][$action_id])) {
                        continue;
                    }

                    call_user_func_array($callback, array(
                        $params,
                        $executed_hook, // comes as 2nd param -> $event
                    ));

                    self::$executed_hooks[$executed_hook_fmt] = self::HOOK_RUN;
                }
            }
        } finally {
            self::$current_action = '';
        }
    }

    /**
     * Just an alias if somebody is using this in plural
     * @param $executed_hook
     * @param $cur_val
     * @param $params
     * @return mixed|null
     * @throws Dj_App_Hooks_Exception
     */
    public static function applyFilters( $executed_hook, $cur_val = null, $params = [] ) {
        trigger_error("Please use Dj_App_Hooks::applyFilter instead of " . __METHOD__, E_USER_WARNING);
        return Dj_App_Hooks::applyFilter( $executed_hook, $cur_val, $params );
    }

    /**
     * This method is supposed to work but test it jic.
     * Dj_App_Hooks::applyFilter();
     * @throws Dj_App_Hooks_Exception
     */
    public static function applyFilter($executed_hook, $cur_val = null, $params = []) {
        if (!is_scalar($executed_hook)) {
            throw new Dj_App_Hooks_Exception("Invalid filter name. We're expecting a scalar, something else was given.", [
                'hook_name' => $executed_hook,
                'type' => gettype($executed_hook),
            ]);
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
     * @param int $priority Execution priority (default: 20)
     * @throws Dj_App_Exception For invalid hook names or callbacks
     */
    public static function addFilter($hook_name, $callback, $priority = self::DEFAULT_PRIORITY) {
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

            // Generate the action_id (callback fingerprint) for this callback
            $action_id = self::generateCallbackHash($callback);

            // Store callback under its action_id (matches $actions / $deferred_actions shape)
            self::$filters[$formatted_hook][$priority][$action_id] = $callback;
        }
    }

    public static function getActions(): array
    {
        return self::$actions;
    }

    public static function setActions($actions = [])
    {
        self::$actions = $actions;
    }

    public static function getFilters(): array
    {
        return self::$filters;
    }

    public static function setFilters($filters = [])
    {
        self::$filters = $filters;
    }

    public static function getDeferredActions()
    {
        return self::$deferred_actions;
    }

    public static function setDeferredActions($deferred_actions = [])
    {
        self::$deferred_actions = $deferred_actions;
    }

    public static function getDeferredActionsData()
    {
        return self::$deferred_actions_data;
    }

    public static function setDeferredActionsData($deferred_actions_data = [])
    {
        self::$deferred_actions_data = $deferred_actions_data;
    }

    /**
     * The single entry point for the shutdown phase. Registered as a PHP shutdown
     * function in the bootstrap so it runs on EVERY exit path: normal completion,
     * early return (headless mode), exit(), exceptions, even fatal errors.
     *
     * Closes the client connection FIRST (via $req_obj->finishRequest()) so any
     * slow deferred work runs in the background after the user has been disconnected.
     * Safe to call even when finishRequest already ran from the bootstrap finally —
     * its !headers_sent() / buffer-level guards make repeat calls effective no-ops.
     *
     * Idempotent via state clearing — calling this twice in a row is safe:
     *   1. First call: flush+close, fires 'app/shutdown' listeners, clears them, drains queue
     *   2. Second call: no listeners → no-op, queue empty → no-op
     *
     * No flag needed because the work itself drains the state.
     */
    public static function runShutdownHooks()
    {
        // Flush response + close connection BEFORE any deferred work runs, so the
        // user's browser disconnects immediately. Only meaningful for real HTTP
        // requests — skip in CLI (PHPUnit, scripts) where there's no connection
        // and finishRequest's ob_end_flush would close buffers it didn't open.
        // Dj_App_Env::isWebRequest() returns true only when !isCli AND we have
        // REQUEST_METHOD + REQUEST_URI in $_SERVER. class_exists guards against
        // very-early shutdown calls before env.php / request.php have been loaded.
        $is_web_req = class_exists('Dj_App_Env', false) && Dj_App_Env::isWebRequest();

        if ($is_web_req && class_exists('Dj_App_Request', false)) {
            $req_obj = Dj_App_Request::getInstance();
            $req_obj->finishRequest();
        }

        self::doAction('app/shutdown');

        // Drain the listeners so a second runShutdownHooks() call is a no-op.
        unset(self::$actions['app/shutdown']);

        self::runDeferredActions();
    }

    /**
     * Drains the captured deferred-actions queue. Each captured (hook, params)
     * entry is replayed via doAction(..., type=DEFERRED), which iterates
     * $deferred_actions[hook] and runs ALL the deferred callbacks for that hook
     * in priority order with the originally-captured params.
     *
     * Called by runShutdownHooks() in the shutdown phase, AFTER
     * $req_obj->finishRequest() has flushed the response and closed the
     * connection — so the deferred work runs in the background.
     *
     * Loop prevention: doAction() with type=DEFERRED reads $deferred_actions
     * (not $actions), so the inline skip-and-capture branch never re-fires.
     */
    public static function runDeferredActions()
    {
        $pending_data = self::$deferred_actions_data;
        self::$deferred_actions_data = [];

        if (empty($pending_data)) {
            return;
        }

        $drain_opts = [
            'type' => self::ACTION_TYPE_DEFERRED,
        ];

        foreach ($pending_data as $hook => $param_sets) {
            foreach ($param_sets as $params) {
                try {
                    self::doAction($hook, $params, $drain_opts);
                } catch (\Exception $e) {
                    // Don't let one failure block the rest of the pending data.
                }
            }
        }
    }

    /**
     * Removes an action hook.
     *
     * @param string|array $hook_name The hook name(s) to remove
     * @param callable $callback The callback to remove
     * @param int $priority The priority level to remove (optional)
     * @param array $opts Optional flags. Supported keys:
     *   - 'type' => self::ACTION_TYPE_NORMAL (default) | self::ACTION_TYPE_DEFERRED
     *     DEFERRED also clears the matching $deferred_actions entry, so a single
     *     pass through the hooks list handles both stores (no duplicate formatHookName).
     * @return bool True if removed, false if not found
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function removeAction($hook_name, $callback, $priority = self::DEFAULT_PRIORITY, $opts = []) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid hook name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $type = empty($opts['type']) ? self::ACTION_TYPE_NORMAL : $opts['type'];
        $remove_deferred = ($type === self::ACTION_TYPE_DEFERRED);

        $hooks = (array) $hook_name;
        $removed = false;

        // Generate the action_id (callback fingerprint) once.
        $action_id = self::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);

            // Remove from the regular $actions store.
            if (isset(self::$actions[$formatted_hook][$priority][$action_id])) {
                unset(self::$actions[$formatted_hook][$priority][$action_id]);
                $removed = true;

                if (empty(self::$actions[$formatted_hook][$priority])) {
                    unset(self::$actions[$formatted_hook][$priority]);

                    if (empty(self::$actions[$formatted_hook])) {
                        unset(self::$actions[$formatted_hook]);
                    }
                }
            }

            // Also clear the deferred entry in the same pass when removing a deferred action.
            // Mirrors the [hook][priority][action_id] cleanup pattern used for $actions above.
            if ($remove_deferred && isset(self::$deferred_actions[$formatted_hook][$priority][$action_id])) {
                unset(self::$deferred_actions[$formatted_hook][$priority][$action_id]);

                if (empty(self::$deferred_actions[$formatted_hook][$priority])) {
                    unset(self::$deferred_actions[$formatted_hook][$priority]);

                    if (empty(self::$deferred_actions[$formatted_hook])) {
                        unset(self::$deferred_actions[$formatted_hook]);
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
     * @throws Dj_App_Hooks_Exception For invalid filter names
     */
    public static function removeFilter($hook_name, $callback, $priority = self::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array)$hook_name;
        $removed = false;

        // Generate the action_id (callback fingerprint) once for the callback we want to remove
        $action_id = self::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = self::formatHookName($hook);

            if (!isset(self::$filters[$formatted_hook][$priority])) {
                continue;
            }

            // Remove the specific callback if it exists for this hook
            if (isset(self::$filters[$formatted_hook][$priority][$action_id])) {
                unset(self::$filters[$formatted_hook][$priority][$action_id]);
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

/**
 * Exception class for Hooks-related errors
 */
class Dj_App_Hooks_Exception extends Dj_App_Exception {}
