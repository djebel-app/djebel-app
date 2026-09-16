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
     * Registry names that are wildcard patterns (name => name). Flat, not keyed by type: every
     * doAction() / applyFilter() checks them, and a nested read measured ~30 ns slower.
     * @var array
     */
    private static $action_patterns = [];
    private static $filter_patterns = [];

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
     * Parked (disabled) hook entries. Same 3-level shape as the active registries:
     *   $disabled_filters[$formatted_hook][$priority][$action_id] = $callback;
     *
     * disableFilter()/disableAction() MOVE entries here instead of flagging them —
     * the fire path never sees disabled entries, so skipping them costs ZERO per
     * fire (a flag checked inside the hot loop would tax every invocation).
     * enableFilter()/enableAction() move them back. A fully parked hook hits the
     * existing empty() quick-return in applyFilter()/doAction() for free.
     * @var array
     */
    private static $disabled_filters = [];
    private static $disabled_actions = [];

    /**
     * Parked entries of the $deferred_actions mirror. Kept in sync by
     * disableAction()/enableAction() so a disabled deferred action neither runs
     * nor queues params while parked.
     * @var array
     */
    private static $disabled_deferred_actions = [];

    /**
     * Pending notices queued via addNotice(). Drained ONCE per request by
     * flushNotices() during runShutdownHooks() — after the response has already
     * gone out, so emitting costs the user nothing.
     * Each entry: [ 'message' => string, 'ctx' => array, ].
     * @var array
     */
    private static $notices = [];

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
            return Dj_App_Hooks::$current_action;
        }

        $hook_name_fmt = Dj_App_Hooks::formatHookName($hook_name);
        $is_current_action = Dj_App_Hooks::$current_action === $hook_name_fmt;

        return $is_current_action;
    }

    /**
     * Get the name of the currently executing filter, or check if specific filter is running
     * @param string $hook_name Optional hook name to check
     * @return string|bool Hook name if no param, true/false if hook name provided
     */
    public static function currentFilter($hook_name = '') {
        if (empty($hook_name)) {
            return Dj_App_Hooks::$current_filter;
        }

        $hook_name_fmt = Dj_App_Hooks::formatHookName($hook_name);
        $is_current_filter = Dj_App_Hooks::$current_filter === $hook_name_fmt;

        return $is_current_filter;
    }

    /**
     * Check if a hook has been run.
     *
     * @param string $hook_name The hook name
     * @return bool True if the action/filter has been run or processed
     */
    public static function hasRun($hook_name) {
        $executed_hook_fmt = Dj_App_Hooks::formatHookName( $hook_name );
        $has_run = !empty(Dj_App_Hooks::$executed_hooks[$executed_hook_fmt]);

        return $has_run;
    }

    /**
     * Get all executed hooks.
     * @return array
     */
    public static function getExecutedHooks() {
        $exeecuted_hooks = [];

        foreach (Dj_App_Hooks::$executed_hooks as $hook => $status) {
            if ($status == Dj_App_Hooks::HOOK_RUN) {
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
        $formatted_hook = Dj_App_Hooks::formatHookName($hook_name);
        $has_action = !empty(Dj_App_Hooks::$actions[$formatted_hook]);

        return $has_action;
    }

    /**
     * Check if a filter has been registered.
     * 
     * @param string $hook_name The hook name
     * @return bool True if the filter has been registered
     */
    public static function hasFilter($hook_name) {
        $formatted_hook = Dj_App_Hooks::formatHookName($hook_name);
        $has_filter = !empty(Dj_App_Hooks::$filters[$formatted_hook]);

        return $has_filter;
    }

    /**
     * Check if a hook (action or filter) has been registered.
     *
     * @param string $hook_name The hook name
     * @return bool True if the hook has been registered (as action or filter)
     */
    public static function hasHook($hook_name) {
        // Kept as two steps rather than one `||`: the filter registry is only consulted
        // when the action registry has already missed.
        $has_action = Dj_App_Hooks::hasAction($hook_name);

        if ($has_action) {
            return true;
        }

        $has_filter = Dj_App_Hooks::hasFilter($hook_name);

        return $has_filter;
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

        $hook_fmt = Dj_App_Hooks::formatHookName($hook);
        $expected_hook_fmt = Dj_App_Hooks::formatHookName($expected_hook);

        $is_match = $hook_fmt === $expected_hook_fmt;

        return $is_match;
    }

    /**
     * Capture the output of a hook.
     */
    public static function captureHookOutput( $hook_name, $params = [] ) {
        ob_start();
        Dj_App_Hooks::doAction( $hook_name, $params );
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
                if (array_key_exists($callback, Dj_App_Hooks::$allowed_predefined_quick_returns)) {
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
     * // Register as DEFERRED — runs after the response has gone out, on app/shutdown
     * $opts = [ 'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED, ];
     * Dj_App_Hooks::addAction('app/messages/insert', [ $obj, 'sendPush', ], 50, $opts);
     *
     * // Wildcard: '*' = any characters inside one segment, '**' = any number of whole segments.
     * // Dots, because a star next to a slash would close this docblock.
     * Dj_App_Hooks::addAction('app.plugin.*.action.message_processed', [ $obj, 'onAnyMessage', ]);
     * ```
     *
     * @param string|array $hook_name Single hook name, wildcard pattern, or array of either
     * @param callable $callback Function to execute
     * @param int $priority Execution priority (default: 20)
     * @param array $opts Optional flags. Supported keys:
     *   - 'type' => Dj_App_Hooks::ACTION_TYPE_NORMAL (default) | Dj_App_Hooks::ACTION_TYPE_DEFERRED
     *     DEFERRED also records the callback in $deferred_actions so doAction()
     *     skips it during normal execution and replays it on app/shutdown.
     * @return void Registering cannot fail once the input passes; invalid input throws.
     * @throws Exception For invalid hook names or callbacks
     */
    public static function addAction($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY, $opts = []) {
        $check_ctx = [];
        $check_ctx['hook_name'] = $hook_name;
        $check_ctx['callback'] = $callback;
        $check_ctx['priority'] = $priority;

        $check_res = Dj_App_Hooks::checkAllowed($check_ctx);

        if ($check_res->isError()) {
            throw new Dj_App_Exception($check_res->msg(), [ 'res' => $check_res, ]);
        }

        $type = empty($opts['type']) ? Dj_App_Hooks::ACTION_TYPE_NORMAL : $opts['type'];

        // Generate the action_id (callback fingerprint) once — same callable for all hooks.
        $action_id = Dj_App_Hooks::generateCallbackHash($callback);

        $hooks = (array) $hook_name;

        // PHP auto-vivifies missing intermediate keys on nested writes — single
        // opcode per assignment, no isset+init dance needed.
        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (str_contains($formatted_hook, '*')) {
                // The deferred replay runs listeners by exact name only, so a deferred pattern would never run.
                if ($type === Dj_App_Hooks::ACTION_TYPE_DEFERRED) {
                    $exc_data = [
                        'code' => 'app.core.hooks.pattern.deferred_not_supported',
                        'pattern' => $formatted_hook,
                    ];

                    throw new Dj_App_Hooks_Exception('A wildcard hook pattern cannot be a deferred action', $exc_data);
                }

                Dj_App_Hooks::$action_patterns[$formatted_hook] = $formatted_hook;
            }

            // SORTED INVARIANT: priorities stay sorted at registration so doAction()
            // never sorts on the fire path. A new priority key appends at the end of
            // the array — re-sort only when it lands out of order. array_key_last()
            // is O(1); adds at an existing priority or in increasing order skip this.
            if (!isset(Dj_App_Hooks::$actions[$formatted_hook][$priority]) && !empty(Dj_App_Hooks::$actions[$formatted_hook]) && $priority < array_key_last(Dj_App_Hooks::$actions[$formatted_hook])) {
                Dj_App_Hooks::$actions[$formatted_hook][$priority] = [];
                ksort(Dj_App_Hooks::$actions[$formatted_hook]);
            }

            Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id] = $callback;

            // Mirror into $deferred_actions so doAction() in DEFERRED mode reads it directly.
            if ($type === Dj_App_Hooks::ACTION_TYPE_DEFERRED) {
                // Same sorted invariant for the mirror — the DEFERRED replay iterates it.
                if (!isset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority]) && !empty(Dj_App_Hooks::$deferred_actions[$formatted_hook]) && $priority < array_key_last(Dj_App_Hooks::$deferred_actions[$formatted_hook])) {
                    Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority] = [];
                    ksort(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
                }

                Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id] = $callback;
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
     * runShutdownHooks() flushes the response before firing 'app/shutdown', so this
     * background work runs once the client already has the page. Registering deferred
     * work is itself what makes that flush worth doing.
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
    public static function addDeferredAction($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        $opts = [
            'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED,
        ];

        Dj_App_Hooks::addAction($hook_name, $callback, $priority, $opts);
    }

    /**
     * Register a callback on the 'app/shutdown' action — the shutdown phase fires once the
     * response has been sent to the client, so this is the home for fire-and-forget
     * background work that must not block the request: file cleanup, GC, flushing writes.
     *
     * Runs unconditionally at the end with no triggering doAction(), unlike
     * addDeferredAction() (which defers a specific hook's per-firing handling). No closures.
     *
     * Usage:
     *   Dj_App_Hooks::addShutdownAction([$this, 'processDelFiles'], 50);
     *
     * @param callable $callback Class method or function — NO closures
     * @param int $priority Execution priority (default: 20)
     */
    public static function addShutdownAction($callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        Dj_App_Hooks::addAction('app/shutdown', $callback, $priority);
    }

    /**
     * Remove a callback registered via addShutdownAction(). A shutdown action is a plain
     * 'app/shutdown' action (no deferred mirror), so removeAction('app/shutdown', ...) works
     * too — this is just the symmetric, intention-revealing form.
     *
     * @param callable $callback The callback to remove
     * @param int $priority The priority level (default: 20)
     * @return bool True if a callback was removed
     */
    public static function removeShutdownAction($callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        $removed = Dj_App_Hooks::removeAction('app/shutdown', $callback, $priority);

        return $removed;
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
    public static function removeDeferredAction($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        $opts = [
            'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED,
        ];

        $removed = Dj_App_Hooks::removeAction($hook_name, $callback, $priority, $opts);

        return $removed;
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

        // MEMO CACHE: raw input → formatted result. This runs on EVERY add/remove/
        // doAction/applyFilter and the same canonical names repeat constantly, so
        // after the first call the whole function collapses to one isset + return.
        // The raw string itself is the key — PHP's hashtable hashes string keys in
        // C, so manual (partial) hashing would only ADD work and risk collisions.
        // Variant spellings ('App.Page.Content' vs 'app/page/content') each take a
        // slot but resolve to the SAME formatted name — correctness is unaffected;
        // the registries are keyed by the formatted name only.
        static $formatted_cache = [];

        if (isset($formatted_cache[$hook_name])) {
            return $formatted_cache[$hook_name];
        }

        $raw_hook_name = $hook_name;

        // Static caches for hot-path arrays — allocated once per process, not per call.
        // formatHookName runs on every add/remove/doAction/applyFilter, so per-call
        // array literals add up at scale. $plural_map uses strtr (which accepts a
        // key→value array directly) to avoid array_keys/array_values per call.
        static $separator_chars = [ ' ', "\t", "\n", "\r", ':', '.', ];
        static $separator_chars_str = " \t\n\r:.";
        static $alnum_extra_chars = [ '_', '/', '*', ];
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

        // A '*' makes it a wildcard pattern, validated once per spelling so a bad one fails where it
        // is registered. Rejected: no segment without '*' (*/*), '**' inside a segment (app/a**),
        // '**' twice in a row (a/**/**/b). A preg_match() failure rejects too.
        if (str_contains($hook_name, '*')) {
            if (!preg_match('#(?:^|/)[^*/]+(?:/|$)#', $hook_name) || (preg_match('#[^/]\*\*|\*\*[^/]#', $hook_name) !== 0) || str_contains($hook_name, '**/**')) {
                $exc_data = [
                    'code' => 'app.core.hooks.pattern.invalid',
                    'pattern' => $hook_name,
                ];

                throw new Dj_App_Hooks_Exception('Invalid wildcard hook pattern: it needs a segment without *, and ** only as a whole segment, never twice in a row', $exc_data);
            }
        }

        // Growth cap, not eviction: canonical hook names are a small fixed set, so
        // 1000 distinct raw spellings means something is generating dynamic names —
        // those format normally, just uncached. count() is O(1) on PHP arrays.
        if (count($formatted_cache) < 1000) {
            $formatted_cache[$raw_hook_name] = $hook_name;
        }

        return $hook_name;
    }

    /**
     * Executes all registered callbacks for a given action hook
     *
     * @param string $executed_hook The hook to execute
     * @param array $params Parameters to pass to the callbacks
     * @param array $opts Optional flags. Supported keys:
     *   - 'type' => Dj_App_Hooks::ACTION_TYPE_NORMAL (default) | Dj_App_Hooks::ACTION_TYPE_DEFERRED
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
            // First statement in the try, so the finally below always has a value to put
            // back — a property read cannot throw, so nothing can fail ahead of it.
            // A restore and not a blank because dispatch nests: a listener that fires a
            // hook of its own would otherwise return here having erased the outer one,
            // leaving every listener still to run on it seeing no current action, which
            // is the single question currentAction() exists to answer.
            $prev_action = Dj_App_Hooks::$current_action;

            $executed_hook_fmt = Dj_App_Hooks::formatHookName($executed_hook);

            // Set current action BEFORE executing
            Dj_App_Hooks::$current_action = $executed_hook_fmt;

            // Mark as processed even if no callbacks exist
            Dj_App_Hooks::$executed_hooks[$executed_hook_fmt] = Dj_App_Hooks::HOOK_PROCESSED;

            // SOURCE: DEFERRED reads from $deferred_actions, NORMAL reads from $actions.
            // PHP COW: assigning the static to a local is a refcount bump, not a copy.
            // $source_actions starts as the whole registry, then narrows to this hook's callbacks.
            $type = empty($opts['type']) ? Dj_App_Hooks::ACTION_TYPE_NORMAL : $opts['type'];
            $source_actions = $type === Dj_App_Hooks::ACTION_TYPE_DEFERRED ? Dj_App_Hooks::$deferred_actions : Dj_App_Hooks::$actions;

            // The pattern check comes first: on a site without patterns it is the one extra check
            // a fire pays. The deferred replay runs by exact name, so patterns join normal fires only.
            if (!empty(Dj_App_Hooks::$action_patterns) && $type === Dj_App_Hooks::ACTION_TYPE_NORMAL) {
                $resolve_params = [
                    'hook_name' => $executed_hook_fmt,
                    'registry' => Dj_App_Hooks::$actions,
                    'patterns' => Dj_App_Hooks::$action_patterns,
                ];

                $source_actions = Dj_App_Hooks::resolveRunList($resolve_params);
            } elseif (empty($source_actions[$executed_hook_fmt])) {
                return;
            } else {
                // No sort here: priorities are kept sorted at registration time
                // (addAction/setActions/enableAction maintain the sorted invariant).
                // That also keeps $source_actions a cheap refcount alias — the old
                // per-fire ksort() wrote to the local and forced a full COW array copy.
                $source_actions = $source_actions[$executed_hook_fmt];
            }

            // NORMAL mode + this hook has deferred callbacks → capture (hook, params) NOW,
            // once, before the loop. We know the loop WILL skip them (they're registered),
            // so there's no need for a flag inside the loop. $deferred_for_hook caches the
            // per-hook deferred set so the loop's isset check is O(1).
            $deferred_for_hook = [];

            if ($type === Dj_App_Hooks::ACTION_TYPE_NORMAL && !empty(Dj_App_Hooks::$deferred_actions[$executed_hook_fmt])) {
                Dj_App_Hooks::$deferred_actions_data[$executed_hook_fmt][] = $params;
                $deferred_for_hook = Dj_App_Hooks::$deferred_actions[$executed_hook_fmt];
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

                    // Direct invocation: no call_user_func_array() dispatch overhead
                    // and no per-call args-array allocation. Works for all callable
                    // shapes ('Class::method', [ $obj, 'method', ], plain functions).
                    $callback($params, $executed_hook); // $executed_hook comes as 2nd param -> $event

                    Dj_App_Hooks::$executed_hooks[$executed_hook_fmt] = Dj_App_Hooks::HOOK_RUN;
                }
            }
        } finally {
            Dj_App_Hooks::$current_action = $prev_action;
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
            // First statement in the try — same reasoning as doAction(). It carries more
            // weight here: this loop returns from INSIDE the try, and a filter callback
            // reaching for any config value routinely applies another filter on the way.
            $prev_filter = Dj_App_Hooks::$current_filter;

            $executed_hook_fmt = Dj_App_Hooks::formatHookName($executed_hook);

            // Set current filter BEFORE executing
            Dj_App_Hooks::$current_filter = $executed_hook_fmt;

            // Mark as processed even if no callbacks exist
            Dj_App_Hooks::$executed_hooks[$executed_hook_fmt] = Dj_App_Hooks::HOOK_PROCESSED;

            // The pattern check comes first: on a site without patterns it is the one extra check
            // a filter call pays.
            if (!empty(Dj_App_Hooks::$filter_patterns)) {
                $resolve_params = [
                    'hook_name' => $executed_hook_fmt,
                    'registry' => Dj_App_Hooks::$filters,
                    'patterns' => Dj_App_Hooks::$filter_patterns,
                ];

                $hook_filters = Dj_App_Hooks::resolveRunList($resolve_params);
            } elseif (empty(Dj_App_Hooks::$filters[$executed_hook_fmt])) {
                // If no callbacks registered for this hook, return current value
                return $cur_val;
            } else {
                $hook_filters = Dj_App_Hooks::$filters[$executed_hook_fmt];
            }

            // Execute callbacks in priority order. is_callable() is NOT checked on
            // the hot path — checkAllowed() validates at registration time (same
            // contract as doAction). Quick-return sentinels are checked FIRST:
            // is_scalar() + isset() are C-level checks, cheaper than invoking, and
            // this matches checkAllowed()'s precedence (sentinel before callable).
            foreach ($hook_filters as $callbacks_by_priority) {
                foreach ($callbacks_by_priority as $callback) {
                    if (is_scalar($callback) && isset(Dj_App_Hooks::$allowed_predefined_quick_returns[$callback])) {
                        $cur_val = Dj_App_Hooks::$allowed_predefined_quick_returns[$callback];
                        Dj_App_Hooks::$executed_hooks[$executed_hook_fmt] = Dj_App_Hooks::HOOK_RUN;
                    } else {
                        // Direct invocation: no call_user_func_array() dispatch
                        // overhead, no per-call args-array allocation. The try is
                        // free on the non-throwing path (PHP zero-cost exceptions).
                        try {
                            $cur_val = $callback($cur_val, $params, $executed_hook); // $executed_hook comes as 3rd param -> $event
                        } catch (\Error $e) {
                            // An \Error thrown from INSIDE a valid callback is a real
                            // bug in that callback — don't mask it.
                            if (is_callable($callback)) {
                                throw $e;
                            }

                            // Non-callable entry (only reachable via setFilters()
                            // injection or corrupted state — addFilter() validates).
                            // Surface it LOUDLY but keep the chain running: $cur_val
                            // is untouched and the remaining callbacks still fire.
                            $callback_str = Dj_App_Hooks::generateCallbackHash($callback);

                            $notice_ctx = [
                                'hook_name' => $executed_hook_fmt,
                                'callback' => $callback_str,
                            ];

                            Dj_App_Hooks::addNotice("Invalid callback for filter: [{$executed_hook_fmt}] callback: [{$callback_str}]", $notice_ctx);
                            continue;
                        }

                        // Mark as actually run only after successful execution
                        Dj_App_Hooks::$executed_hooks[$executed_hook_fmt] = Dj_App_Hooks::HOOK_RUN;
                    }
                }
            }

            return $cur_val;
        } finally {
            Dj_App_Hooks::$current_filter = $prev_filter;
        }
    }

    const RETURN_ZERO = '__return_zero';
    const RETURN_TRUE = '__return_true';
    const RETURN_FALSE = '__return_false';
    const RETURN_NULL = '__return_null';
    const RETURN_EMPTY_STRING = '__return_empty_string';
    const RETURN_EMPTY_ARRAY = '__return_empty_array';

    private static $allowed_predefined_quick_returns = [
        Dj_App_Hooks::RETURN_ZERO => 0,
        Dj_App_Hooks::RETURN_TRUE => true,
        Dj_App_Hooks::RETURN_FALSE => false,
        Dj_App_Hooks::RETURN_NULL => null,
        Dj_App_Hooks::RETURN_EMPTY_STRING => '',
        Dj_App_Hooks::RETURN_EMPTY_ARRAY => [],
    ];

    /**
     * Adds a filter with organized storage by hook name and priority
     * 
     * Usage:
     * ```php
     * // Regular callback — a NAMED callable; closures are not used in this codebase
     * Dj_App_Hooks::addFilter('content', ['Djebel_Plugin_Demo', 'filterContent']);
     *
     * // One callback on several hooks
     * Dj_App_Hooks::addFilter(['title', 'content'], ['Djebel_Plugin_Demo', 'stripTags']);
     *
     * // Using predefined returns
     * Dj_App_Hooks::addFilter('show_admin', Dj_App_Hooks::RETURN_FALSE);
     *
     * // Wildcard pattern, in dot notation: a star next to a slash would close this docblock
     * Dj_App_Hooks::addFilter('app.plugin.**.filter.item', ['Djebel_Plugin_Demo', 'filterAnyItem']);
     * ```
     *
     * @param string|array $hook_name Single hook name, wildcard pattern, or array of either
     * @param callable|string $callback Function to execute or predefined return value
     * @param int $priority Execution priority (default: 20)
     * @return void Registering cannot fail once the input passes; invalid input throws.
     * @throws Dj_App_Exception For invalid hook names or callbacks
     */
    public static function addFilter($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        $check_ctx = [];
        $check_ctx['type'] = 'filter';
        $check_ctx['callback'] = $callback;
        $check_ctx['hook_name'] = $hook_name;
        $check_ctx['priority'] = $priority;

        $check_res = Dj_App_Hooks::checkAllowed($check_ctx);

        if ($check_res->isError()) {
            throw new Dj_App_Exception($check_res->msg(), [ 'res' => $check_res, ]);
        }

        $hooks = (array) $hook_name;

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (str_contains($formatted_hook, '*')) {
                Dj_App_Hooks::$filter_patterns[$formatted_hook] = $formatted_hook;
            }

            if (!isset(Dj_App_Hooks::$filters[$formatted_hook])) {
                Dj_App_Hooks::$filters[$formatted_hook] = [];
            }

            if (!isset(Dj_App_Hooks::$filters[$formatted_hook][$priority])) {
                // SORTED INVARIANT: priorities stay sorted at registration so
                // applyFilter() never sorts on the fire path. A new priority key
                // appends at the end of the array — re-sort only when it lands out
                // of order. array_key_last() is O(1); adds at an existing priority
                // or in increasing order skip the ksort entirely.
                if (!empty(Dj_App_Hooks::$filters[$formatted_hook]) && $priority < array_key_last(Dj_App_Hooks::$filters[$formatted_hook])) {
                    Dj_App_Hooks::$filters[$formatted_hook][$priority] = [];
                    ksort(Dj_App_Hooks::$filters[$formatted_hook]);
                } else {
                    Dj_App_Hooks::$filters[$formatted_hook][$priority] = [];
                }
            }

            // Generate the action_id (callback fingerprint) for this callback
            $action_id = Dj_App_Hooks::generateCallbackHash($callback);

            // Store callback under its action_id (matches $actions / $deferred_actions shape)
            Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id] = $callback;
        }
    }

    /**
     * The action registry, or with 'hook_name' the listeners that run for that name, in run order.
     * @param array $params Optional. hook_name
     * @return array
     */
    public static function getActions($params = [])
    {
        if (empty($params['hook_name'])) {
            return Dj_App_Hooks::$actions;
        }

        $hook_name_fmt = Dj_App_Hooks::formatHookName($params['hook_name']);

        $resolve_params = [
            'hook_name' => $hook_name_fmt,
            'registry' => Dj_App_Hooks::$actions,
            'patterns' => Dj_App_Hooks::$action_patterns,
        ];

        $run_list = Dj_App_Hooks::resolveRunList($resolve_params);

        return $run_list;
    }

    public static function setActions($actions = [])
    {
        // Bulk replace bypasses addAction()'s sorted-at-registration bookkeeping —
        // re-establish the sorted invariant here so the fire path keeps skipping sorts.
        // Setters run rarely (tests, state restore), so the cost is irrelevant.
        $hook_names = array_keys($actions);
        $action_patterns = [];

        foreach ($hook_names as $hook) {
            if (!is_array($actions[$hook])) {
                continue;
            }

            ksort($actions[$hook]);

            if (str_contains($hook, '*')) {
                $action_patterns[$hook] = $hook;
            }
        }

        Dj_App_Hooks::$action_patterns = $action_patterns;
        Dj_App_Hooks::$actions = $actions;
    }

    /**
     * The filter registry, or with 'hook_name' the listeners that run for that name, in run order.
     * @param array $params Optional. hook_name
     * @return array
     */
    public static function getFilters($params = [])
    {
        if (empty($params['hook_name'])) {
            return Dj_App_Hooks::$filters;
        }

        $hook_name_fmt = Dj_App_Hooks::formatHookName($params['hook_name']);

        $resolve_params = [
            'hook_name' => $hook_name_fmt,
            'registry' => Dj_App_Hooks::$filters,
            'patterns' => Dj_App_Hooks::$filter_patterns,
        ];

        $run_list = Dj_App_Hooks::resolveRunList($resolve_params);

        return $run_list;
    }

    public static function setFilters($filters = [])
    {
        // Bulk replace bypasses addFilter()'s sorted-at-registration bookkeeping —
        // re-establish the sorted invariant here so the fire path keeps skipping sorts.
        $hook_names = array_keys($filters);
        $filter_patterns = [];

        foreach ($hook_names as $hook) {
            if (!is_array($filters[$hook])) {
                continue;
            }

            ksort($filters[$hook]);

            if (str_contains($hook, '*')) {
                $filter_patterns[$hook] = $hook;
            }
        }

        Dj_App_Hooks::$filters = $filters;
        Dj_App_Hooks::$filter_patterns = $filter_patterns;
    }

    public static function getDeferredActions()
    {
        return Dj_App_Hooks::$deferred_actions;
    }

    public static function setDeferredActions($deferred_actions = [])
    {
        // Same sorted-invariant bookkeeping as setActions() — the DEFERRED replay
        // in doAction() iterates this registry without sorting.
        $hook_names = array_keys($deferred_actions);

        foreach ($hook_names as $hook) {
            if (!is_array($deferred_actions[$hook])) {
                continue;
            }

            ksort($deferred_actions[$hook]);
        }

        Dj_App_Hooks::$deferred_actions = $deferred_actions;
    }

    public static function getDeferredActionsData()
    {
        return Dj_App_Hooks::$deferred_actions_data;
    }

    public static function setDeferredActionsData($deferred_actions_data = [])
    {
        Dj_App_Hooks::$deferred_actions_data = $deferred_actions_data;
    }

    /**
     * Queues a notice for deferred, FILTERABLE emission instead of calling
     * trigger_error() inline. Costs ONE array append — safe to call from hot
     * paths' error branches. The queue is drained once per request by
     * flushNotices() during runShutdownHooks(), i.e. after the response has already
     * gone out, so emission costs the user nothing.
     *
     * Deferred (vs emitting immediately) also means plugins that load AFTER the
     * notice was raised can still filter it, and no nested hook fires from inside
     * applyFilter()'s loop.
     *
     * @param string $message The notice text passed to trigger_error() at drain time
     * @param array $ctx Optional context. Supported keys:
     *   - 'level' => E_USER_* constant for trigger_error() (default: E_USER_WARNING)
     *   - anything else (e.g. 'hook_name', 'callback') travels with the notice so
     *     'app/core/notices' filter callbacks can act on it programmatically.
     * @return bool
     */
    public static function addNotice($message, $ctx = []) {
        $ctx['time'] = Dj_App_Util::time();

        $notice = [
            'message' => $message,
            'ctx' => $ctx,
        ];

        Dj_App_Hooks::$notices[] = $notice;

        return true;
    }

    public static function getNotices()
    {
        return Dj_App_Hooks::$notices;
    }

    public static function setNotices($notices = [])
    {
        Dj_App_Hooks::$notices = $notices;
    }

    /**
     * Drains the pending notices queue: fires ONE batch 'app/core/notices' filter
     * with ALL queued notices (plugins can suppress by returning [], modify, or
     * route them elsewhere), then trigger_error()s each surviving notice at its
     * requested level.
     *
     * Called by runShutdownHooks() AFTER runDeferredActions(). Notices added
     * DURING the drain (e.g. by filter callbacks) stay queued and are NOT
     * re-filtered in the same pass — no recursion by construction.
     *
     * @return bool True if there was anything to drain
     */
    public static function flushNotices() {
        if (empty(Dj_App_Hooks::$notices)) {
            return false;
        }

        $pending_notices = Dj_App_Hooks::$notices;
        Dj_App_Hooks::$notices = [];

        $filtered_notices = Dj_App_Hooks::applyFilter('app/core/notices', $pending_notices);

        if (empty($filtered_notices) || !is_array($filtered_notices)) {
            return true;
        }

        foreach ($filtered_notices as $notice) {
            $message = empty($notice['message']) ? '' : $notice['message'];

            if (empty($message)) {
                continue;
            }

            $level = empty($notice['ctx']['level']) ? E_USER_WARNING : $notice['ctx']['level'];
            trigger_error($message, $level);
        }

        return true;
    }

    /**
     * The single entry point for the shutdown phase. Registered as a PHP shutdown
     * function in the bootstrap so it runs on EVERY exit path: normal completion,
     * early return (headless mode), exit(), exceptions, even fatal errors.
     *
     * Releases the session lock, then — only when something is actually queued to run
     * afterwards — flushes the response FIRST (via Dj_App_Util::flushResponse()) so any
     * slow deferred work happens once the user already has the page. Safe to call even
     * when the response was already flushed from the bootstrap finally — the
     * !headers_sent() / buffer-level guards make repeat calls effective no-ops.
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
        // requests — skip in CLI (PHPUnit, scripts), where there's no connection to
        // close and buffers this code didn't open would be closed.
        $is_web_req = Dj_App_Env::isWebRequest();

        if ($is_web_req) {
            // Releasing the session lock is worth doing on EVERY request — a held lock
            // serialises anything else that same visitor has in flight. It sits here rather
            // than behind the flush below, which most requests never reach. False only means
            // there was no session open, which is a normal outcome and not a failure.
            $session_closed = Dj_App_Util::closeSession();

            // Handing the client back early only pays for itself when something is queued to
            // run after they are gone. With nothing waiting there is no background work to
            // overlap with, and the cost is charged anyway: the response gives up gzip and the
            // connection gives up keep-alive, both for an idle gap that never happens.
            if (Dj_App_Hooks::hasPostResponseWork()) {
                Dj_App_Util::flushResponse();
            }
        }

        Dj_App_Hooks::doAction('app/shutdown');

        // Drain the listeners so a second runShutdownHooks() call is a no-op.
        unset(Dj_App_Hooks::$actions['app/shutdown']);

        Dj_App_Hooks::runDeferredActions();

        // Emit queued notices LAST — deferred work above may add more, and the
        // 'app/core/notices' filter should see the full batch. Idempotent: the
        // drain empties the queue, so a second call is a no-op.
        Dj_App_Hooks::flushNotices();
    }

    /**
     * Is anything queued to run AFTER the response has gone out?
     *
     * The three things runShutdownHooks() does once the client is served: 'app/shutdown'
     * listeners, the deferred-action replay, and the notice drain. When all three are empty
     * the shutdown phase has nothing to do, so disconnecting the client early buys no overlap
     * — it only spends gzip and keep-alive on an idle gap that never happens.
     *
     * A listener registering MORE work while 'app/shutdown' fires is already covered: the
     * listener itself is work, so this answered true before it ever ran.
     *
     * @return bool
     */
    public static function hasPostResponseWork()
    {
        if (!empty(Dj_App_Hooks::$actions['app/shutdown'])) {
            return true;
        }

        if (!empty(Dj_App_Hooks::$deferred_actions)) {
            return true;
        }

        if (!empty(Dj_App_Hooks::$notices)) {
            return true;
        }

        return false;
    }

    /**
     * Drains the captured deferred-actions queue. Each captured (hook, params)
     * entry is replayed via doAction(..., type=DEFERRED), which iterates
     * $deferred_actions[hook] and runs ALL the deferred callbacks for that hook
     * in priority order with the originally-captured params.
     *
     * Called by runShutdownHooks() in the shutdown phase, AFTER
     * Dj_App_Util::flushResponse() has pushed the response out — so the deferred
     * work runs once the client already has the page.
     *
     * Loop prevention: doAction() with type=DEFERRED reads $deferred_actions
     * (not $actions), so the inline skip-and-capture branch never re-fires.
     */
    public static function runDeferredActions()
    {
        $pending_data = Dj_App_Hooks::$deferred_actions_data;
        Dj_App_Hooks::$deferred_actions_data = [];

        if (empty($pending_data)) {
            return;
        }

        $drain_opts = [
            'type' => Dj_App_Hooks::ACTION_TYPE_DEFERRED,
        ];

        foreach ($pending_data as $hook => $param_sets) {
            foreach ($param_sets as $params) {
                try {
                    Dj_App_Hooks::doAction($hook, $params, $drain_opts);
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
     *   - 'type' => Dj_App_Hooks::ACTION_TYPE_NORMAL (default) | Dj_App_Hooks::ACTION_TYPE_DEFERRED
     *     DEFERRED also clears the matching $deferred_actions entry, so a single
     *     pass through the hooks list handles both stores (no duplicate formatHookName).
     * @return bool True if removed, false if not found
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function removeAction($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY, $opts = []) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid hook name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $type = empty($opts['type']) ? Dj_App_Hooks::ACTION_TYPE_NORMAL : $opts['type'];
        $remove_deferred = ($type === Dj_App_Hooks::ACTION_TYPE_DEFERRED);

        $hooks = (array) $hook_name;
        $removed = false;

        // Generate the action_id (callback fingerprint) once.
        $action_id = Dj_App_Hooks::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            // Remove from the regular $actions store.
            if (isset(Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id])) {
                unset(Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id]);
                $removed = true;

                if (empty(Dj_App_Hooks::$actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$actions[$formatted_hook]);
                    }
                }
            }

            // Also clear the deferred entry in the same pass when removing a deferred action.
            // Mirrors the [hook][priority][action_id] cleanup pattern used for $actions above.
            if ($remove_deferred && isset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id])) {
                unset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id]);

                if (empty(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$deferred_actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
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
    public static function removeFilter($hook_name, $callback, $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array) $hook_name;
        $removed = false;

        // Generate the action_id (callback fingerprint) once for the callback we want to remove
        $action_id = Dj_App_Hooks::generateCallbackHash($callback);

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (!isset(Dj_App_Hooks::$filters[$formatted_hook][$priority])) {
                continue;
            }

            // Remove the specific callback if it exists for this hook
            if (isset(Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id])) {
                unset(Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id]);
                $removed = true;

                // Clean up empty arrays for this specific hook
                if (empty(Dj_App_Hooks::$filters[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$filters[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$filters[$formatted_hook])) {
                        unset(Dj_App_Hooks::$filters[$formatted_hook]);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Temporarily disables a filter callback (or a WHOLE hook when $callback is
     * empty) WITHOUT removing it. The entry is MOVED into $disabled_filters —
     * not flagged — so the fire path pays ZERO: applyFilter() never sees parked
     * entries and a fully parked hook hits the existing empty() quick-return.
     * Reversible via enableFilter(). removeFilter() does NOT touch parked entries.
     *
     * While disabled the callback is invisible: hasFilter() returns false for a
     * fully parked hook.
     *
     * @param string|array $hook_name The hook name(s)
     * @param callable|string $callback Specific callback to park; empty = whole hook
     * @param int $priority The priority the callback was registered at
     * @return bool True if at least one entry was parked
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function disableFilter($hook_name, $callback = '', $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array) $hook_name;
        $disabled = false;

        $action_id = '';

        if (!empty($callback)) {
            $action_id = Dj_App_Hooks::generateCallbackHash($callback);
        }

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (empty(Dj_App_Hooks::$filters[$formatted_hook])) {
                continue;
            }

            // Empty callback = park the WHOLE hook. Entry-by-entry move so
            // previously parked individual callbacks of the same hook are merged,
            // never overwritten.
            if (empty($callback)) {
                foreach (Dj_App_Hooks::$filters[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                    foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                        Dj_App_Hooks::$disabled_filters[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                    }
                }

                unset(Dj_App_Hooks::$filters[$formatted_hook]);
                $disabled = true;
                continue;
            }

            if (isset(Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id]);
                $disabled = true;

                // Clean up empty levels so the fire path's empty() quick-return kicks in.
                if (empty(Dj_App_Hooks::$filters[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$filters[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$filters[$formatted_hook])) {
                        unset(Dj_App_Hooks::$filters[$formatted_hook]);
                    }
                }
            }
        }

        return $disabled;
    }

    /**
     * Re-enables a previously disabled filter callback (or a WHOLE hook when
     * $callback is empty) by moving it back from $disabled_filters into $filters.
     * Re-sorts the hook's priorities afterwards — enable is rare, so an
     * unconditional ksort() is the simplest way to re-establish the
     * sorted-at-registration invariant the fire path relies on.
     *
     * @param string|array $hook_name The hook name(s)
     * @param callable|string $callback Specific callback to restore; empty = whole hook
     * @param int $priority The priority the callback was registered at
     * @return bool True if at least one entry was restored
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function enableFilter($hook_name, $callback = '', $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid filter name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array) $hook_name;
        $enabled = false;

        $action_id = '';

        if (!empty($callback)) {
            $action_id = Dj_App_Hooks::generateCallbackHash($callback);
        }

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (empty(Dj_App_Hooks::$disabled_filters[$formatted_hook])) {
                continue;
            }

            if (empty($callback)) {
                foreach (Dj_App_Hooks::$disabled_filters[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                    foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                        Dj_App_Hooks::$filters[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                    }
                }

                unset(Dj_App_Hooks::$disabled_filters[$formatted_hook]);
                ksort(Dj_App_Hooks::$filters[$formatted_hook]);
                $enabled = true;
                continue;
            }

            if (isset(Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$filters[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority][$action_id]);
                $enabled = true;

                if (empty(Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$disabled_filters[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$disabled_filters[$formatted_hook])) {
                        unset(Dj_App_Hooks::$disabled_filters[$formatted_hook]);
                    }
                }

                ksort(Dj_App_Hooks::$filters[$formatted_hook]);
            }
        }

        return $enabled;
    }

    /**
     * Temporarily disables an action callback (or a WHOLE hook when $callback is
     * empty) WITHOUT removing it. Same park/unpark mechanics as disableFilter()
     * — zero fire-path cost. ALSO parks the matching $deferred_actions mirror
     * entry, so a disabled deferred action neither runs on the shutdown replay
     * nor queues params during normal doAction() fires.
     *
     * @param string|array $hook_name The hook name(s)
     * @param callable|string $callback Specific callback to park; empty = whole hook
     * @param int $priority The priority the callback was registered at
     * @return bool True if at least one entry was parked
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function disableAction($hook_name, $callback = '', $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid hook name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array) $hook_name;
        $disabled = false;

        $action_id = '';

        if (!empty($callback)) {
            $action_id = Dj_App_Hooks::generateCallbackHash($callback);
        }

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (empty($callback)) {
                if (!empty(Dj_App_Hooks::$actions[$formatted_hook])) {
                    foreach (Dj_App_Hooks::$actions[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                        foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                            Dj_App_Hooks::$disabled_actions[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                        }
                    }

                    unset(Dj_App_Hooks::$actions[$formatted_hook]);
                    $disabled = true;
                }

                // Park the deferred mirror too — keeps doAction()'s capture/skip view coherent.
                if (!empty(Dj_App_Hooks::$deferred_actions[$formatted_hook])) {
                    foreach (Dj_App_Hooks::$deferred_actions[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                        foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                            Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                        }
                    }

                    unset(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
                    $disabled = true;
                }

                continue;
            }

            if (isset(Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id]);
                $disabled = true;

                if (empty(Dj_App_Hooks::$actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$actions[$formatted_hook]);
                    }
                }
            }

            if (isset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id]);
                $disabled = true;

                if (empty(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$deferred_actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
                    }
                }
            }
        }

        return $disabled;
    }

    /**
     * Re-enables a previously disabled action callback (or a WHOLE hook when
     * $callback is empty), restoring the $deferred_actions mirror entry too.
     * Re-sorts the restored hooks' priorities to re-establish the
     * sorted-at-registration invariant.
     *
     * @param string|array $hook_name The hook name(s)
     * @param callable|string $callback Specific callback to restore; empty = whole hook
     * @param int $priority The priority the callback was registered at
     * @return bool True if at least one entry was restored
     * @throws Dj_App_Hooks_Exception For invalid hook names
     */
    public static function enableAction($hook_name, $callback = '', $priority = Dj_App_Hooks::DEFAULT_PRIORITY) {
        if (!is_scalar($hook_name) && !is_array($hook_name)) {
            throw new Dj_App_Hooks_Exception("Invalid hook name. We're expecting a scalar or an array, something else was given.", [
                'hook_name' => $hook_name,
                'type' => gettype($hook_name),
            ]);
        }

        $hooks = (array) $hook_name;
        $enabled = false;

        $action_id = '';

        if (!empty($callback)) {
            $action_id = Dj_App_Hooks::generateCallbackHash($callback);
        }

        foreach ($hooks as $hook) {
            $formatted_hook = Dj_App_Hooks::formatHookName($hook);

            if (empty($callback)) {
                if (!empty(Dj_App_Hooks::$disabled_actions[$formatted_hook])) {
                    foreach (Dj_App_Hooks::$disabled_actions[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                        foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                            Dj_App_Hooks::$actions[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                        }
                    }

                    unset(Dj_App_Hooks::$disabled_actions[$formatted_hook]);
                    ksort(Dj_App_Hooks::$actions[$formatted_hook]);
                    $enabled = true;
                }

                if (!empty(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook])) {
                    foreach (Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook] as $parked_priority => $callbacks_at_priority) {
                        foreach ($callbacks_at_priority as $parked_action_id => $parked_callback) {
                            Dj_App_Hooks::$deferred_actions[$formatted_hook][$parked_priority][$parked_action_id] = $parked_callback;
                        }
                    }

                    unset(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook]);
                    ksort(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
                    $enabled = true;
                }

                continue;
            }

            if (isset(Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$actions[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority][$action_id]);
                $enabled = true;

                if (empty(Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$disabled_actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$disabled_actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$disabled_actions[$formatted_hook]);
                    }
                }

                ksort(Dj_App_Hooks::$actions[$formatted_hook]);
            }

            if (isset(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority][$action_id])) {
                Dj_App_Hooks::$deferred_actions[$formatted_hook][$priority][$action_id] = Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority][$action_id];
                unset(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority][$action_id]);
                $enabled = true;

                if (empty(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority])) {
                    unset(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook][$priority]);

                    if (empty(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook])) {
                        unset(Dj_App_Hooks::$disabled_deferred_actions[$formatted_hook]);
                    }
                }

                ksort(Dj_App_Hooks::$deferred_actions[$formatted_hook]);
            }
        }

        return $enabled;
    }

    public static function setDisabledFilters($disabled_filters = [])
    {
        Dj_App_Hooks::$disabled_filters = $disabled_filters;
    }

    public static function setDisabledActions($disabled_actions = [])
    {
        Dj_App_Hooks::$disabled_actions = $disabled_actions;
    }

    public static function setDisabledDeferredActions($disabled_deferred_actions = [])
    {
        Dj_App_Hooks::$disabled_deferred_actions = $disabled_deferred_actions;
    }

    /**
     * The listeners that run for a fired name: its own plus those of every pattern it matches, in
     * one priority order, exact ones first at equal priority.
     *   qs_app.*.save   matches qs_app/vehicles/save, not qs_app/a/b/save
     *   qs_app.**.save  matches qs_app/save and qs_app/a/b/save
     *
     * @param array $params hook_name (formatted), registry, patterns
     * @return array
     * @throws Dj_App_Hooks_Exception When PCRE fails while matching
     */
    private static function resolveRunList($params) {
        static $pattern_regexes = [];

        // '**' carries its own slashes, which lets it match zero segments.
        static $wildcard_regex_map = [
            '\*\*/' => '(?:[^/]+/)*',
            '/\*\*' => '(?:/[^/]+)*',
            '\*' => '[^/]*',
        ];

        $hook_name = $params['hook_name'];
        $registry = $params['registry'];
        $run_list = empty($registry[$hook_name]) ? [] : $registry[$hook_name];
        $has_pattern_listeners = false;

        foreach ($params['patterns'] as $pattern) {
            // A removed or disabled pattern keeps its entry with no listeners left.
            if (empty($registry[$pattern])) {
                continue;
            }

            if (!isset($pattern_regexes[$pattern])) {
                $pattern_regex = preg_quote($pattern, '#');
                $pattern_regex = strtr($pattern_regex, $wildcard_regex_map);
                $pattern_regexes[$pattern] = '#^' . $pattern_regex . '$#';
            }

            $pattern_match = preg_match($pattern_regexes[$pattern], $hook_name);

            if ($pattern_match === false) {
                $exc_data = [
                    'code' => 'app.core.hooks.pattern.match_failed',
                    'hook_name' => $hook_name,
                    'pattern' => $pattern,
                ];

                throw new Dj_App_Hooks_Exception('Matching a fired hook name against a wildcard pattern failed', $exc_data);
            }

            if (empty($pattern_match)) {
                continue;
            }

            foreach ($registry[$pattern] as $priority => $callbacks_at_priority) {
                if (empty($run_list[$priority])) {
                    $run_list[$priority] = [];
                }

                // + keeps the entries already there: exact listeners stay first and none runs twice.
                $run_list[$priority] += $callbacks_at_priority;
            }

            $has_pattern_listeners = true;
        }

        // Each registry list is kept sorted; two merged may not be.
        if ($has_pattern_listeners) {
            ksort($run_list);
        }

        return $run_list;
    }

    /**
     * Generates a unique hash for a callback
     *
     * @param string|array|object $callback The callback to hash
     * @return string Unique identifier for the callback
     */
    private static function generateCallbackHash($callback) {
        // Handle predefined string returns
        if (is_string($callback)) {
            return $callback;
        }

        // Handle closure/object methods
        if (is_object($callback)) {
            $callback_hash = spl_object_hash($callback);

            return $callback_hash;
        }

        // Handle array callbacks [class/object, method]
        if (is_array($callback)) {
            if (is_object($callback[0])) {
                // Instance method: [object, 'method']
                $object_hash = spl_object_hash($callback[0]);
                $callback_hash = $object_hash . '::' . $callback[1];

                return $callback_hash;
            } else {
                // Static method: ['Class', 'method']
                $callback_hash = $callback[0] . '::' . $callback[1];

                return $callback_hash;
            }
        }

        // Handle string function names
        if (is_string($callback) && function_exists($callback)) {
            $callback_hash = 'function::' . $callback;

            return $callback_hash;
        }

        // Fallback for any other callable
        $serialized_callback = serialize($callback);
        $callback_hash = 'callback::' . $serialized_callback;

        return $callback_hash;
    }
}

/**
 * Exception class for Hooks-related errors
 */
class Dj_App_Hooks_Exception extends Dj_App_Exception {}
