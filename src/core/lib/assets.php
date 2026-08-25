<?php

/**
 * One-call asset registration: a plugin or theme says WHAT it needs on the page and this
 * decides where the tag goes, what URL it points at, and whether it can be linked at all.
 *
 * Dj_App_Assets::register([ 'plugin' => 'djebel-login', 'file' => '/assets/main.js', ]);
 * Dj_App_Assets::register([ 'style'  => '.a { color: red; }', ]);
 * Dj_App_Assets::register([ 'js'     => 'var cfg = {};', ]);
 * Dj_App_Assets::register([ 'url'    => 'https://cdn.example.com/x.css', ]);
 *
 * The KEY the caller used declares both the source and the kind, so the common cases need
 * no 'kind' and no 'placement'. CSS lands in the head, JS in the footer, and every inference
 * is overridable.
 *
 * Only dj-content is served over the web. A plugin installed in the private tree therefore
 * has no URL at all, so its file is INLINED rather than linked — the author does not have
 * to know where their plugin was installed.
 */
class Dj_App_Assets {
    // Where a tag goes. PLACEMENT_BODY_START rides the seam core already injects after <body.
    const PLACEMENT_HEAD = 'head';
    const PLACEMENT_BODY_START = 'body_start';
    const PLACEMENT_FOOTER = 'footer';

    const KIND_CSS = 'css';
    const KIND_JS = 'js';
    const KIND_ICON = 'icon';

    // The kinds a tag can be built for. Anything else renders nothing.
    const SUPPORTED_KINDS = [ self::KIND_CSS => 1, self::KIND_JS => 1, self::KIND_ICON => 1, ];

    // The extension a caller wrote against the kind it declares. Worth a table rather than a
    // branch precisely because it is NOT one-to-one — an icon arrives named .ico.
    const EXT_KINDS = [
        'css' => self::KIND_CSS,
        'js' => self::KIND_JS,
        'ico' => self::KIND_ICON,
    ];

    // The kinds that render as <link>, against the rel each one carries.
    const LINK_RELS = [
        self::KIND_CSS => 'stylesheet',
        self::KIND_ICON => 'icon',
    ];

    // The kinds that can be written INTO the page. Deliberately not the inverse of the list
    // above — css is both, an icon is neither, since nothing can spell an icon as markup.
    const INLINE_KINDS = [ self::KIND_CSS => 1, self::KIND_JS => 1, ];

    // Where each kind lands when the caller names no placement. A new kind declares its own
    // default by joining this list, rather than by another branch inside resolvePlacement().
    const DEFAULT_PLACEMENTS = [
        self::KIND_CSS => self::PLACEMENT_HEAD,
        self::KIND_JS => self::PLACEMENT_FOOTER,
        self::KIND_ICON => self::PLACEMENT_HEAD,
    ];

    // Extensions a minified build is looked for. Separate from the kinds above on purpose:
    // fonts and images would join the KINDS the day they render, and nobody minifies a font.
    const SUPPORTED_MIN_EXTS = [ 'css' => 1, 'js' => 1, ];

    // The page seams this echoes on. Registered on all three with ONE listener, which reads
    // back the firing hook to learn which placement it is rendering.
    const HOOK_PAGE_HEAD = 'app.page.html.head';
    const HOOK_PAGE_BODY_START = 'app.page.html.body.start';
    const HOOK_PAGE_BODY_END = 'app.page.html.body.end';

    // The site-config section a site declares its own assets in. See loadConfiguredAssets().
    const CONFIG_SECTION = 'assets';

    // Around adding.
    const FILTER_ITEM = 'app.core.assets.filter.item';
    const ACTION_ADDED = 'app.core.assets.action.added';

    // Whether a minified build is preferred over the file a caller named.
    const FILTER_USE_MIN = 'app.core.assets.filter.use_min';

    // Around rendering.
    const FILTER_QUEUE = 'app.core.assets.filter.queue';
    const FILTER_TAG_HTML = 'app.core.assets.filter.tag_html';
    const FILTER_HTML = 'app.core.assets.filter.html';

    const SOURCE_FILE = 'file';
    const SOURCE_URL = 'url';
    const SOURCE_STYLE = 'style';
    const SOURCE_JS = 'js';
    const SOURCE_CONTENT = 'content';

    // Each source, in precedence order, against the field names it is read from. A new source
    // joins here and nowhere else, and the list costs nothing at runtime — it is compiled.
    const SOURCE_FIELDS = [
        self::SOURCE_FILE => 'file',
        self::SOURCE_URL => 'url',
        self::SOURCE_STYLE => 'style',
        self::SOURCE_JS => 'js|script',
        self::SOURCE_CONTENT => 'content|buffer|data',
    ];

    // Enough of a value to see a leading <script / <style and no more.
    const SNIFF_CHUNK_SIZE = 32;

    // id => item, in registration order. Re-adding a known id overwrites the entry WHERE IT
    // ALREADY SITS, which is what makes an explicit id an override handle rather than a move.
    private $queue = [];

    // Flipped the first time an asset asks to be ordered. While it is false the queue renders
    // in the order it was registered — no bucketing, no sort, nothing to compare. Assets are
    // registered far more often than they are ordered, so the common page pays nothing.
    private $has_priority = false;

    // Same idea for prerequisites: while nothing declares one there is no graph to walk, so
    // the reordering pass is never entered.
    private $has_prereq = false;

    // What the environment and the site config say about minified builds — the value the filter
    // is then handed. Null until asked, a bool after; null rather than false, because a site
    // that answered NO must not read as "not asked yet" and send the env scan around again.
    private $use_min_default = null;

    /**
     * Singleton pattern i.e. we have only one instance of this obj
     * @staticvar static $instance
     * @return static
     */
    public static function getInstance() {
        static $instance = null;

        // This will make the calling class to be instantiated.
        // no need each sub class to define this method.
        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }

    /**
     * @return void
     */
    public function installHooks()
    {
        $page_hooks = [
            Dj_App_Assets::HOOK_PAGE_HEAD,
            Dj_App_Assets::HOOK_PAGE_BODY_START,
            Dj_App_Assets::HOOK_PAGE_BODY_END,
        ];

        Dj_App_Hooks::addAction($page_hooks, [$this, 'renderAssets']);

        $this->loadConfiguredAssets();
    }

