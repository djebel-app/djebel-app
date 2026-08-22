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
 * no 'kind' and no 'target'. CSS lands in the head, JS in the footer, and every inference
 * is overridable.
 *
 * Only dj-content is served over the web. A plugin installed in the private tree therefore
 * has no URL at all, so its file is INLINED rather than linked — the author does not have
 * to know where their plugin was installed.
 */
class Dj_App_Assets {
    // Where a tag goes. TARGET_BODY_START rides the seam core already injects after <body.
    const TARGET_HEAD = 'head';
    const TARGET_BODY_START = 'body_start';
    const TARGET_FOOTER = 'footer';

    const KIND_CSS = 'css';
    const KIND_JS = 'js';

    // The page seams this echoes on. Registered on all three with ONE listener, which reads
    // back the firing hook to learn which target it is rendering.
    const HOOK_PAGE_HEAD = 'app.page.html.head';
    const HOOK_PAGE_BODY_START = 'app.page.html.body.start';
    const HOOK_PAGE_BODY_END = 'app.page.html.body.end';

    // renderPage() is a self-contained terminal renderer and fires none of the seams above,
    // so error pages reach the queue through these two instead.
    const HOOK_RENDER_PAGE_HEAD = 'app.page.render.head_content';
    const HOOK_RENDER_PAGE_FOOTER = 'app.page.render.footer_content';

    // Around adding.
    const FILTER_ITEM = 'app.core.assets.filter.item';
    const ACTION_ADDED = 'app.core.assets.action.added';

    // Around rendering.
    const FILTER_QUEUE = 'app.core.assets.filter.queue';
    const FILTER_TAG_HTML = 'app.core.assets.filter.tag_html';
    const FILTER_HTML = 'app.core.assets.filter.html';

    const SOURCE_FILE = 'file';
    const SOURCE_URL = 'url';
    const SOURCE_STYLE = 'style';
    const SOURCE_JS = 'js';
    const SOURCE_CONTENT = 'content';

    // Enough of a value to see a leading <script / <style and no more.
    const SNIFF_CHUNK_SIZE = 32;

    // id => item, in registration order. Re-adding a known id overwrites the entry WHERE IT
    // ALREADY SITS, which is what makes an explicit id an override handle rather than a move.
    private $queue = [];

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

        $render_page_hooks = [
            Dj_App_Assets::HOOK_RENDER_PAGE_HEAD,
            Dj_App_Assets::HOOK_RENDER_PAGE_FOOTER,
        ];

        Dj_App_Hooks::addFilter($render_page_hooks, [$this, 'filterRenderPageContent']);
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
     *   - kind: 'css' or 'js', overriding what the key implied
     *   - target: TARGET_HEAD / TARGET_BODY_START / TARGET_FOOTER
     *   - priority: lower renders earlier; defaults to Dj_App_Hooks::DEFAULT_PRIORITY
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

            $delivery_res = $this->resolveFileDelivery($source_res->abs_file);

            if ($delivery_res->isError()) {
                return $delivery_res;
            }

