<?php

use PHPUnit\Framework\TestCase;

class Dj_App_Assets_Test extends TestCase {

    // The fixture tree stands in for a site's content dir: a PUBLIC plugin + theme under
    // dj-content (URL-reachable) and a PRIVATE plugin outside it (auto-inlined). Both
    // locations are pointed at through the filters core already exposes, so nothing here
    // touches the real site layout.
    private $fixture_root_dir = '';
    private $content_dir = '';
    private $non_public_plugins_dir = '';

    protected function setUp(): void
    {
        $this->fixture_root_dir = sys_get_temp_dir() . '/dj_app_assets_test';
        $this->content_dir = $this->fixture_root_dir . '/dj-content';
        $this->non_public_plugins_dir = $this->fixture_root_dir . '/private/plugins';

        $fixture_files = [
            $this->content_dir . '/plugins/djebel-test-plugin/assets/main.js' => "var djTestPlugin = 1;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/main.css' => ".dj-test { color: red; }\n",
            $this->content_dir . '/themes/djebel-test-theme/style.css' => ".dj-theme { color: blue; }\n",
            $this->non_public_plugins_dir . '/djebel-private-plugin/assets/hidden.js' => "var djPrivatePlugin = 1;\n",
        ];

        foreach ($fixture_files as $fixture_file => $fixture_content) {
            $write_res = Dj_App_File_Util::write($fixture_file, $fixture_content);
            $this->assertTrue($write_res->isSuccess(), 'Failed to write the asset fixture: ' . $fixture_file);
        }

        Dj_App_Hooks::addFilter('app.config.content_dir', ['Dj_App_Assets_Test', 'filterContentDir']);
        Dj_App_Hooks::addFilter('app.core.plugins.non_public_plugins_dir', ['Dj_App_Assets_Test', 'filterNonPublicPluginsDir']);

        // installHooks() is idempotent — a registration is keyed by its callback, so re-running
        // it re-seats the page seams rather than duplicating them. Done here so the seam tests
        // stand on their own whatever an earlier test left in the shared hook registry.
        $assets_obj = Dj_App_Assets::getInstance();
        $assets_obj->installHooks();
    }

    protected function tearDown(): void
    {
        // setUp registered both, so both must come off. A filter that fails to unregister
        // is not a cosmetic leak — it stays live for every later test in the class.
        $removed = Dj_App_Hooks::removeFilter('app.config.content_dir', ['Dj_App_Assets_Test', 'filterContentDir']);
        $this->assertTrue($removed, 'The content dir filter leaked out of the test');

        $removed = Dj_App_Hooks::removeFilter('app.core.plugins.non_public_plugins_dir', ['Dj_App_Assets_Test', 'filterNonPublicPluginsDir']);
        $this->assertTrue($removed, 'The non-public plugins dir filter leaked out of the test');

        // The singleton is process-wide; without this the suite becomes order-dependent.
        $assets_obj = Dj_App_Assets::getInstance();
        $remove_res = $assets_obj->removeAll();
        $this->assertTrue($remove_res->isSuccess());
    }

    /**
     * Registers an asset fixture and fails HERE if it did not queue. A registration that
     * silently failed otherwise surfaces as a confusing assertion much further down, pointing
     * at the thing under test instead of at the setup that never happened.
     *
     * Tests that are asserting a REFUSAL call Dj_App_Assets::register() directly — they want
     * the error Result, not this.
     *
     * @param array $ctx See Dj_App_Assets::add()
     * @return string The resolved asset id
     */
    public function registerAsset($ctx = [])
    {
        $res_obj = Dj_App_Assets::register($ctx);
        $this->assertTrue($res_obj->isSuccess(), 'Failed to register the asset fixture');

        return $res_obj->id;
    }

    /**
     * @param string $cur_val
     * @param array $ctx
     * @return string
     */
    public static function filterContentDir($cur_val, $ctx = [])
    {
        $dir = sys_get_temp_dir() . '/dj_app_assets_test/dj-content';

        return $dir;
    }