    /**
     * The assets a SITE declares in its own config, registered before the first plugin runs so
     * a shared library reaches the page without one line of PHP asking for it. Adding one is
     * then a config line, not an edit to whichever screen happened to need it first.
     *
     * The section key IS the asset id; every key under it is a param add() already understands,
     * so this grows by whatever add() grows by and needs nothing here.
     *
     * Registering FIRST is what keeps these overridable rather than final: re-registering an id
     * replaces that entry in place, so a later registration of the same id wins and everything
     * that named it as a prerequisite follows the replacement.
     *
     * @return int How many registered
     */
    public function loadConfiguredAssets()
    {
        $opt_obj = Dj_App_Options::getInstance();
        $section_obj = $opt_obj->getSection(Dj_App_Assets::CONFIG_SECTION);
        $entries = $section_obj->toArray();

        if (empty($entries)) {
            return 0;
        }

        $loaded_cnt = 0;

        foreach ($entries as $asset_id => $params) {
            // A bare "key = value" in the section names no asset — only a dotted "<id>.<param>"
            // nests into the params array add() takes. Skipping rather than failing leaves the
            // section usable for a plain setting later without breaking every site that has one.
            if (!is_array($params)) {
                continue;
            }

            $params['id'] = $asset_id;

            $res_obj = $this->add($params);

            // One bad line must not cost a site the rest of its assets — a mistyped file name is
            // the likely case and it is the only one that should go missing. The log carries the
            // id, since a config asset has no call site to be found by.
            if ($res_obj->isError()) {
                $error_msg = $res_obj->msg();

                $log_data = [
                    'asset_id' => $asset_id,
                    'msg' => $error_msg,
                ];

                Dj_App_Log::warn($log_data, __METHOD__);

                continue;
            }

            $loaded_cnt++;
        }

        return $loaded_cnt;
    }

    /**
     * Static entry point, so a plugin registers in ONE statement instead of resolving the
     * instance first. Delegates to add().
     * @param array $params See add()
     * @return Dj_App_Result
     */
    public static function register($params = [])
    {
        $assets_obj = Dj_App_Assets::getInstance();
        $res_obj = $assets_obj->add($params);

        return $res_obj;
    }

    /**
     * Static entry point over remove().
     * @param string|array $id An asset id, or the same params that added it
     * @return Dj_App_Result
     */
    public static function deregister($id = '')
    {
        $assets_obj = Dj_App_Assets::getInstance();
        $res_obj = $assets_obj->remove($id);

        return $res_obj;
    }

    /**
     * Queues one asset and returns a Result carrying its resolved 'id' — the handle
     * remove() and replace() take, so a caller never has to invent one.
     *
     * @param array $params Exactly ONE source key:
     *   - file: relative to the 'plugin' / 'theme' context (absolute with neither)
     *   - url: an explicit external URL, left untouched and never versioned
     *   - style: inline CSS
     *   - js / script: inline JS
     *   - content / buffer / data: inline, kind sniffed from a leading <script / <style
     *   Plus, all optional:
     *   - plugin / theme: the slug 'file' is relative to
     *   - kind: KIND_CSS / KIND_JS / KIND_ICON, overriding what the key implied. A .ico is
     *     recognised on its own; name the kind for an icon shipped as .png or .svg
     *   - placement: PLACEMENT_HEAD / PLACEMENT_BODY_START / PLACEMENT_FOOTER
     *   - in_head / head / in_footer / footer: shorthand for the two common placements —
     *     'in_footer' => 1. An explicit placement outranks them; asking for both throws.
     *   - priority: lower renders earlier. OMIT IT and the asset renders where it was
     *     registered; pass one only to move against assets you do not control. An asset that
     *     names none is ordered as Dj_App_Hooks::DEFAULT_PRIORITY once anything else does.
     *   - prereq / prereqs / deps: id(s) this asset must render after — one name or a list,
     *     as a string or an array. A hard constraint: it moves the asset regardless of what
     *     priority arranged. A name that was never registered is treated as satisfied.
     *   - v / ver / version: the cache-busting stamp. Given one, the file is never stat'ed
     *     for a filemtime. Applies to 'file' only — an explicit url carries its own query.
     *   - skip_min: serve the named file even where a .min sibling exists. For the one asset
     *     that must not be swapped; the site keeps using builds for everything else.
     *   - attrs / attribs: extra tag attributes; a true value renders the attribute bare
     *   - id: an explicit handle. Registering it again REPLACES the entry in place.
     * @return Dj_App_Result status + id, or an error carrying a checkable code
     * @throws Dj_App_Validation_Exception When the call itself is malformed
     */
    public function add($params = [])
    {
        $res_obj = new Dj_App_Result();

        $params = Dj_App_Hooks::applyFilter('app.core.assets.filter.add_params', $params);

        $source_res = $this->resolveSource($params);
        $kind = $source_res->kind;
        $url = $source_res->url;
        $content = $source_res->content;

        if ($source_res->source_type == Dj_App_Assets::SOURCE_FILE) {
            if (empty($source_res->file_found)) {
                $res_obj->msg('Asset file not found');
                $res_obj->code('app.core.assets.file_not_found');

                return $res_obj;
            }

            // A caller that already knows the version — a build id, a release tag — hands it
            // over so the file never has to be stat'ed for one.
            $version = Dj_App_Util::getField('v|ver|version', $params);

            $delivery_args = [
                'file' => $source_res->abs_file,
                'version' => $version,
            ];

            $delivery_res = $this->resolveFileDelivery($delivery_args);

            if ($delivery_res->isError()) {
                return $delivery_res;
            }

            $url = $delivery_res->url;
            $content = $delivery_res->content;
        }

        $priority = Dj_App_Util::getField('priority', $params);
        $attrs = Dj_App_Util::getField('attrs|attribs', $params, []);

        $placement_args = [
            'params' => $params,
            'kind' => $kind,
        ];

        $placement = $this->resolvePlacement($placement_args);

        $item = [
            'id' => $source_res->id,
            'kind' => $kind,
            'placement' => $placement,
            'url' => $url,
            'content' => $content,
            'attrs' => $attrs,
            'source_hash' => $source_res->source_hash,
        ];

        // Only an asset that ASKED to be ordered carries a priority; absent means "where I was
        // registered". getField answers '' for a key that is not there and 0 for one that is,
        // so this cannot be an empty() test — priority 0 is a real answer and the earliest one.
        if ($priority !== '') {
            $item['priority'] = $priority;
            $this->has_priority = true;
        }

        $prereq_ids = $this->resolvePrereqIds($params);

        if (!empty($prereq_ids)) {
            $item['prereq'] = $prereq_ids;
            $this->has_prereq = true;
        }

        $item = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_ITEM, $item, $params);

        // An empty return is the veto seam — the ONE way a site says "never load that".
        if (empty($item) || !is_array($item)) {
            $res_obj->msg('Asset vetoed');
            $res_obj->code('app.core.assets.vetoed');

            return $res_obj;
        }

        // A listener that rebuilt the item may simply have left the handle out. That is not a
        // veto, and refusing the asset in the filter's name would be a lie — the id resolved
        // before the filter ran still names this source, so it stands back in. Length, not
        // empty(): '0' is a handle a caller may have chosen.
        if (!isset($item['id']) || strlen($item['id']) == 0) {
            $item['id'] = $source_res->id;
        }

        // Run on the FINAL item, so content arriving through the filter above is covered too.
        $content_res = $this->checkInlineContent($item);