            $url = $delivery_res->url;
            $content = $delivery_res->content;
        }

        $priority = Dj_App_Util::getField('priority', $params, Dj_App_Hooks::DEFAULT_PRIORITY);
        $attrs = Dj_App_Util::getField('attrs|attribs', $params, []);
        $target = Dj_App_Util::getField('target', $params);

        if (empty($target)) {
            $target = Dj_App_Assets::TARGET_FOOTER;

            if ($kind == Dj_App_Assets::KIND_CSS) {
                $target = Dj_App_Assets::TARGET_HEAD;
            }
        } else {
            // The constants are underscored; a caller writing body-start means the same thing.
            $target = str_replace('-', '_', $target);
            $target = Dj_App_String_Util::formatStringId($target);

            $allowed_targets = [
                Dj_App_Assets::TARGET_HEAD => 1,
                Dj_App_Assets::TARGET_BODY_START => 1,
                Dj_App_Assets::TARGET_FOOTER => 1,
            ];

            if (!isset($allowed_targets[$target])) {
                throw new Dj_App_Validation_Exception('Unknown asset target', [
                    'code' => 'app.core.assets.unknown_target',
                    'target' => $target,
                ]);
            }
        }

        $item = [
            'id' => $source_res->id,
            'kind' => $kind,
            'target' => $target,
            'priority' => $priority,
            'url' => $url,
            'content' => $content,
            'attrs' => $attrs,
            'source_hash' => $source_res->source_hash,
        ];

        $item = Dj_App_Hooks::applyFilter(Dj_App_Assets::FILTER_ITEM, $item, $params);

        // An empty return is the veto seam — the one way a site says "never load that".
        if (empty($item) || !is_array($item) || empty($item['id'])) {
            $res_obj->msg('Asset vetoed');
            $res_obj->code('app.core.assets.vetoed');

            return $res_obj;
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

        if (empty($id)) {
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

        if (empty($asset_id) || !isset($this->queue[$asset_id])) {
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

        $lead_chunk = substr($content, 0, Dj_App_Assets::SNIFF_CHUNK_SIZE);
        $lead_chunk = Dj_App_String_Util::trim($lead_chunk);
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

        $inp_file = Dj_App_Util::getField('file', $params);
        $inp_url = Dj_App_Util::getField('url', $params);
        $inp_style = Dj_App_Util::getField('style', $params);
        $inp_js = Dj_App_Util::getField('js|script', $params);
        $inp_content = Dj_App_Util::getField('content|buffer|data', $params);

        $sources = [
            Dj_App_Assets::SOURCE_FILE => $inp_file,
            Dj_App_Assets::SOURCE_URL => $inp_url,
            Dj_App_Assets::SOURCE_STYLE => $inp_style,
            Dj_App_Assets::SOURCE_JS => $inp_js,
            Dj_App_Assets::SOURCE_CONTENT => $inp_content,
        ];

        $source_type = '';
        $source_val = '';
        $given_source_keys = [];

        foreach ($sources as $source_key => $val) {
            if (!is_scalar($val) || strlen($val) == 0) {
                continue;
            }

            $given_source_keys[] = $source_key;

            if (empty($source_type)) {
                $source_type = $source_key;
                $source_val = $val;
            }
        }

        if (empty($source_type)) {
            throw new Dj_App_Validation_Exception('An asset needs a source', [
                'code' => 'app.core.assets.no_source',
            ]);
        }

        // Which key wins must never become folklore, so two sources is a hard error.
        if (count($given_source_keys) > 1) {
            throw new Dj_App_Validation_Exception('An asset takes exactly one source', [
                'code' => 'app.core.assets.conflicting_source',
                'source_keys' => $given_source_keys,
            ]);
        }

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

        $kind_args = [
            'params' => $params,
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
        $asset_id = Dj_App_Util::getField('id', $params);

        if (empty($asset_id)) {
            $asset_id = $source_hash;
        } else {
            $asset_id = Dj_App_String_Util::formatStringId($asset_id);

            if (empty($asset_id)) {
                throw new Dj_App_Validation_Exception('Unusable asset id', [
                    'code' => 'app.core.assets.invalid_id',
                    'id' => $params['id'],
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
     * CSS or JS — from an explicit 'kind', else from the key the caller used, else from the
     * file/url extension, else sniffed off a leading <script / <style. Un-sniffable inline
     * content is treated as JS, which is what an inline blob almost always is; pass 'kind'
     * when it is not.
     *
     * @param array $args params, source_type, source_val
     * @return string
     * @throws Dj_App_Validation_Exception
     */
    public function resolveKind($args = [])
    {
        $params = empty($args['params']) ? [] : $args['params'];
        $source_type = empty($args['source_type']) ? '' : $args['source_type'];
        $source_val = empty($args['source_val']) ? '' : $args['source_val'];

        $kind = Dj_App_Util::getField('kind', $params);

        if (!empty($kind)) {
            $kind = Dj_App_String_Util::formatStringId($kind);

            if ($kind != Dj_App_Assets::KIND_CSS && $kind != Dj_App_Assets::KIND_JS) {
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

        if ($ext == 'css') {
            return Dj_App_Assets::KIND_CSS;
        }

        if ($ext == 'js') {
            return Dj_App_Assets::KIND_JS;
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
     * @param string $abs_file
     * @return Dj_App_Result url, content
     */
    public function resolveFileDelivery($abs_file)
    {
        $res_obj = new Dj_App_Result();
        $res_obj->url = '';
        $res_obj->content = '';

        $content_dir = Dj_App_Util::getContentDir();
        $content_dir_prefix = $content_dir . '/';
        $is_web_reachable = !empty($content_dir) && (strpos($abs_file, $content_dir_prefix) === 0);

        if ($is_web_reachable) {
            $rel_url_file = substr($abs_file, strlen($content_dir));
            $content_url = Dj_App_Util::getContentDirUrl();
            $url = $content_url . $rel_url_file;
            $version = filemtime($abs_file);
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
     * The markup for one target, priority-ordered and stable within a priority. Returns the
     * block rather than echoing it, so the page seams and renderPage() can both use it.
     *
     * @param string $target TARGET_HEAD / TARGET_BODY_START / TARGET_FOOTER
     * @return string
     */
    public function buildHtml($target)
    {
        $html = '';

        if (empty($this->queue) || empty($target)) {
            return $html;
        }

        // Bucketed rather than sorted: insertion order inside a bucket IS the tiebreak, so
        // two plugins at the same priority never have to coordinate, and there is no
        // comparator to get wrong on a PHP whose sort is not stable.
        $buckets = [];

        foreach ($this->queue as $item) {
            if ($item['target'] != $target) {
                continue;
            }

            $buckets[$item['priority']][] = $item;
        }

        if (empty($buckets)) {
            return $html;
        }

        ksort($buckets);

        $queue_items = [];

        foreach ($buckets as $bucket_items) {
            foreach ($bucket_items as $item) {
                $queue_items[] = $item;
            }
        }

        $ctx = [ 'target' => $target, ];
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
            $tag_ctx['target'] = $target;
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
        $attrs = empty($item['attrs']) ? [] : $item['attrs'];
        $url = empty($item['url']) ? '' : $item['url'];
        $content = empty($item['content']) ? '' : $item['content'];

        if (!empty($url)) {
            if ($kind == Dj_App_Assets::KIND_CSS) {
                $tag_attrs = [ 'rel' => 'stylesheet', ];
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
     * firing hook says which target it is, and a caller may name one explicitly instead.
     *
     * @param array $ctx target — optional; defaults to whichever seam is firing
     * @return void echoes into the buffer the page is being built in
     */
    public function renderAssets($ctx = [])
    {
        $target = Dj_App_Util::getField('target', $ctx);

        if (empty($target)) {
            $target = Dj_App_Assets::TARGET_FOOTER;

            if (Dj_App_Hooks::currentAction(Dj_App_Assets::HOOK_PAGE_HEAD)) {
                $target = Dj_App_Assets::TARGET_HEAD;
            } elseif (Dj_App_Hooks::currentAction(Dj_App_Assets::HOOK_PAGE_BODY_START)) {
                $target = Dj_App_Assets::TARGET_BODY_START;
            }
        }

        $html = $this->buildHtml($target);

        if (empty($html)) {
            return;
        }

        echo $html;
    }

    /**
     * Appends the queued markup to renderPage()'s head / footer slots, so an asset reaches an
     * error page too. CAVEAT worth stating plainly: a broken plugin asset can then also affect
     * the page that reports the breakage.
     *
     * @param string $cur_val The content renderPage() already holds for that slot
     * @param array $ctx
     * @return string
     */
    public function filterRenderPageContent($cur_val, $ctx = [])
    {
        $target = Dj_App_Assets::TARGET_FOOTER;

        if (Dj_App_Hooks::currentFilter(Dj_App_Assets::HOOK_RENDER_PAGE_HEAD)) {
            $target = Dj_App_Assets::TARGET_HEAD;
        }

        $html = $this->buildHtml($target);

        if (empty($html)) {
            return $cur_val;
        }

        $content = $cur_val . $html;

        return $content;
    }
}