    /**
     * @param string $cur_val
     * @param array $ctx
     * @return string
     */
    public static function filterNonPublicPluginsDir($cur_val, $ctx = [])
    {
        $dir = sys_get_temp_dir() . '/dj_app_assets_test/private/plugins';

        return $dir;
    }

    // ---------------------------------------------------------------- kind + target

    public function testKindInferredFromUrlExtension()
    {
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.css', ]);
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('<link', $head_html);
        $this->assertStringContainsString('x.css', $head_html);
        $this->assertStringNotContainsString('x.js', $head_html);

        $this->assertStringContainsString('<script', $footer_html);
        $this->assertStringContainsString('x.js', $footer_html);
        $this->assertStringNotContainsString('x.css', $footer_html);
    }

    public function testKindInferredFromFileExtension()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.css', ]);
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('main.css', $head_html);
        $this->assertStringContainsString('main.js', $footer_html);
    }

    public function testKindSniffedFromContent()
    {
        $this->registerAsset([ 'content' => '<style>.sniffed-css { color: red; }</style>', ]);
        $this->registerAsset([ 'content' => '<script>var sniffed_js = 1;</script>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('.sniffed-css', $head_html);
        $this->assertStringContainsString('sniffed_js', $footer_html);
    }

    public function testStyleKeyRendersWrappedInHead()
    {
        $this->registerAsset([ 'style' => '.a { color: red; }', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringContainsString('<style>.a { color: red; }</style>', $head_html);
    }

    public function testJsKeyRendersWrappedInFooter()
    {
        $this->registerAsset([ 'js' => 'var cfg = {};', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('<script>var cfg = {};</script>', $footer_html);
    }

    public function testPreWrappedContentPassesThroughUntouched()
    {
        $content = '<script type="module">var already_wrapped = 1;</script>';
        $this->registerAsset([ 'content' => $content, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString($content, $footer_html);
        $this->assertStringNotContainsString('<script><script', $footer_html);
    }

    public function testJsForcedToHeadViaTarget()
    {
        $ctx = [
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
            'target' => Dj_App_Assets::TARGET_HEAD,
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('main.js', $head_html);
        $this->assertEmpty($footer_html);
    }

    public function testBodyStartTargetAcceptsDashedSpelling()
    {
        $this->registerAsset([ 'js' => 'var underscored = 1;', 'target' => 'body_start', ]);
        $this->registerAsset([ 'js' => 'var dashed = 1;', 'target' => 'body-start', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $body_start_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_BODY_START);

        $this->assertStringContainsString('var underscored = 1;', $body_start_html);
        $this->assertStringContainsString('var dashed = 1;', $body_start_html);
    }

    public function testUnknownTargetThrows()
    {
        $code = '';

        try {
            $this->registerAsset([ 'js' => 'var x = 1;', 'target' => 'nowhere', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.unknown_target', $code);
    }

    // ---------------------------------------------------------------- urls + versioning

    public function testPluginFileBuildsContentUrl()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('/dj-content/plugins/djebel-test-plugin/assets/main.js', $footer_html);
    }

    public function testThemeFileBuildsContentUrl()
    {
        $this->registerAsset([ 'theme' => 'djebel-test-theme', 'file' => '/style.css', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringContainsString('/dj-content/themes/djebel-test-theme/style.css', $head_html);
    }

    public function testVersionParamPresentWhenFileResolves()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $asset_file = $this->content_dir . '/plugins/djebel-test-plugin/assets/main.js';
        $version = filemtime($asset_file);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('v=' . $version, $footer_html);
    }

    /**
     * A caller that already knows the version — a build id, a release tag — supplies it and
     * the file is never stat'ed for a filemtime.
     */
    public function testSuppliedVersionReplacesTheFilemtime()
    {
        $this->registerAsset([
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
            'version' => '1.2.3',
        ]);

        $asset_file = $this->content_dir . '/plugins/djebel-test-plugin/assets/main.js';
        $mtime = filemtime($asset_file);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('v=1.2.3', $footer_html);
        $this->assertStringNotContainsString('v=' . $mtime, $footer_html);
    }

    public function testSuppliedVersionAcceptsTheShortSpellings()
    {
        $this->registerAsset([
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
            'ver' => 'abc123',
        ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('v=abc123', $footer_html);
    }

    public function testVersionParamAbsentForExplicitUrl()
    {
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('src="https://cdn.example.com/x.js"', $footer_html);
        $this->assertStringNotContainsString('v=', $footer_html);
    }

    public function testProtocolRelativeUrlAccepted()
    {
        $this->registerAsset([ 'url' => '//cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('src="//cdn.example.com/x.js"', $footer_html);
    }

    public function testUnusableUrlThrows()
    {
        $code = '';

        try {
            $this->registerAsset([ 'url' => 'javascript:alert(1)', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.invalid_url', $code);
    }

    // ---------------------------------------------------------------- delivery mode

    public function testNonPublicPluginFileIsInlined()
    {
        $res_obj = Dj_App_Assets::register([ 'plugin' => 'djebel-private-plugin', 'file' => '/assets/hidden.js', ]);
        $this->assertTrue($res_obj->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('var djPrivatePlugin = 1;', $footer_html);
        $this->assertStringNotContainsString('src=', $footer_html);
    }

    public function testMissingFileReturnsErrorResult()
    {
        $res_obj = Dj_App_Assets::register([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/nope.js', ]);

        $this->assertTrue($res_obj->isError());
        $this->assertEquals('app.core.assets.file_not_found', $res_obj->code());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    public function testConflictingSourceKeysThrow()
    {
        $code = '';

        try {
            $this->registerAsset([ 'file' => '/assets/main.js', 'style' => '.a {}', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.conflicting_source', $code);
    }

    /**
     * A literal </script inside content we are about to WRAP ends the block wherever it
     * appears, so the browser reads the rest as markup. Refused, and the asset does not
     * render — a typo'd asset must not take the page down, so it is a Result not a throw.
     */
    public function testInlineJsCarryingAClosingScriptTagIsRefused()
    {
        $res_obj = Dj_App_Assets::register([ 'js' => 'var x = "</script><img src=x onerror=alert(1)>";', ]);

        $this->assertTrue($res_obj->isError());
        $this->assertEquals('app.core.assets.unsafe_content', $res_obj->code());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    public function testInlineStyleCarryingAClosingStyleTagIsRefused()
    {
        $res_obj = Dj_App_Assets::register([ 'style' => '.a {} </style><script>alert(1)</script>', ]);

        $this->assertTrue($res_obj->isError());
        $this->assertEquals('app.core.assets.unsafe_content', $res_obj->code());
    }

    /**
     * Content that ships its own tag owns its own markup, closing tag included — the refusal
     * above must not turn a legitimate pre-wrapped block into an error.
     */
    public function testPreWrappedContentKeepsItsOwnClosingTag()
    {
        $res_obj = Dj_App_Assets::register([ 'content' => '<script>var owns_its_tag = 1;</script>', ]);

        $this->assertTrue($res_obj->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);
        $this->assertStringContainsString('var owns_its_tag = 1;', $footer_html);
    }

    /**
     * The wrapped check reads the FIRST byte before anything else, so a tag that arrives behind
     * the newline a template or heredoc opens with must still be recognized. Reading byte zero
     * alone would call this bare content and wrap it a second time.
     */
    public function testPreWrappedContentIsRecognizedBehindLeadingWhitespace()
    {
        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertTrue($assets_obj->isWrappedContent("\n    <style>.a{color:red}</style>"));
        $this->assertTrue($assets_obj->isWrappedContent("\t<script defer>var x = 1;</script>"));
    }

    /**
     * Only script and style wrap an asset. Any other tag is content, and content gets wrapped —
     * so the cheap first-byte test must not answer "wrapped" for every string opening with '<'.
     */
    public function testOtherTagsAreNotTreatedAsWrapped()
    {
        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertFalse($assets_obj->isWrappedContent('<div>nope</div>'));
        $this->assertFalse($assets_obj->isWrappedContent('   <div>nope</div>'));
        $this->assertFalse($assets_obj->isWrappedContent('    '));
    }

    public function testMissingSourceThrows()
    {
        $code = '';

        try {
            $this->registerAsset([ 'priority' => 5, ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.no_source', $code);
    }

    /**
     * A file with no plugin and no theme is the site's OWN, so it is rooted at the content
     * dir. Left unrooted, the leading slash the resolver adds made the caller's string an
     * absolute path, and any readable file on the box was inlined into the page.
     */
    public function testBareFileCannotReachOutsideTheContentDir()
    {
        $asset_ctx = [
            'file' => '/etc/hosts',
            'kind' => 'css',
        ];

        $res_obj = Dj_App_Assets::register($asset_ctx);

        $this->assertTrue($res_obj->isError());
        $this->assertEquals('app.core.assets.file_not_found', $res_obj->code());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    /**
     * A symlink SPELLS itself inside the allowed dir while pointing anywhere on the box, so
     * a prefix test on the spelling alone answers the wrong question — the resolved location
     * is what decides.
     */
    public function testSymlinkEscapingTheContentDirIsRefused()
    {
        $link_file = $this->content_dir . '/assets/escape.css';
        $link_dir = dirname($link_file);

        if (!is_dir($link_dir)) {
            $mkdir_res = mkdir($link_dir, 0755, true);
            $this->assertTrue($mkdir_res, 'Could not create the symlink fixture dir');
        }

        if (is_link($link_file)) {
            $stale_unlink_res = unlink($link_file);
            $this->assertTrue($stale_unlink_res, 'Could not clear a stale symlink fixture');
        }

        $symlink_res = symlink('/etc/hosts', $link_file);
        $this->assertTrue($symlink_res, 'Could not create the escaping symlink');

        $asset_ctx = [
            'file' => '/assets/escape.css',
        ];

        $code = '';

        try {
            Dj_App_Assets::register($asset_ctx);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        // Removed before the assertion, so a failing expectation still leaves no live symlink
        // behind for the tests that run after this one.
        $unlink_res = unlink($link_file);
        $this->assertTrue($unlink_res, 'The escaping symlink leaked out of the test');

        $this->assertEquals('app.core.assets.file_outside_allowed_dirs', $code);
    }

    public function testFileTraversalIsRefused()
    {
        // The kind is pinned so the refusal under test is the traversal guard and not the
        // extension sniffer, which runs earlier and rejects an extension-less name first.
        $asset_ctx = [
            'file' => '/../../../../etc/hosts',
            'kind' => 'css',
        ];

        $code = '';

        try {
            Dj_App_Assets::register($asset_ctx);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.invalid_file', $code);
    }

    // ---------------------------------------------------------------- aliases

    public function testScriptKeyBehavesAsJs()
    {
        $this->registerAsset([ 'script' => 'var via_script = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('<script>var via_script = 1;</script>', $footer_html);
    }

    public function testBufferKeyBehavesAsContent()
    {
        $this->registerAsset([ 'buffer' => '<style>.via-buffer {}</style>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringContainsString('.via-buffer', $head_html);
    }

    public function testDataKeyBehavesAsContent()
    {
        $this->registerAsset([ 'data' => '<style>.via-data {}</style>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringContainsString('.via-data', $head_html);
    }

    public function testAttribsKeyBehavesAsAttrs()
    {
        $ctx = [
            'url' => 'https://cdn.example.com/x.js',
            'attribs' => [ 'defer' => true, ],
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString(' defer>', $footer_html);
    }

    // ---------------------------------------------------------------- attributes

    public function testAttrsRenderOnTag()
    {
        $ctx = [
            'url' => 'https://cdn.example.com/x.js',
            'attrs' => [
                'defer' => true,
                'crossorigin' => 'anonymous',
            ],
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('crossorigin="anonymous"', $footer_html);
        $this->assertStringContainsString(' defer ', $footer_html);
        $this->assertStringNotContainsString('defer="', $footer_html);
    }

    public function testAttrValuesAreEscaped()
    {
        $ctx = [
            'url' => 'https://cdn.example.com/x.js',
            'attrs' => [ 'data-title' => 'a" onload="alert(1)', ],
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $this->assertStringContainsString('&quot;', $footer_html);
        $this->assertStringNotContainsString('onload="alert(1)"', $footer_html);
    }

    /**
     * A url the escaper refuses must cost the ATTRIBUTE, not become an empty one — href=""
     * resolves to the current page, so an inert tag is the honest outcome.
     */
    public function testUrlAttrRefusedByTheEscaperIsDropped()
    {
        $ctx = [
            'url' => 'https://cdn.example.com/x.css',
            'attrs' => [ 'href' => 'javascript:alert(1)', ],
        ];

        $res_obj = Dj_App_Assets::register($ctx);
        $this->assertTrue($res_obj->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringNotContainsString('javascript:', $head_html);
        $this->assertStringNotContainsString('href=', $head_html);
        $this->assertStringContainsString('rel="stylesheet"', $head_html);
    }

    public function testAttrsCanOverrideStylesheetRel()
    {
        $ctx = [
            'url' => 'https://cdn.example.com/x.css',
            'attrs' => [ 'rel' => 'preload', ],
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_HEAD);

        $this->assertStringContainsString('rel="preload"', $head_html);
        $this->assertStringNotContainsString('rel="stylesheet"', $head_html);
    }

    // ---------------------------------------------------------------- ordering

    public function testLowerPriorityRendersFirst()
    {
        $this->registerAsset([ 'js' => 'var late = 1;', 'priority' => 90, ]);
        $this->registerAsset([ 'js' => 'var early = 1;', 'priority' => 5, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $early_pos = strpos($footer_html, 'var early = 1;');
        $late_pos = strpos($footer_html, 'var late = 1;');

        $this->assertLessThan($late_pos, $early_pos);
    }

    public function testSortIsStableWithinOnePriority()
    {
        $this->registerAsset([ 'js' => 'var first = 1;', 'priority' => 30, ]);
        $this->registerAsset([ 'js' => 'var second = 1;', 'priority' => 30, ]);
        $this->registerAsset([ 'js' => 'var third = 1;', 'priority' => 30, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $first_pos = strpos($footer_html, 'var first = 1;');
        $second_pos = strpos($footer_html, 'var second = 1;');
        $third_pos = strpos($footer_html, 'var third = 1;');

        $this->assertLessThan($second_pos, $first_pos);
        $this->assertLessThan($third_pos, $second_pos);
    }

    public function testAssetThatNamesNoPriorityCarriesNone()
    {
        $res_obj = Dj_App_Assets::register([ 'js' => 'var defaulted = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $item = $queue[$res_obj->id];

        $this->assertArrayNotHasKey('priority', $item);
    }

    public function testAssetsRenderInRegistrationOrderWhenNoPriorityIsGiven()
    {
        $this->registerAsset([ 'js' => 'var one = 1;', ]);
        $this->registerAsset([ 'js' => 'var two = 1;', ]);
        $this->registerAsset([ 'js' => 'var three = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $one_pos = strpos($footer_html, 'var one = 1;');
        $two_pos = strpos($footer_html, 'var two = 1;');
        $three_pos = strpos($footer_html, 'var three = 1;');

        $this->assertLessThan($two_pos, $one_pos);
        $this->assertLessThan($three_pos, $two_pos);
    }

    /**
     * The one that earns the whole design: an asset that named nothing must still sort against
     * the ones that did, or a plugin could never place itself relative to somebody else's.
     */
    public function testAssetWithoutPriorityOrdersAsTheHooksDefault()
    {
        $this->registerAsset([ 'js' => 'var unpriced = 1;', ]);
        $this->registerAsset([ 'js' => 'var early = 1;', 'priority' => 5, ]);
        $this->registerAsset([ 'js' => 'var late = 1;', 'priority' => 90, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $early_pos = strpos($footer_html, 'var early = 1;');
        $unpriced_pos = strpos($footer_html, 'var unpriced = 1;');
        $late_pos = strpos($footer_html, 'var late = 1;');

        $this->assertLessThan($unpriced_pos, $early_pos);
        $this->assertLessThan($late_pos, $unpriced_pos);
    }

    /**
     * Zero is a real priority and the earliest one — it must not be read as "no priority given".
     */
    public function testPriorityZeroIsHonored()
    {
        $this->registerAsset([ 'js' => 'var normal = 1;', ]);
        $this->registerAsset([ 'js' => 'var first = 1;', 'priority' => 0, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

        $first_pos = strpos($footer_html, 'var first = 1;');
        $normal_pos = strpos($footer_html, 'var normal = 1;');

        $this->assertLessThan($normal_pos, $first_pos);
    }

    // ---------------------------------------------------------------- duplicates

    public function testIdenticalContentEmitsOnce()
    {
        $this->registerAsset([ 'js' => 'var shared = 1;', ]);
        $this->registerAsset([ 'js' => 'var shared = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertCount(1, $queue);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);
        $this->assertEquals(1, substr_count($footer_html, 'var shared = 1;'));
    }

    public function testExplicitIdLastWriteWinsKeepingPosition()
    {
        $this->registerAsset([ 'js' => 'var one = 1;', ]);
        $this->registerAsset([ 'js' => 'var original = 1;', 'id' => 'my-asset', ]);
        $this->registerAsset([ 'js' => 'var three = 1;', ]);

        // Same handle again: the content is replaced, the queue position is not.
        $this->registerAsset([ 'js' => 'var overridden = 1;', 'id' => 'my-asset', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertCount(3, $queue);

        $ids = array_keys($queue);
        $this->assertEquals('my-asset', $ids[1]);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);
        $this->assertStringContainsString('var overridden = 1;', $footer_html);
        $this->assertStringNotContainsString('var original = 1;', $footer_html);

        $overridden_pos = strpos($footer_html, 'var overridden = 1;');
        $three_pos = strpos($footer_html, 'var three = 1;');
        $this->assertLessThan($three_pos, $overridden_pos);
    }

    // ---------------------------------------------------------------- queue editing

    public function testAddReturnsIdThatRoundTripsIntoRemove()
    {
        $res_obj = Dj_App_Assets::register([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertNotEmpty($res_obj->id);

        $remove_res = Dj_App_Assets::deregister($res_obj->id);
        $this->assertTrue($remove_res->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    public function testRemoveOnUnqueuedIdIsNoOpSuccess()
    {
        $remove_res = Dj_App_Assets::deregister('never-registered');

        $this->assertTrue($remove_res->isSuccess());
    }

    public function testDeregisterByParamsFindsSameItem()
    {
        $ctx = [
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
        ];

        $this->registerAsset($ctx);
        $remove_res = Dj_App_Assets::deregister($ctx);

        $this->assertTrue($remove_res->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    public function testCustomIdStillRemovableByParams()
    {
        $add_params = [
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
            'id' => 'hand-picked-id',
        ];

        $this->registerAsset($add_params);

        // The params that created it — WITHOUT the custom id, so the re-derived handle
        // cannot match and the source itself has to be what finds the item.
        $remove_ctx = [
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
        ];

        $remove_res = Dj_App_Assets::deregister($remove_ctx);
        $this->assertTrue($remove_res->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    public function testReplacePreservesPosition()
    {
        $this->registerAsset([ 'js' => 'var one = 1;', ]);
        $this->registerAsset([ 'js' => 'var two = 1;', 'id' => 'swap-me', ]);
        $this->registerAsset([ 'js' => 'var three = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $replace_res = $assets_obj->replace('swap-me', [ 'js' => 'var swapped = 1;', ]);
        $this->assertTrue($replace_res->isSuccess());

        $queue = $assets_obj->getQueue();
        $ids = array_keys($queue);
        $this->assertEquals('swap-me', $ids[1]);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);
        $swapped_pos = strpos($footer_html, 'var swapped = 1;');
        $three_pos = strpos($footer_html, 'var three = 1;');

        $this->assertLessThan($three_pos, $swapped_pos);
    }

    public function testRemoveAllEmptiesQueue()
    {
        $this->registerAsset([ 'js' => 'var one = 1;', ]);
        $this->registerAsset([ 'style' => '.two {}', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $remove_res = $assets_obj->removeAll();
        $this->assertTrue($remove_res->isSuccess());

        $queue = $assets_obj->getQueue();
        $this->assertEmpty($queue);
    }

    // ---------------------------------------------------------------- hooks

    public function testAddParamsFilterRewritesTheParams()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.add_params', ['Dj_App_Assets_Test', 'filterAddParamsToCdn']);

        try {
            $this->registerAsset([ 'url' => 'https://cdn.example.com/x.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

            $this->assertStringContainsString('https://rewritten.example.com/x.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.add_params', ['Dj_App_Assets_Test', 'filterAddParamsToCdn']);
            $this->assertTrue($removed, 'The add_params filter leaked out of the test');
        }
    }

    public function testItemFilterReturningEmptyVetoesTheAsset()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.item', ['Dj_App_Assets_Test', 'filterItemVeto']);

        try {
            $res_obj = Dj_App_Assets::register([ 'js' => 'var vetoed = 1;', ]);

            $this->assertTrue($res_obj->isError());
            $this->assertEquals('app.core.assets.vetoed', $res_obj->code());

            $assets_obj = Dj_App_Assets::getInstance();
            $queue = $assets_obj->getQueue();
            $this->assertEmpty($queue);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.item', ['Dj_App_Assets_Test', 'filterItemVeto']);
            $this->assertTrue($removed, 'The veto filter leaked out of the test');
        }
    }

    public function testAddedActionFires()
    {
        Dj_App_Hooks::addAction('app.core.assets.action.added', ['Dj_App_Assets_Test', 'recordAddedAsset']);
        self::$added_asset_ids = [];

        try {
            $res_obj = Dj_App_Assets::register([ 'js' => 'var observed = 1;', ]);

            $this->assertContains($res_obj->id, self::$added_asset_ids);
        } finally {
            $removed = Dj_App_Hooks::removeAction('app.core.assets.action.added', ['Dj_App_Assets_Test', 'recordAddedAsset']);
            $this->assertTrue($removed, 'The added action listener leaked out of the test');
            self::$added_asset_ids = [];
        }
    }

    public function testTagHtmlFilterStampsAnAttribute()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.tag_html', ['Dj_App_Assets_Test', 'filterTagHtmlAddNonce']);

        try {
            $this->registerAsset([ 'js' => 'var stamped = 1;', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

            $this->assertStringContainsString('nonce="dj-test-nonce"', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.tag_html', ['Dj_App_Assets_Test', 'filterTagHtmlAddNonce']);
            $this->assertTrue($removed, 'The tag_html filter leaked out of the test');
        }
    }

    public function testQueueFilterSeesTheWholeSortedSet()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.queue', ['Dj_App_Assets_Test', 'filterQueueDropSecond']);

        try {
            $this->registerAsset([ 'js' => 'var kept = 1;', ]);
            $this->registerAsset([ 'js' => 'var dropped = 1;', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

            $this->assertStringContainsString('var kept = 1;', $footer_html);
            $this->assertStringNotContainsString('var dropped = 1;', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.queue', ['Dj_App_Assets_Test', 'filterQueueDropSecond']);
            $this->assertTrue($removed, 'The queue filter leaked out of the test');
        }
    }

    public function testHtmlFilterSeesTheAssembledBlock()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.html', ['Dj_App_Assets_Test', 'filterHtmlWrapInComment']);

        try {
            $this->registerAsset([ 'js' => 'var wrapped = 1;', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::TARGET_FOOTER);

            $this->assertStringContainsString('<!-- dj-assets-start -->', $footer_html);
            $this->assertStringContainsString('var wrapped = 1;', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.html', ['Dj_App_Assets_Test', 'filterHtmlWrapInComment']);
            $this->assertTrue($removed, 'The html filter leaked out of the test');
        }
    }

    // ---------------------------------------------------------------- page seams

    public function testRenderAssetsEchoesForTheCurrentHook()
    {
        $this->registerAsset([ 'style' => '.head-seam {}', ]);
        $this->registerAsset([ 'js' => 'var footer_seam = 1;', ]);

        $head_html = Dj_App_Hooks::captureHookOutput('app.page.html.head');
        $footer_html = Dj_App_Hooks::captureHookOutput('app.page.html.body.end');

        $this->assertStringContainsString('.head-seam {}', $head_html);
        $this->assertStringContainsString('var footer_seam = 1;', $footer_html);
    }

    /**
     * renderPage() is terminal and never reaches app.page.full_content, so it runs the seams
     * itself through the shared injector. This asserts that path end to end: a queued asset
     * lands in a page buffer that was never touched by the page pipeline.
     *
     * Runs in a SEPARATE PROCESS by necessity, not preference: the injector skips a seam that
     * hasRun(), and the executed-hook registry is private and process-wide, so an earlier test
     * firing a seam would otherwise decide this one's result.
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testTerminalRenderCarriesAssetsThroughTheSeamInjector()
    {
        $this->registerAsset([ 'style' => '.error-page-css {}', ]);
        $this->registerAsset([ 'js' => 'var error_page_js = 1;', ]);

        $page_buff = '<html><head><title>x</title></head><body><p>boom</p></body></html>';
        $injected_buff = Dj_App_Util::autoInjectSysHookContent($page_buff);

        $this->assertStringContainsString('.error-page-css {}', $injected_buff);
        $this->assertStringContainsString('var error_page_js = 1;', $injected_buff);
    }

    /**
     * The injector refuses to fire a seam twice, so a page rendered after the theme already
     * fired them keeps what it had rather than getting a second copy of every asset.
     */
    public function testSeamInjectorDoesNotFireASeamTwice()
    {
        $this->registerAsset([ 'style' => '.once-only {}', ]);

        $page_buff = '<html><head><title>x</title></head><body><p>x</p></body></html>';

        $first_buff = Dj_App_Util::autoInjectSysHookContent($page_buff);
        $second_buff = Dj_App_Util::autoInjectSysHookContent($first_buff);

        $this->assertEquals($first_buff, $second_buff);
    }

    // ---------------------------------------------------------------- filter callbacks

    public static $added_asset_ids = [];

    /**
     * @param array $cur_val
     * @param array $ctx
     * @return array
     */
    public static function filterAddParamsToCdn($cur_val, $ctx = [])
    {
        $cur_val['url'] = 'https://rewritten.example.com/x.js';

        return $cur_val;
    }

    /**
     * @param array $cur_val
     * @param array $ctx
     * @return array
     */
    public static function filterItemVeto($cur_val, $ctx = [])
    {
        return [];
    }

    /**
     * @param array $ctx
     * @return void
     */
    public static function recordAddedAsset($ctx = [])
    {
        self::$added_asset_ids[] = $ctx['item']['id'];
    }

    /**
     * @param string $cur_val
     * @param array $ctx
     * @return string
     */
    public static function filterTagHtmlAddNonce($cur_val, $ctx = [])
    {
        $tag_html = str_replace('<script', '<script nonce="dj-test-nonce"', $cur_val);

        return $tag_html;
    }

    /**
     * @param array $cur_val
     * @param array $ctx
     * @return array
     */
    public static function filterQueueDropSecond($cur_val, $ctx = [])
    {
        $queue_items = [];

        foreach ($cur_val as $item) {
            if (strpos($item['content'], 'dropped') !== false) {
                continue;
            }

            $queue_items[] = $item;
        }

        return $queue_items;
    }

    /**
     * @param string $cur_val
     * @param array $ctx
     * @return string
     */
    public static function filterHtmlWrapInComment($cur_val, $ctx = [])
    {
        $html = "<!-- dj-assets-start -->\n" . $cur_val;

        return $html;
    }
}