        if ($content_res->isError()) {
            return $content_res;
        }

        $asset_id = $item['id'];
        $this->queue[$asset_id] = $item;

        $added_ctx = [ 'item' => $item, ];
        Dj_App_Hooks::doAction(Dj_App_Assets::ACTION_ADDED, $added_ctx);

        $res_obj->id = $asset_id;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Where the tag goes. An explicit placement wins; failing that the in_head / in_footer
     * shorthands; failing both the kind decides — stylesheets in the head, scripts in the
     * footer.
     *
     * Takes the params bag rather than the three fields, because the shorthands are only read
     * when no explicit placement was given — pulling them out at the call site would read them
     * on every asset to answer a question most assets never ask.
     *
     * @param array $args params, kind
     * @return string ALWAYS one of the PLACEMENT_* constants — it throws rather than answer empty
     * @throws Dj_App_Validation_Exception
     */
    public function resolvePlacement($args = [])
    {
        $params = empty($args['params']) ? [] : $args['params'];
        $kind = empty($args['kind']) ? '' : $args['kind'];

        $placement = Dj_App_Util::getField('placement', $params);

        // Shorthand flags for the two placements anyone actually names, so a caller can say
        // where without reaching for a constant. An explicit placement outranks them, and it is
        // the only way to reach PLACEMENT_BODY_START.
        if (empty($placement)) {
            $in_head = Dj_App_Util::getField('in_head|head', $params);
            $in_footer = Dj_App_Util::getField('in_footer|footer', $params);

            // Asking for both is not a preference to resolve, it is a contradiction — and
            // picking one silently is how a caller ends up debugging the wrong half of a page.
            if (!empty($in_head) && !empty($in_footer)) {
                throw new Dj_App_Validation_Exception('An asset goes in one place', [
                    'code' => 'app.core.assets.conflicting_placement',
                ]);
            }

            if (!empty($in_head)) {
                $placement = Dj_App_Assets::PLACEMENT_HEAD;
            } elseif (!empty($in_footer)) {
                $placement = Dj_App_Assets::PLACEMENT_FOOTER;
            }
        }

        if (empty($placement)) {
            // The footer is where anything unclassified belongs — the end of the document
            // cannot block rendering, so it is the safe answer for a kind with no entry.
            $placement = Dj_App_Assets::PLACEMENT_FOOTER;

            if (isset(Dj_App_Assets::DEFAULT_PLACEMENTS[$kind])) {
                $placement = Dj_App_Assets::DEFAULT_PLACEMENTS[$kind];
            }

            return $placement;
        }

        // The constants are underscored; a caller writing body-start means the same thing.
        $placement = str_replace('-', '_', $placement);
        $placement = Dj_App_String_Util::formatStringId($placement);

        $allowed_placements = [
            Dj_App_Assets::PLACEMENT_HEAD => 1,
            Dj_App_Assets::PLACEMENT_BODY_START => 1,
            Dj_App_Assets::PLACEMENT_FOOTER => 1,
        ];

        if (!isset($allowed_placements[$placement])) {
            throw new Dj_App_Validation_Exception('Unknown asset placement', [
                'code' => 'app.core.assets.unknown_placement',
                'placement' => $placement,
            ]);
        }

        return $placement;
    }

    /**
     * The ids an asset must render after, as a list. One name or many, given as a string or an
     * array — the caller writes whichever reads better and the splitter takes both.
     *
     * Each name goes through the SAME formatter an id does, so a prerequisite written 'jQuery'
     * finds an asset registered as 'jquery' instead of silently never matching.
     *
     * @param array $params See add()
     * @return array
     */
    public function resolvePrereqIds($params = [])
    {
        $prereq_ids = [];
        $inp_prereq = Dj_App_Util::getField('prereq|prereqs|deps', $params);

        if (empty($inp_prereq)) {
            return $prereq_ids;
        }

        // Always a list on the way out, whatever went in: the splitter takes a string, a
        // separated string, an array or a nested one and answers with a flat array every time.
        $prereq_tokens = Dj_App_String_Util::splitOnSeparators($inp_prereq);

        foreach ($prereq_tokens as $prereq_token) {
            // A name has to be a name. Anything else cannot match an id and is not worth
            // failing a page over.
            if (!is_scalar($prereq_token)) {
                continue;
            }

            $prereq_id = Dj_App_String_Util::formatStringId($prereq_token);

            // strlen, not empty(): a formatted '0' is a real name, and an asset may well be
            // registered under it — dropping it here would lose the ordering constraint with
            // nothing said about why.
            if (strlen($prereq_id) == 0) {
                continue;
            }

            $prereq_ids[] = $prereq_id;
        }

        return $prereq_ids;
    }

    /**
     * Drops an asset from the queue. Not-queued is a no-op SUCCESS, so removing a maybe-present
     * asset never needs a check first.
     *
     * @param string|array $id An asset id, or the same params array that added it
     * @return Dj_App_Result
     */
    public function remove($id = '')
    {
        $res_obj = new Dj_App_Result();
        $res_obj->status(true);

        // An empty ARRAY genuinely names nothing, and resolving one would throw for no source.
        // A scalar is length-tested instead: '0' is a handle a caller may have registered, and
        // empty() would quietly report it removed while leaving it in the queue.
        if (is_array($id)) {
            if (empty($id)) {
                return $res_obj;
            }
        } elseif (strlen($id) == 0) {
            return $res_obj;
        }

        $source_hash = '';

        // Smart param: the params that added the asset name it just as well as its id does.
        if (is_array($id)) {
            $source_res = $this->resolveSource($id);
            $asset_id = $source_res->id;
            $source_hash = $source_res->source_hash;
        } else {
            $asset_id = Dj_App_String_Util::formatStringId($id);
        }

        if (isset($this->queue[$asset_id])) {
            unset($this->queue[$asset_id]);

            return $res_obj;
        }

        // A CUSTOM id at add time means the handle re-derived above cannot match it, so the
        // resolved source is what finds the item — otherwise a custom id would quietly make an
        // asset unremovable by the very params that created it.
        if (empty($source_hash)) {
            return $res_obj;
        }

        foreach ($this->queue as $queue_id => $item) {
            if ($item['source_hash'] != $source_hash) {
                continue;
            }

            unset($this->queue[$queue_id]);
            break;
        }

        return $res_obj;
    }

    /**
     * Swaps what a queued handle renders, keeping its position relative to its neighbours.
     * @param string $id The handle to swap
     * @param array $params The new definition. See add()
     * @return Dj_App_Result
     */
    public function replace($id, $params = [])
    {
        $asset_id = Dj_App_String_Util::formatStringId($id);

        // strlen, not empty(): '0' is a handle a caller may legitimately have registered.
        if (strlen($asset_id) == 0 || !isset($this->queue[$asset_id])) {
            $res_obj = new Dj_App_Result();
            $res_obj->msg('Asset is not queued');
            $res_obj->code('app.core.assets.not_queued');

            return $res_obj;
        }

        // Re-adding under the SAME key overwrites the entry where it already sits — PHP keeps
        // an existing key's position — so the swap never moves the asset.
        $params['id'] = $asset_id;
        $add_res = $this->add($params);

        return $add_res;
    }

    /**
     * Clears the queue. Also the test-reset seam, so a suite needs no back door.
     * @return Dj_App_Result
     */
    public function removeAll()
    {
        $this->queue = [];
        $this->has_priority = false;
        $this->has_prereq = false;

        // The memoized env/config answer goes too. A suite drives that half through real env
        // vars, so one carried over from an earlier test would decide the next one instead.
        $this->use_min_default = null;

        $res_obj = new Dj_App_Result();
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * @return array id => item, in registration order
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * Does this content already ship its own <script> / <style>? Such content is emitted
     * verbatim and owns its own markup; everything else gets wrapped, which is what makes the
     * closing-tag check below meaningful.
     *
     * @param string $content
     * @return bool
     */
    public function isWrappedContent($content)
    {
        if (empty($content)) {
            return false;
        }

        // One byte settles almost everything: a bare JS or CSS blob opens with a letter, a
        // brace, a dot or a comment — never with a tag. Those return here having allocated
        // nothing and called nothing.
        $first_char = $content[0];

        if ($first_char != '<' && !ctype_space($first_char)) {
            return false;
        }

        // Bounded before any search: stripos() below answers a question about the START of the
        // content, and on a large inlined file it would otherwise scan all of it to say no.
        $lead_chunk = substr($content, 0, Dj_App_Assets::SNIFF_CHUNK_SIZE);

        // Only content that did NOT already open with the tag pays for the trim — leading
        // whitespace ahead of a tag is ordinary in a template or a heredoc.
        if ($first_char != '<') {
            $lead_chunk = ltrim($lead_chunk);

            if (empty($lead_chunk) || $lead_chunk[0] != '<') {
                return false;
            }
        }

        $is_wrapped = (stripos($lead_chunk, '<script') === 0) || (stripos($lead_chunk, '<style') === 0);

        return $is_wrapped;
    }

    /**
     * Refuses inline content that carries the closing tag of the wrapper it is about to go
     * into. A literal </script inside a <script> block ENDS the block wherever it appears —
     * the browser reads whatever follows as markup — so a plugin interpolating an unescaped
     * value lands anything from a broken page to an injected tag. The conforming form inside
     * a script is <\/script, so this can only ever fire on content that is already wrong.
     *
     * A typo'd asset must not take the page down, so this is an error Result rather than a
     * throw: the asset does not render and the caller has a code to check.
     *
     * @param array $item A resolved queue entry
     * @return Dj_App_Result
     */
    public function checkInlineContent($item = [])
    {
        $res_obj = new Dj_App_Result();
        $res_obj->status(true);

        $content = empty($item['content']) ? '' : $item['content'];

        if (empty($content)) {
            return $res_obj;
        }

        $kind = empty($item['kind']) ? Dj_App_Assets::KIND_JS : $item['kind'];
        $closing_tag = '</script';

        if ($kind == Dj_App_Assets::KIND_CSS) {
            $closing_tag = '</style';
        }

        // Cheapest check and the one that clears almost everything, so it leads: content
        // that never mentions the closing tag cannot be carrying it, wrapped or not.
        if (stripos($content, $closing_tag) === false) {
            return $res_obj;
        }

        // Content that brings its own tag is left alone — its closing tag is its own.
        if ($this->isWrappedContent($content)) {
            return $res_obj;
        }

        $res_obj->status(false);
        $res_obj->msg('Inline asset content carries its own closing tag');
        $res_obj->code('app.core.assets.unsafe_content');

        return $res_obj;
    }

    /**
     * Reads the caller's context into ONE resolved source record — kind, where the bytes
     * come from, the hash two plugins dedupe on, and the id. Shared by add() and remove()
     * so a handle is derived exactly once, in one place.
     *
     * @param array $params See add()
     * @return Dj_App_Result kind, source_type, source_hash, id, url, content, abs_file, file_found
     * @throws Dj_App_Validation_Exception When the call itself is malformed
     */
    public function resolveSource($params = [])
    {
        $res_obj = new Dj_App_Result();

        $type_res = $this->resolveSourceType($params);
        $source_type = $type_res->source_type;
        $source_val = $type_res->source_val;

        $url = '';
        $content = '';
        $abs_file = '';
        $file_found = false;
        $source_key_val = $source_val;

        // Validated BEFORE the kind is worked out, and here rather than at render time: the
        // kind of a javascript:/data: url is beside the point, and failing at the call site
        // names the line that wrote it.
        if ($source_type == Dj_App_Assets::SOURCE_URL) {
            $url_esc = Dj_App_HTML::escUrl($source_val);

            if (empty($url_esc)) {
                throw new Dj_App_Validation_Exception('Unusable asset url', [
                    'code' => 'app.core.assets.invalid_url',
                    'url' => $source_val,
                ]);
            }
        }

        $inp_kind = Dj_App_Util::getField('kind', $params);

        $kind_args = [
            'kind' => $inp_kind,
            'source_type' => $source_type,
            'source_val' => $source_val,
        ];

        $kind = $this->resolveKind($kind_args);

        if ($source_type == Dj_App_Assets::SOURCE_URL) {
            $url = $source_val;
        } elseif ($source_type == Dj_App_Assets::SOURCE_FILE) {
            $file_args = [
                'params' => $params,
                'file' => $source_val,
            ];

            $file_res = $this->resolveFile($file_args);
            $abs_file = $file_res->abs_file;
            $file_found = $file_res->file_found;

            // Hashed on the resolved FILE, not on the bytes it delivers, so the handle is the
            // same whether the file ends up linked or inlined — and the same file registered
            // by two plugins collapses to one entry.
            $source_key_val = $abs_file;
        } else {
            $content = $source_val;
        }

        $hash_input = $kind . '|' . $source_key_val;
        $source_hash = Dj_App_Util::generateHash($hash_input);
        $inp_asset_id = Dj_App_Util::getField('id', $params);
        $asset_id = $source_hash;

        // strlen, not empty(): getField answers '' for a key that is not there and '0' for one
        // that is, and '0' formats to a perfectly usable handle. An empty() test here threw the
        // caller's id away and queued the asset under a hash they never saw.
        if (strlen($inp_asset_id) > 0) {
            $asset_id = Dj_App_String_Util::formatStringId($inp_asset_id);

            // Reported as the caller wrote it, not as the formatter left it — the formatter
            // returned nothing, so it has nothing to name the offending input with. strlen
            // again: the formatter passes '0' straight through, and it is a usable handle.
            if (strlen($asset_id) == 0) {
                throw new Dj_App_Validation_Exception('Unusable asset id', [
                    'code' => 'app.core.assets.invalid_id',
                    'id' => $inp_asset_id,
                ]);
            }
        }

        $res_obj->kind = $kind;
        $res_obj->source_type = $source_type;
        $res_obj->source_hash = $source_hash;
        $res_obj->id = $asset_id;
        $res_obj->url = $url;
        $res_obj->content = $content;
        $res_obj->abs_file = $abs_file;
        $res_obj->file_found = $file_found;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * Which ONE of the five source keys the caller used, and the value it carried.
     *
     * Exactly one may be given. Which would win otherwise becomes folklore nobody can recall at
     * the call site, so two is a hard error rather than a precedence rule. That is also why all
     * five are read even once the first has hit — a conflict is only visible by looking at all
     * of them.
     *
     * @param array $params See add()
     * @return Dj_App_Result source_type, source_val
     * @throws Dj_App_Validation_Exception When none is given, or more than one
     */
    public function resolveSourceType($params = [])
    {
        $res_obj = new Dj_App_Result();

        $source_type = '';
        $source_val = '';
        $given_source_keys = [];

        foreach (Dj_App_Assets::SOURCE_FIELDS as $source_key => $field_names) {
            $val = Dj_App_Util::getField($field_names, $params);

            if (!is_scalar($val) || strlen($val) == 0) {
                continue;
            }

            $given_source_keys[] = $source_key;

            if (empty($source_type)) {
                $source_type = $source_key;

                // Cast at the one boundary a source enters through. PHP coerces an int for
                // substr() and stripos() but NOT for offset access, which the render path uses.
                $source_val = (string) $val;
            }
        }

        if (empty($source_type)) {
            throw new Dj_App_Validation_Exception('An asset needs a source', [
                'code' => 'app.core.assets.no_source',
            ]);
        }

        if (count($given_source_keys) > 1) {
            throw new Dj_App_Validation_Exception('An asset takes exactly one source', [
                'code' => 'app.core.assets.conflicting_source',
                'source_keys' => $given_source_keys,
            ]);
        }

        $res_obj->source_type = $source_type;
        $res_obj->source_val = $source_val;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * CSS or JS — from an explicit 'kind', else from the key the caller used, else from the
     * file/url extension, else sniffed off a leading <script / <style. Un-sniffable inline
     * content is treated as JS, which is what an inline blob almost always is; pass 'kind'
     * when it is not.
     *
     * @param array $args kind, source_type, source_val
     * @return string ALWAYS KIND_CSS or KIND_JS — it throws rather than answer empty, so no
     *   caller has to test what it got back
     * @throws Dj_App_Validation_Exception
     */
    public function resolveKind($args = [])
    {
        $kind = empty($args['kind']) ? '' : $args['kind'];
        $source_type = empty($args['source_type']) ? '' : $args['source_type'];
        $source_val = empty($args['source_val']) ? '' : $args['source_val'];

        if (!empty($kind)) {
            $kind = Dj_App_String_Util::formatStringId($kind);

            if (!isset(Dj_App_Assets::SUPPORTED_KINDS[$kind])) {
                throw new Dj_App_Validation_Exception('Unknown asset kind', [
                    'code' => 'app.core.assets.unknown_kind',
                    'kind' => $kind,
                ]);
            }

            return $kind;
        }

        if ($source_type == Dj_App_Assets::SOURCE_STYLE) {
            return Dj_App_Assets::KIND_CSS;
        }

        if ($source_type == Dj_App_Assets::SOURCE_JS) {
            return Dj_App_Assets::KIND_JS;
        }

        if ($source_type == Dj_App_Assets::SOURCE_CONTENT) {
            $lead_chunk = substr($source_val, 0, Dj_App_Assets::SNIFF_CHUNK_SIZE);
            $lead_chunk = Dj_App_String_Util::trim($lead_chunk);
            $kind = Dj_App_Assets::KIND_JS;

            if (stripos($lead_chunk, '<style') === 0) {
                $kind = Dj_App_Assets::KIND_CSS;
            }

            return $kind;
        }

        // A url carries a query string and a fragment that are no part of its extension.
        $ext_source = $source_val;

        if ($source_type == Dj_App_Assets::SOURCE_URL) {
            $url_file = parse_url($source_val, PHP_URL_PATH);
            $ext_source = empty($url_file) ? '' : $url_file;
        }

        $ext = Dj_App_File_Util::getExt($ext_source);

        if (isset(Dj_App_Assets::EXT_KINDS[$ext])) {
            return Dj_App_Assets::EXT_KINDS[$ext];
        }

        throw new Dj_App_Validation_Exception('Cannot tell the asset kind from the extension', [
            'code' => 'app.core.assets.unknown_kind',
            'ext' => $ext,
        ]);
    }

    /**
     * Turns a plugin/theme-relative file into the absolute one on disk. A plugin is looked up
     * across every dir plugins load from, first hit wins; with no plugin/theme context the
     * file is taken as already absolute. When nothing exists the FIRST candidate still comes
     * back, so a handle can be derived for an asset that is missing.
     *
     * @param array $args params, file
     * @return Dj_App_Result abs_file, file_found
     * @throws Dj_App_Validation_Exception
     */
    public function resolveFile($args = [])
    {
        $res_obj = new Dj_App_Result();
        $params = empty($args['params']) ? [] : $args['params'];
        $rel_file = empty($args['file']) ? '' : $args['file'];

        $rel_file = Dj_App_File_Util::normalizePath($rel_file);

        if (strpos($rel_file, '..') !== false) {
            throw new Dj_App_Validation_Exception('Asset file may not traverse directories', [
                'code' => 'app.core.assets.invalid_file',
                'file' => $rel_file,
            ]);
        }

        $rel_file = Dj_App_Util::addSlash($rel_file, Dj_App_Util::FLAG_LEADING);

        $plugin = Dj_App_Util::getField('plugin', $params);
        $theme = Dj_App_Util::getField('theme', $params);
        $candidate_files = [];
        $allowed_dirs = [];

        if (!empty($plugin)) {
            // The SAME formatter getContentUrl() runs the slug through, so the dir a file is
            // found in and the URL it is served from can never disagree on the spelling.
            $slug = Dj_App_String_Util::formatStringId($plugin);

            $plugin_dirs = [];
            $plugin_dirs[] = Dj_App_Plugins::getPluginsDir();
            $plugin_dirs[] = Dj_App_Plugins::getNonPublicPluginsDir();
            $plugin_dirs[] = Dj_App_Plugins::getSysPluginsDir();
            $plugin_dirs[] = Dj_App_Plugins::getSharedPluginsDir();

            foreach ($plugin_dirs as $plugin_dir) {
                if (empty($plugin_dir)) {
                    continue;
                }

                $allowed_dirs[] = $plugin_dir;
                $candidate_files[] = $plugin_dir . '/' . $slug . $rel_file;
            }
        } elseif (!empty($theme)) {
            $slug = Dj_App_String_Util::formatStringId($theme);
            $themes_dir = Dj_App_Util::getContentDir() . '/themes';
            $allowed_dirs[] = $themes_dir;
            $candidate_files[] = $themes_dir . '/' . $slug . $rel_file;
        } else {
            // No plugin and no theme names an owner, so the file is the site's own and is
            // rooted at the content dir. Without a root the leading slash added above left
            // the caller's string an ABSOLUTE path, and any readable file on the box resolved.
            $content_dir = Dj_App_Util::getContentDir();
            $allowed_dirs[] = $content_dir;
            $candidate_files[] = $content_dir . $rel_file;
        }

        $abs_file = empty($candidate_files) ? '' : $candidate_files[0];
        $file_found = false;

        foreach ($candidate_files as $candidate_file) {
            if (!file_exists($candidate_file)) {
                continue;
            }

            $abs_file = $candidate_file;
            $file_found = true;
            break;
        }

        // Only a file that exists can leak, and only a found one is ever delivered.
        if ($file_found) {
            // Asked before anything is derived for it, so a site not taking builds stops here
            // having done nothing at all.
            if ($this->checkUseMinified($params)) {
                $min_file = $this->resolveMinFile($abs_file);

                // One stat, on a file already found. is_file rather than file_exists: a
                // DIRECTORY by that name would otherwise replace a good file with something
                // that can be neither read nor served.
                if (!empty($min_file) && is_file($min_file)) {
                    $abs_file = $min_file;
                }
            }

            $containment_args = [
                'file' => $abs_file,
                'allowed_dirs' => $allowed_dirs,
            ];

            $containment_res = $this->checkFileWithinDirs($containment_args);

            if ($containment_res->isError()) {
                $containment_code = $containment_res->code();

                $err_data = [
                    'code' => $containment_code,
                    'file' => $abs_file,
                ];

                throw new Dj_App_Validation_Exception('Asset file resolves outside the allowed dirs', $err_data);
            }
        }

        $res_obj->abs_file = $abs_file;
        $res_obj->file_found = $file_found;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * The name a minified build of this file would carry — `.min` ahead of the extension — or
     * empty when there is no build worth looking for. Everything it needs is in the name.
     *
     * @param string $file
     * @return string Empty when no build applies
     */
    public function resolveMinFile($file)
    {
        // strrpos answers FALSE with no dot at all, and false compares below 4 — so this one
        // test refuses that, a dotfile, and a name too short to carry the marker. Without it
        // the offset below counts back from the END of the string instead.
        $dot_pos = strrpos($file, '.');

        if ($dot_pos < 4) {
            return '';
        }

        $ext = substr($file, $dot_pos + 1);
        $ext = strtolower($ext);

        if (!isset(Dj_App_Assets::SUPPORTED_MIN_EXTS[$ext])) {
            return '';
        }

        // Read where the marker would SIT, so a directory named .min cannot pass a source file
        // off as a build. Case-insensitive: the name came from a caller, not from disk.
        $min_marker = substr($file, $dot_pos - 4, 4);

        if (strcasecmp($min_marker, '.min') == 0) {
            return '';
        }

        $min_file = substr_replace($file, '.min', $dot_pos, 0);

        return $min_file;
    }

    /**
     * Whether a minified build should be preferred over the file a caller named.
     *
     * The environment sets the default: a dev box serves what was asked for, so what runs is
     * what you are editing and a stale build cannot quietly shadow a source change. Everywhere
     * else — staging included, which is what isLive() answers — prefers the build.
     *
     * `app.core.assets.use_min` overrides that, and the filter gets the last word.
     *
     * Only the environment and config half is remembered. Neither can change between two assets
     * in one request, and the env scan behind isLive() costs several times over what everything
     * else here does put together. The FILTER still runs on every call, so it stays a live seam
     * — one registered after the first asset resolved is honored just the same, and a site free
     * to answer differently per call keeps that freedom.
     *
     * An asset opts itself out with `skip_min` and leaves the rest of the site alone.
     *
     * @param array $params See add() — read for 'skip_min'
     * @return bool
     */
    public function checkUseMinified($params = [])
    {
        // The asset's own answer leads: it is a plain array read, and it settles the question
        // without reaching the hook dispatch below.
        $skip_min = Dj_App_Util::getField('skip_min', $params);

        if (!empty($skip_min)) {
            return false;
        }

        if (is_null($this->use_min_default)) {
            $use_min = Dj_App_Env::isLive();
            $use_min = Dj_App_Config::cfg('app.core.assets.use_min', $use_min);
            $this->use_min_default = Dj_App_Util::isEnabled($use_min);
        }

        $use_min = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_USE_MIN, $this->use_min_default);
        $use_min = !empty($use_min);

        return $use_min;
    }

    /**
     * Refuses a file that does not sit inside one of the dirs that were allowed to produce it.
     * A dir a file was never rooted at cannot vouch for it, so an empty allow list is a refusal
     * and not a pass.
     *
     * Symlinks are the reason the resolved half exists: a link inside an allowed dir spells
     * itself like a local file while pointing anywhere on the box, so a prefix test on the
     * spelling alone answers the wrong question.
     *
     * @param array $args file, allowed_dirs
     * @return Dj_App_Result
     */
    public function checkFileWithinDirs($args = [])
    {
        $res_obj = new Dj_App_Result();
        $abs_file = empty($args['file']) ? '' : $args['file'];
        $allowed_dirs = empty($args['allowed_dirs']) ? [] : $args['allowed_dirs'];

        if (empty($abs_file) || empty($allowed_dirs)) {
            $res_obj->msg('Asset file has no allowed dir to sit in');
            $res_obj->code('app.core.assets.no_allowed_dirs');

            return $res_obj;
        }

        $spelled_within = false;

        // Cheapest pass first: string compares only, no filesystem call at all. A path aimed
        // somewhere else entirely is refused here and never reaches a syscall.
        foreach ($allowed_dirs as $allowed_dir) {
            if (empty($allowed_dir)) {
                continue;
            }

            // Trailing separator on the prefix so '/x/dj-content-old' cannot pass a prefix
            // test against '/x/dj-content'.
            $allowed_prefix = $allowed_dir . '/';

            if (strpos($abs_file, $allowed_prefix) === 0) {
                $spelled_within = true;
                break;
            }
        }

        if (!$spelled_within) {
            $res_obj->msg('Asset file is outside the allowed dirs');
            $res_obj->code('app.core.assets.file_outside_allowed_dirs');

            return $res_obj;
        }

        $real_file = realpath($abs_file);

        if (empty($real_file)) {
            $res_obj->msg('Asset file does not resolve');
            $res_obj->code('app.core.assets.file_does_not_resolve');

            return $res_obj;
        }

        // Unchanged by resolving means no link and no '..' anywhere along it, so the string
        // pass above already judged the real location and the dirs need no resolving of their
        // own. This is the ordinary case, and it costs one syscall rather than one per dir.
        if ($real_file == $abs_file) {
            $res_obj->status(true);

            return $res_obj;
        }

        // Something along the way resolved elsewhere, so the dirs are resolved too and the
        // test is redone against where the file ACTUALLY lives. Resolving BOTH sides is also
        // what keeps a symlinked release dir working instead of failing every asset.
        foreach ($allowed_dirs as $allowed_dir) {
            if (empty($allowed_dir)) {
                continue;
            }

            $real_allowed_dir = realpath($allowed_dir);

            if (empty($real_allowed_dir)) {
                continue;
            }

            $real_allowed_prefix = $real_allowed_dir . '/';

            if (strpos($real_file, $real_allowed_prefix) === 0) {
                $res_obj->status(true);

                return $res_obj;
            }
        }

        $res_obj->msg('Asset file resolves outside the allowed dirs');
        $res_obj->code('app.core.assets.file_outside_allowed_dirs');

        return $res_obj;
    }

    /**
     * Picks how a file reaches the browser: a URL when the file sits inside the web-served
     * content dir, the file's own bytes inlined when it does not. Cache busting rides the
     * URL half as ?v=<filemtime>.
     *
     * @param array $args file, version — version optional; filemtime answers when it is absent
     * @return Dj_App_Result url, content
     */
    public function resolveFileDelivery($args = [])
    {
        $res_obj = new Dj_App_Result();
        $res_obj->url = '';
        $res_obj->content = '';

        $abs_file = empty($args['file']) ? '' : $args['file'];
        $version = empty($args['version']) ? '' : $args['version'];

        $content_dir = Dj_App_Util::getContentDir();
        $content_dir_prefix = $content_dir . '/';
        $is_web_reachable = !empty($content_dir) && (strpos($abs_file, $content_dir_prefix) === 0);

        if ($is_web_reachable) {
            $rel_url_file = substr($abs_file, strlen($content_dir));
            $content_url = Dj_App_Util::getContentDirUrl();
            $url = $content_url . $rel_url_file;

            // Only stat when nobody told us. A stat per asset per request is the kind of cost
            // that is invisible until a page carries a dozen of them.
            if (empty($version)) {
                $version = filemtime($abs_file);
            }

            $url = Dj_App_Request::addQueryParam('v', $version, $url);

            // The url is BUILT here, but not out of thin air: the host half comes from the
            // request and the tail from a filename on disk, so it is put through the same
            // escaper a caller-supplied url faces. Failing here beats emitting a broken tag.
            $url_esc = Dj_App_HTML::escUrl($url);

            if (empty($url_esc)) {
                $res_obj->msg('Asset url is unusable');
                $res_obj->code('app.core.assets.invalid_url');

                return $res_obj;
            }

            $res_obj->url = $url;
            $res_obj->status(true);

            return $res_obj;
        }

        $read_res = Dj_App_File_Util::read($abs_file);

        if ($read_res->isError()) {
            $res_obj->msg('Asset file could not be read');
            $res_obj->code('app.core.assets.file_not_readable');

            return $res_obj;
        }

        $res_obj->content = $read_res->output;
        $res_obj->status(true);

        return $res_obj;
    }

    /**
     * The markup for one placement, priority-ordered and stable within a priority. Returns the
     * block rather than echoing it, so the page seams and renderPage() can both use it.
     *
     * @param string $placement PLACEMENT_HEAD / PLACEMENT_BODY_START / PLACEMENT_FOOTER
     * @return string
     */
    public function buildHtml($placement)
    {
        $html = '';

        if (empty($this->queue) || empty($placement)) {
            return $html;
        }

        $queue_items = [];

        // Nobody asked to be ordered, so the order they were registered in IS the order. No
        // buckets, no ksort, nothing compared — the whole reason a priority is not invented
        // for assets that never wanted one.
        if (empty($this->has_priority)) {
            foreach ($this->queue as $item) {
                if ($item['placement'] != $placement) {
                    continue;
                }

                $queue_items[] = $item;
            }
        } else {
            // Bucketed rather than sorted: insertion order inside a bucket IS the tiebreak, so
            // two plugins at the same priority never have to coordinate, and there is no
            // comparator to get wrong on a PHP whose sort is not stable. An asset that named no
            // priority takes the framework's default, which is what lets it order against the
            // ones that did.
            $buckets = [];

            foreach ($this->queue as $item) {
                if ($item['placement'] != $placement) {
                    continue;
                }

                $priority = Dj_App_Util::getField('priority', $item, Dj_App_Hooks::DEFAULT_PRIORITY);
                $buckets[$priority][] = $item;
            }

            ksort($buckets);

            foreach ($buckets as $bucket_items) {
                foreach ($bucket_items as $item) {
                    $queue_items[] = $item;
                }
            }
        }

        if (empty($queue_items)) {
            return $html;
        }

        // Last, and only when something asked: a prerequisite is a hard constraint while a
        // priority is a preference, so it gets to move what priority already arranged.
        if (!empty($this->has_prereq)) {
            $queue_items = $this->sortByPrereq($queue_items);
        }

        $ctx = [ 'placement' => $placement, ];
        $queue_items = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_QUEUE, $queue_items, $ctx);

        if (empty($queue_items) || !is_array($queue_items)) {
            return $html;
        }

        $tags = [];

        foreach ($queue_items as $item) {
            $tag_html = $this->buildTagHtml($item);

            if (empty($tag_html)) {
                continue;
            }

            $tag_ctx = $item;
            $tag_ctx['placement'] = $placement;
            $tag_html = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_TAG_HTML, $tag_html, $tag_ctx);

            if (empty($tag_html)) {
                continue;
            }

            $tags[] = $tag_html;
        }

        if (!empty($tags)) {
            $html = implode("\n", $tags);
            $html .= "\n";
        }

        $html = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_HTML, $html, $ctx);

        return $html;
    }

    /**
     * Reorders so an asset follows everything it named as a prerequisite. Priority and
     * registration order decided the list handed in; this only moves what has to move, so an
     * asset with no prerequisites keeps the place those rules gave it.
     *
     * A prerequisite naming an asset that is not in THIS list — never registered, or sitting in
     * the head while this renders the footer — is already satisfied: it either loaded earlier in
     * the document or does not exist to wait for. Blocking on it would drop a working asset over
     * a name nobody registered.
     *
     * @param array $queue_items Items in the order priority left them
     * @return array
     */
    public function sortByPrereq($queue_items = [])
    {
        $present_ids = [];

        foreach ($queue_items as $item) {
            $present_ids[$item['id']] = 1;
        }

        $ordered_items = [];
        $emitted_ids = [];
        $remaining_items = $queue_items;

        // Each pass emits everything whose prerequisites are already out, in the order the list
        // arrived — so the tiebreak stays priority-then-registration and nothing is reshuffled
        // beyond what a prerequisite demanded.
        while (!empty($remaining_items)) {
            $progressed = false;
            $deferred_items = [];

            foreach ($remaining_items as $item) {
                $is_ready = true;
                $item_prereq_ids = empty($item['prereq']) ? [] : $item['prereq'];

                // add() stores a list, but the item passes through a filter on the way here and
                // a listener can hand back whatever it likes. Re-normalizing a stray string
                // beats iterating one character at a time — and it goes through the same
                // resolver, so 'a, b' arriving that way still means two names and not one.
                if (!is_array($item_prereq_ids)) {
                    $item_prereq_ids = $this->resolvePrereqIds($item);
                }

                foreach ($item_prereq_ids as $prereq_id) {
                    if (!isset($present_ids[$prereq_id])) {
                        continue;
                    }

                    if (isset($emitted_ids[$prereq_id])) {
                        continue;
                    }

                    $is_ready = false;
                    break;
                }

                if (!$is_ready) {
                    $deferred_items[] = $item;
                    continue;
                }

                $ordered_items[] = $item;
                $emitted_ids[$item['id']] = 1;
                $progressed = true;
            }

            // Nothing moved and something is left, so the rest wait on each other. A cycle is a
            // registration bug, not a reason to drop assets off the page — they go out in the
            // order they came, and the log carries the ids so it can be found.
            if (empty($progressed)) {
                $cycle_ids = [];

                foreach ($deferred_items as $item) {
                    $ordered_items[] = $item;
                    $cycle_ids[] = $item['id'];
                }

                $log_data = [
                    'asset_ids' => $cycle_ids,
                ];

                Dj_App_Log::error($log_data, __METHOD__);

                break;
            }

            $remaining_items = $deferred_items;
        }

        return $ordered_items;
    }

    /**
     * One queued item as one tag. Content that already carries its own <script> / <style>
     * ships verbatim — wrapping it again would nest the tags, and its attributes are its own
     * to declare.
     *
     * @param array $item A queue entry
     * @return string
     */
    public function buildTagHtml($item = [])
    {
        $kind = empty($item['kind']) ? Dj_App_Assets::KIND_JS : $item['kind'];

        // Say which kinds render rather than letting "not css" stand in for js: a third kind
        // would reach the branches below and be emitted as a <script>, handing the browser an
        // arbitrary file as executable javascript.
        if (!isset(Dj_App_Assets::SUPPORTED_KINDS[$kind])) {
            return '';
        }

        $attrs = empty($item['attrs']) ? [] : $item['attrs'];
        $url = empty($item['url']) ? '' : $item['url'];
        $content = empty($item['content']) ? '' : $item['content'];

        if (!empty($url)) {
            if (isset(Dj_App_Assets::LINK_RELS[$kind])) {
                $tag_attrs = [ 'rel' => Dj_App_Assets::LINK_RELS[$kind], ];
                $tag_attrs['href'] = $url;
                $tag_attrs = array_replace($tag_attrs, $attrs);
                $attrs_html = $this->buildAttrsHtml($tag_attrs);
                $tag_html = '<link' . $attrs_html . '>';

                return $tag_html;
            }

            $tag_attrs = [ 'src' => $url, ];
            $tag_attrs = array_replace($tag_attrs, $attrs);
            $attrs_html = $this->buildAttrsHtml($tag_attrs);
            $tag_html = '<script' . $attrs_html . '></script>';

            return $tag_html;
        }

        if (empty($content)) {
            return '';
        }

        // Not the same question as LINK_RELS: css is BOTH, an icon is neither. A kind with no
        // inline form reaches the page as a url or not at all, so content for one is a caller
        // mistake rather than markup to invent a wrapper for.
        if (!isset(Dj_App_Assets::INLINE_KINDS[$kind])) {
            return '';
        }

        if ($this->isWrappedContent($content)) {
            return $content;
        }

        $attrs_html = $this->buildAttrsHtml($attrs);

        if ($kind == Dj_App_Assets::KIND_CSS) {
            $tag_html = '<style' . $attrs_html . '>' . $content . '</style>';

            return $tag_html;
        }

        $tag_html = '<script' . $attrs_html . '>' . $content . '</script>';

        return $tag_html;
    }

    /**
     * A key => value map as attribute markup, with a leading space when there is anything to
     * render. A true value renders the attribute BARE, which is how defer / async / nomodule
     * are written.
     *
     * @param array $attrs
     * @return string
     */
    public function buildAttrsHtml($attrs = [])
    {
        if (empty($attrs) || !is_array($attrs)) {
            return '';
        }

        // href and src carry a URL, so they go through the URL escaper — which also refuses a
        // javascript: value someone slipped in through the attribute map.
        $url_attr_names = [
            'href' => 1,
            'src' => 1,
        ];

        $attr_pairs = [];

        foreach ($attrs as $attr_name => $attr_val) {
            // Formatted, so an attribute NAME can never carry a quote out into the tag.
            $attr_name = Dj_App_String_Util::formatStringId($attr_name);

            if (empty($attr_name)) {
                continue;
            }

            if ($attr_val === true) {
                $attr_pairs[] = $attr_name;
                continue;
            }

            if (!is_scalar($attr_val)) {
                continue;
            }

            if (isset($url_attr_names[$attr_name])) {
                $attr_val_esc = Dj_App_HTML::escUrl($attr_val);

                // The escaper refused it. Drop the ATTRIBUTE rather than render an empty one:
                // a href="" resolves to the current page, so an inert tag is the honest result.
                if (empty($attr_val_esc)) {
                    continue;
                }
            } else {
                $attr_val_esc = Dj_App_HTML::escAttr($attr_val);
            }

            $attr_pairs[] = $attr_name . '="' . $attr_val_esc . '"';
        }

        if (empty($attr_pairs)) {
            return '';
        }

        $attrs_html = ' ' . implode(' ', $attr_pairs);

        return $attrs_html;
    }

    /**
     * Writes the queued markup into the page. ONE listener for all three page seams: the
     * firing hook says which placement it is, and a caller may name one explicitly instead.
     *
     * @param array $ctx placement — optional; defaults to whichever seam is firing
     * @return void echoes into the buffer the page is being built in
     */
    public function renderAssets($ctx = [])
    {
        $placement = Dj_App_Util::getField('placement', $ctx);

        if (empty($placement)) {
            $placement = Dj_App_Assets::PLACEMENT_FOOTER;

            if (Dj_App_Hooks::currentAction(Dj_App_Assets::HOOK_PAGE_HEAD)) {
                $placement = Dj_App_Assets::PLACEMENT_HEAD;
            } elseif (Dj_App_Hooks::currentAction(Dj_App_Assets::HOOK_PAGE_BODY_START)) {
                $placement = Dj_App_Assets::PLACEMENT_BODY_START;
            }
        }

        $html = $this->buildHtml($placement);

        if (empty($html)) {
            return;
        }

        echo $html;
    }

}
