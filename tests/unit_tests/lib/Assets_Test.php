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

            // A source WITH a build beside it. The fixtures above deliberately have none, so the
            // tests written before builds existed keep resolving to the file they name.
            $this->content_dir . '/plugins/djebel-test-plugin/assets/favicon.ico' => "\x00\x00\x01\x00",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/icon.png' => "\x89PNG\r\n",

            $this->content_dir . '/plugins/djebel-test-plugin/assets/app.js' => "var djTestApp = 0;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/app.min.js' => "var djTestApp=1;\n",

            // Already a build, plus a TRAP beside each: nothing may ever ask for a doubled
            // suffix, so a file by that name EXISTING is what makes the test fail if the guard
            // is dropped. The upper-case pair is the same trap for a case-blind comparison.
            $this->content_dir . '/plugins/djebel-test-plugin/assets/vendor.min.js' => "var djTestVendor=1;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/vendor.min.min.js' => "var djTestTrap=1;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/lib.MIN.js' => "var djTestLib=1;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/lib.MIN.min.js' => "var djTestTrap=2;\n",

            // Its build is the directory created below, so the source has to survive.
            $this->content_dir . '/plugins/djebel-test-plugin/assets/dirtrap.js' => "var djTestDirTrap = 1;\n",

            // A source inside a DIRECTORY named like the marker — the build is still the sibling
            // file, never anything the enclosing dir is called.
            $this->content_dir . '/plugins/djebel-test-plugin/assets/.min/boxed.js' => "var djTestBoxed = 0;\n",
            $this->content_dir . '/plugins/djebel-test-plugin/assets/.min/boxed.min.js' => "var djTestBoxed=1;\n",
        ];

        foreach ($fixture_files as $fixture_file => $fixture_content) {
            $write_res = Dj_App_File_Util::write($fixture_file, $fixture_content);
            $this->assertTrue($write_res->isSuccess(), 'Failed to write the asset fixture: ' . $fixture_file);
        }

        // A DIRECTORY where a build would go. Nothing can read or serve one, so a mere
        // existence check picking it up would swap a good file for an unusable answer.
        $dir_trap_dir = $this->content_dir . '/plugins/djebel-test-plugin/assets/dirtrap.min.js';
        $mkdir_res = Dj_App_File_Util::mkdir($dir_trap_dir);
        $this->assertTrue($mkdir_res->isSuccess(), 'Failed to create the directory trap fixture');

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

        // Config is a process-wide singleton too, and setUp's installHooks() reads it — so a
        // declaration left behind here would silently register itself into the NEXT test, one
        // that asked for no assets at all. Dropped through ArrayAccess: it reaches the one
        // section directly, where reading the config out and writing it back would copy every
        // OTHER section to remove this one, and would clear the extra-options data as a
        // side effect of the write.
        $opt_obj = Dj_App_Options::getInstance();
        unset($opt_obj[Dj_App_Assets::CONFIG_SECTION]);
    }

    /**
     * Puts a config-declared asset section in place, the way a parsed site config carries one —
     * the section key naming the asset, the keys under it being add() params.
     *
     * @param array $entries id => params
     * @return void
     */
    public function declareConfigAssets($entries = [])
    {
        $opt_obj = Dj_App_Options::getInstance();
        $opt_obj[Dj_App_Assets::CONFIG_SECTION] = $entries;
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

    // ---------------------------------------------------------------- kind + placement

    public function testKindInferredFromUrlExtension()
    {
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.css', ]);
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('main.css', $head_html);
        $this->assertStringContainsString('main.js', $footer_html);
    }

    public function testKindSniffedFromContent()
    {
        $this->registerAsset([ 'content' => '<style>.sniffed-css { color: red; }</style>', ]);
        $this->registerAsset([ 'content' => '<script>var sniffed_js = 1;</script>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('.sniffed-css', $head_html);
        $this->assertStringContainsString('sniffed_js', $footer_html);
    }

    public function testStyleKeyRendersWrappedInHead()
    {
        $this->registerAsset([ 'style' => '.a { color: red; }', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('<style>.a { color: red; }</style>', $head_html);
    }

    public function testJsKeyRendersWrappedInFooter()
    {
        $this->registerAsset([ 'js' => 'var cfg = {};', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('<script>var cfg = {};</script>', $footer_html);
    }

    public function testPreWrappedContentPassesThroughUntouched()
    {
        $content = '<script type="module">var already_wrapped = 1;</script>';
        $this->registerAsset([ 'content' => $content, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString($content, $footer_html);
        $this->assertStringNotContainsString('<script><script', $footer_html);
    }

    public function testJsForcedToHeadViaPlacement()
    {
        $ctx = [
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/main.js',
            'placement' => Dj_App_Assets::PLACEMENT_HEAD,
        ];

        $this->registerAsset($ctx);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('main.js', $head_html);
        $this->assertEmpty($footer_html);
    }

    public function testInHeadFlagPlacesTheAssetInTheHead()
    {
        $this->registerAsset([ 'js' => 'var probe = 1;', 'in_head' => 1, ]);

        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertStringContainsString('var probe = 1;', $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD));
        $this->assertEmpty($assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER));
    }

    public function testHeadFlagIsAcceptedAsTheShortSpelling()
    {
        $this->registerAsset([ 'js' => 'var probe = 1;', 'head' => 1, ]);

        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertStringContainsString('var probe = 1;', $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD));
    }

    public function testInFooterFlagPlacesTheAssetInTheFooter()
    {
        $this->registerAsset([ 'style' => '.a { color: red; }', 'in_footer' => 1, ]);

        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertStringContainsString('.a { color: red; }', $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER));
        $this->assertEmpty($assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD));
    }

    public function testExplicitPlacementOutranksTheShorthandFlag()
    {
        $this->registerAsset([ 'js' => 'var probe = 1;', 'in_head' => 1, 'placement' => Dj_App_Assets::PLACEMENT_FOOTER, ]);

        $assets_obj = Dj_App_Assets::getInstance();

        $this->assertStringContainsString('var probe = 1;', $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER));
        $this->assertEmpty($assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD));
    }

    /**
     * Asking for both places is a contradiction, not a preference to resolve — picking one
     * quietly is how a caller ends up debugging the half of the page the asset is not on.
     */
    public function testAskingForHeadAndFooterThrows()
    {
        $code = '';

        try {
            $this->registerAsset([ 'js' => 'var probe = 1;', 'in_head' => 1, 'in_footer' => 1, ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.conflicting_placement', $code);
    }

    public function testBodyStartPlacementAcceptsDashedSpelling()
    {
        $this->registerAsset([ 'js' => 'var underscored = 1;', 'placement' => 'body_start', ]);
        $this->registerAsset([ 'js' => 'var dashed = 1;', 'placement' => 'body-start', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $body_start_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_BODY_START);

        $this->assertStringContainsString('var underscored = 1;', $body_start_html);
        $this->assertStringContainsString('var dashed = 1;', $body_start_html);
    }

    public function testUnknownPlacementThrows()
    {
        $code = '';

        try {
            $this->registerAsset([ 'js' => 'var x = 1;', 'placement' => 'nowhere', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.unknown_placement', $code);
    }

    // ---------------------------------------------------------------- urls + versioning

    public function testPluginFileBuildsContentUrl()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('/dj-content/plugins/djebel-test-plugin/assets/main.js', $footer_html);
    }

    public function testThemeFileBuildsContentUrl()
    {
        $this->registerAsset([ 'theme' => 'djebel-test-theme', 'file' => '/style.css', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('/dj-content/themes/djebel-test-theme/style.css', $head_html);
    }

    public function testVersionParamPresentWhenFileResolves()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.js', ]);

        $asset_file = $this->content_dir . '/plugins/djebel-test-plugin/assets/main.js';
        $version = filemtime($asset_file);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('v=abc123', $footer_html);
    }

    public function testVersionParamAbsentForExplicitUrl()
    {
        $this->registerAsset([ 'url' => 'https://cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('src="https://cdn.example.com/x.js"', $footer_html);
        $this->assertStringNotContainsString('v=', $footer_html);
    }

    public function testProtocolRelativeUrlAccepted()
    {
        $this->registerAsset([ 'url' => '//cdn.example.com/x.js', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('src="//cdn.example.com/x.js"', $footer_html);
    }

    /**
     * An extension the system does not handle is a BAD USE, not a runtime miss — so it throws
     * the way a conflicting source or a javascript: url does, rather than coming back as an
     * error Result the caller may ignore.
     */
    public function testUnsupportedExtensionThrows()
    {
        $code = '';

        try {
            Dj_App_Assets::register([ 'url' => 'https://cdn.example.com/x.woff2', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.unknown_kind', $code);
    }

    /**
     * And it is refused BEFORE any file work: an unsupported extension reports the kind, never
     * file_not_found, which is what proves the filesystem was never touched for it.
     */
    public function testUnsupportedExtensionIsRefusedBeforeTheFileIsLookedFor()
    {
        $code = '';

        try {
            Dj_App_Assets::register([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/nope.woff2', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.unknown_kind', $code);
    }

    /**
     * A caller may hand over a number — a build stamp, a counter — and it is cast at the one
     * boundary it enters through, so nothing downstream reads a character off an int. PHP
     * coerces ints for substr() and stripos() but NOT for offset access, so an uncast value
     * reaches the render path and warns there.
     *
     * The stored type is what is asserted, not the absence of a warning: this suite does not
     * fail on warnings, so a rendering check alone would pass whether or not the cast is there.
     */
    public function testNumericSourceIsStoredAsAString()
    {
        $this->registerAsset([ 'js' => 12345, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $item = reset($queue);

        $this->assertIsString($item['content']);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('12345', $footer_html);
        $this->assertStringContainsString('<script', $footer_html);
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

    /**
     * "Not css" is not a way to spell js. A kind this cannot render produces nothing, rather
     * than falling through to the script branch and handing an arbitrary url to a <script> tag.
     *
     * Asserted against the builder directly, because no third kind can be registered yet —
     * which is exactly why the guard has to be here before one can be.
     */
    public function testTagBuilderRefusesAKindItCannotRender()
    {
        $assets_obj = Dj_App_Assets::getInstance();

        $font_item = [ 'kind' => 'font', 'url' => 'https://cdn.example.com/x.woff2', ];
        $this->assertEmpty($assets_obj->buildTagHtml($font_item));

        $inline_item = [ 'kind' => 'font', 'content' => 'not javascript', ];
        $this->assertEmpty($assets_obj->buildTagHtml($inline_item));

        // The two it does render are untouched.
        $css_item = [ 'kind' => Dj_App_Assets::KIND_CSS, 'url' => 'https://cdn.example.com/x.css', ];
        $this->assertStringContainsString('<link', $assets_obj->buildTagHtml($css_item));

        $js_item = [ 'kind' => Dj_App_Assets::KIND_JS, 'url' => 'https://cdn.example.com/x.js', ];
        $this->assertStringContainsString('<script', $assets_obj->buildTagHtml($js_item));
    }

    // ---------------------------------------------------------------- icons

    /**
     * A .ico needs nothing said about it — the extension declares the kind, the kind picks the
     * head, and the tag carries rel="icon" rather than the stylesheet rel every other <link>
     * in this class was hardcoded to.
     */
    public function testIcoIsRecognisedFromItsExtension()
    {
        $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/favicon.ico', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('<link', $head_html);
        $this->assertStringContainsString('rel="icon"', $head_html);
        $this->assertStringContainsString('/assets/favicon.ico', $head_html);
        $this->assertStringNotContainsString('stylesheet', $head_html);

        // Head by default, so it is not sitting at the bottom of the document.
        $this->assertEmpty($assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER));
    }

    /**
     * An icon shipped as .png or .svg cannot be told apart from any other image by its name, so
     * the caller names the kind and the extension is not consulted at all.
     */
    public function testIconKindCarriesAnExtensionThatCannotDeclareItself()
    {
        $this->registerAsset([
            'plugin' => 'djebel-test-plugin',
            'file' => '/assets/icon.png',
            'kind' => Dj_App_Assets::KIND_ICON,
        ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('rel="icon"', $head_html);
        $this->assertStringContainsString('/assets/icon.png', $head_html);
    }

    /**
     * Without the kind, the same .png is still refused — recognising .ico must not have turned
     * the extension check into a guess about images generally.
     */
    public function testPngWithoutAKindIsStillRefused()
    {
        $code = '';

        try {
            Dj_App_Assets::register([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/icon.png', ]);
        } catch (Dj_App_Validation_Exception $e) {
            $code = $e->getErrorCode();
        }

        $this->assertEquals('app.core.assets.unknown_kind', $code);
    }

    /**
     * Nothing can spell an icon as markup, so inline content for one renders nothing rather
     * than being wrapped in whichever tag the fallthrough happened to reach.
     */
    public function testIconHasNoInlineForm()
    {
        $assets_obj = Dj_App_Assets::getInstance();

        $inline_item = [ 'kind' => Dj_App_Assets::KIND_ICON, 'content' => 'not markup', ];
        $this->assertEmpty($assets_obj->buildTagHtml($inline_item));

        // The kinds that DO have one are untouched.
        $css_item = [ 'kind' => Dj_App_Assets::KIND_CSS, 'content' => '.a { color: red; }', ];
        $this->assertStringContainsString('<style', $assets_obj->buildTagHtml($css_item));

        $js_item = [ 'kind' => Dj_App_Assets::KIND_JS, 'content' => 'var a = 1;', ];
        $this->assertStringContainsString('<script', $assets_obj->buildTagHtml($js_item));
    }

    // ---------------------------------------------------------------- minified builds

    /**
     * Every case here drives the decision through the filter rather than the ambient
     * environment. The suite runs wherever it is checked out, and a test whose answer depends
     * on which box it ran on is not asserting the behaviour, it is reporting the machine.
     */
    public function testMinifiedBuildIsPreferredWhenPresent()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/app.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/app.min.js', $footer_html);
            $this->assertStringNotContainsString('/assets/app.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    /**
     * One asset opts out while the rest of the site keeps taking builds — so the flag has to
     * reach only the asset that set it, with min still on everywhere else in the same request.
     */
    public function testSkipMinServesTheNamedFileForThatAssetAlone()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/app.js', 'skip_min' => 1, ]);
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/.min/boxed.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            // Opted out, so its own build is left on disk untouched.
            $this->assertStringContainsString('/assets/app.js', $footer_html);
            $this->assertStringNotContainsString('/assets/app.min.js', $footer_html);

            // The other asset never asked to opt out and still gets its build.
            $this->assertStringContainsString('/assets/.min/boxed.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    public function testSourceIsServedWhenNoBuildExists()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/main.css', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

            $this->assertStringContainsString('/assets/main.css', $head_html);
            $this->assertStringNotContainsString('.min.css', $head_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    public function testBuildIsIgnoredWhenMinifiedAssetsAreOff()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOff']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/app.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/app.js', $footer_html);
            $this->assertStringNotContainsString('/assets/app.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOff']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    /**
     * The site config turns it off where the environment would have turned it on, with no plugin
     * registering a filter to say so.
     *
     * The raw dotted key is cleared first because cfg() memoizes each resolved value under it
     * and reads it BEFORE the conventional uppercase one — an earlier test's answer would
     * otherwise decide this one.
     */
    public function testConfigTurnsMinifiedAssetsOff()
    {
        $cfg_attribs = [ 'override' => 1, ];
        $cleared = Dj_App_Config::cfg('app.core.assets.use_min', '', $cfg_attribs);
        $this->assertEmpty($cleared, 'The memoized config key survived the clear');

        $env_set = Dj_App_Env::set('DJEBEL_APP_CORE_ASSETS_USE_MIN', 0);
        $this->assertTrue($env_set, 'Failed to set the config env var');

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/app.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/app.js', $footer_html);
            $this->assertStringNotContainsString('/assets/app.min.js', $footer_html);
        } finally {
            // null REMOVES the variable, so the setting cannot outlive the test and decide a
            // later one.
            $env_set = Dj_App_Env::set('DJEBEL_APP_CORE_ASSETS_USE_MIN', null);
            $this->assertTrue($env_set, 'The config env var leaked out of the test');

            $cleared = Dj_App_Config::cfg('app.core.assets.use_min', '', $cfg_attribs);
            $this->assertEmpty($cleared, 'The memoized config key leaked out of the test');
        }
    }

    /**
     * A name that already carries the marker is served as it stands. The doubled-suffix file
     * EXISTS in the fixtures, so dropping the guard makes this fail instead of quietly falling
     * back to the same answer for the wrong reason.
     */
    public function testAlreadyBuiltFileIsNotDoubled()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/vendor.min.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/vendor.min.js', $footer_html);
            $this->assertStringNotContainsString('vendor.min.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    public function testAlreadyBuiltFileIsRecognizedWhateverItsCase()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/lib.MIN.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/lib.MIN.js', $footer_html);
            $this->assertStringNotContainsString('lib.MIN.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    /**
     * The filter is a LIVE seam, not a question asked once and remembered. Only the environment
     * and config half is memoized, so a filter registered after the first asset already resolved
     * still decides the next one — and a site free to answer per asset keeps that freedom.
     *
     * The alternating filter says NO, then YES. Consulted once, both assets would take the first
     * answer and the build would never appear.
     */
    public function testFilterIsConsultedForEveryAssetNotOncePerRequest()
    {
        self::$use_min_calls = 0;
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinAlternating']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/app.js', ]);
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/.min/boxed.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertEquals(2, self::$use_min_calls, 'The filter was not consulted once per asset');

            // First asset answered NO, so it keeps its source.
            $this->assertStringContainsString('/assets/app.js', $footer_html);
            $this->assertStringNotContainsString('/assets/app.min.js', $footer_html);

            // Second answered YES, so it takes the build.
            $this->assertStringContainsString('/assets/.min/boxed.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinAlternating']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
            self::$use_min_calls = 0;
        }
    }

    /**
     * Only stylesheets and scripts get built. Nothing else may be probed for a build — that
     * would be a syscall per request spent learning what the extension already said.
     *
     * Asserted against the method rather than through register(), because a font cannot be
     * enqueued yet: resolveKind() refuses the extension before a file is ever resolved. The
     * guard is here for when it can be, so the probe never has to be remembered and removed.
     */
    public function testOnlyStylesheetsAndScriptsGetABuildName()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $assets_obj = Dj_App_Assets::getInstance();

            $this->assertEquals('/assets/app.min.js', $assets_obj->resolveMinFile('/assets/app.js'));
            $this->assertEquals('/assets/app.min.css', $assets_obj->resolveMinFile('/assets/app.css'));

            // Case is the caller's, not disk's.
            $this->assertEquals('/assets/app.min.JS', $assets_obj->resolveMinFile('/assets/app.JS'));

            $this->assertEmpty($assets_obj->resolveMinFile('/assets/font.woff2'));
            $this->assertEmpty($assets_obj->resolveMinFile('/assets/logo.svg'));
            $this->assertEmpty($assets_obj->resolveMinFile('/assets/photo.png'));
            $this->assertEmpty($assets_obj->resolveMinFile('/assets/data.json'));

            // Nothing to mark, and nothing left to mark it against.
            $this->assertEmpty($assets_obj->resolveMinFile('/assets/README'));
            $this->assertEmpty($assets_obj->resolveMinFile(''));

            // Already a build.
            $this->assertEmpty($assets_obj->resolveMinFile('/assets/app.min.js'));

            // The per-asset opt-out is the decision method's job, not the name derivation's.
            $this->assertFalse($assets_obj->checkUseMinified([ 'skip_min' => 1, ]));
            $this->assertTrue($assets_obj->checkUseMinified());
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    /**
     * A directory answers an existence check exactly as a file does, and neither reading nor
     * serving one is possible — so the source has to stand rather than be swapped for it.
     */
    public function testDirectoryStandingWhereTheBuildWouldBeIsNotServed()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/dirtrap.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/dirtrap.js', $footer_html);
            $this->assertStringNotContainsString('/assets/dirtrap.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    /**
     * The marker is read where it would sit — in front of the extension — and never anywhere in
     * the string, so an enclosing directory spelled that way cannot pass a source off as built.
     */
    public function testDirectoryNamedLikeTheMarkerIsNotABuild()
    {
        Dj_App_Hooks::addFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);

        try {
            $this->registerAsset([ 'plugin' => 'djebel-test-plugin', 'file' => '/assets/.min/boxed.js', ]);

            $assets_obj = Dj_App_Assets::getInstance();
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('/assets/.min/boxed.min.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter(Dj_App_Assets::FILTER_USE_MIN, ['Dj_App_Assets_Test', 'filterUseMinOn']);
            $this->assertTrue($removed, 'The use_min filter leaked out of the test');
        }
    }

    // ---------------------------------------------------------------- delivery mode

    public function testNonPublicPluginFileIsInlined()
    {
        $res_obj = Dj_App_Assets::register([ 'plugin' => 'djebel-private-plugin', 'file' => '/assets/hidden.js', ]);
        $this->assertTrue($res_obj->isSuccess());

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);
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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('<script>var via_script = 1;</script>', $footer_html);
    }

    public function testBufferKeyBehavesAsContent()
    {
        $this->registerAsset([ 'buffer' => '<style>.via-buffer {}</style>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('.via-buffer', $head_html);
    }

    public function testDataKeyBehavesAsContent()
    {
        $this->registerAsset([ 'data' => '<style>.via-data {}</style>', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

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
        $head_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_HEAD);

        $this->assertStringContainsString('rel="preload"', $head_html);
        $this->assertStringNotContainsString('rel="stylesheet"', $head_html);
    }

    // ---------------------------------------------------------------- ordering

    public function testLowerPriorityRendersFirst()
    {
        $this->registerAsset([ 'js' => 'var late = 1;', 'priority' => 90, ]);
        $this->registerAsset([ 'js' => 'var early = 1;', 'priority' => 5, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $first_pos = strpos($footer_html, 'var first = 1;');
        $normal_pos = strpos($footer_html, 'var normal = 1;');

        $this->assertLessThan($normal_pos, $first_pos);
    }

    // ---------------------------------------------------------------- config-declared assets

    public function testConfigDeclaredAssetsRegisterWithNoCallerAskingForThem()
    {
        $config_entries = [
            'jquery' => [ 'file' => '/plugins/djebel-test-plugin/assets/main.js', ],
            'theme-css' => [ 'file' => '/themes/djebel-test-theme/style.css', ],
        ];

        $this->declareConfigAssets($config_entries);

        $assets_obj = Dj_App_Assets::getInstance();
        $loaded_cnt = $assets_obj->loadConfiguredAssets();

        $this->assertSame(2, $loaded_cnt);

        $queue = $assets_obj->getQueue();

        // The section key IS the handle — that is what makes a prereq elsewhere able to name it.
        $this->assertArrayHasKey('jquery', $queue);
        $this->assertArrayHasKey('theme-css', $queue);
    }

    /**
     * The ordering guarantee is the whole reason a config entry may carry a prereq: config is
     * read top-to-bottom, so without it a library would render in whatever order someone
     * happened to type the lines in.
     */
    public function testConfigDeclaredPrereqOutranksTheOrderTheyWereDeclaredIn()
    {
        $config_entries = [
            'app' => [ 'js' => 'var app = 1;', 'prereq' => 'jquery', ],
            'jquery' => [ 'js' => 'var jq = 1;', ],
        ];

        $this->declareConfigAssets($config_entries);

        $assets_obj = Dj_App_Assets::getInstance();
        $assets_obj->loadConfiguredAssets();

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $jq_pos = strpos($footer_html, 'var jq = 1;');
        $app_pos = strpos($footer_html, 'var app = 1;');

        $this->assertLessThan($app_pos, $jq_pos);
    }

    /**
     * A mistyped file name is the likely bad line, and it must cost the site that one asset
     * rather than every asset declared after it.
     */
    public function testABadConfigEntryIsSkippedAndTheRestStillLoad()
    {
        $config_entries = [
            'missing' => [ 'file' => '/plugins/djebel-test-plugin/assets/nope.js', ],
            'good' => [ 'file' => '/plugins/djebel-test-plugin/assets/main.js', ],
        ];

        $this->declareConfigAssets($config_entries);

        $assets_obj = Dj_App_Assets::getInstance();
        $loaded_cnt = $assets_obj->loadConfiguredAssets();

        $this->assertSame(1, $loaded_cnt);

        $queue = $assets_obj->getQueue();

        $this->assertArrayHasKey('good', $queue);
        $this->assertArrayNotHasKey('missing', $queue);
    }

    /**
     * A plain "key = value" in the section names no asset. It is skipped rather than treated as
     * a broken one, so the section stays usable for a setting later.
     */
    public function testAScalarConfigEntryIsNotTreatedAsAnAsset()
    {
        $config_entries = [
            'enabled' => '1',
            'good' => [ 'file' => '/plugins/djebel-test-plugin/assets/main.js', ],
        ];

        $this->declareConfigAssets($config_entries);

        $assets_obj = Dj_App_Assets::getInstance();
        $loaded_cnt = $assets_obj->loadConfiguredAssets();

        $this->assertSame(1, $loaded_cnt);

        $queue = $assets_obj->getQueue();

        $this->assertArrayNotHasKey('enabled', $queue);
    }

    public function testNoConfigSectionRegistersNothing()
    {
        $assets_obj = Dj_App_Assets::getInstance();
        $loaded_cnt = $assets_obj->loadConfiguredAssets();

        $this->assertSame(0, $loaded_cnt);

        $queue = $assets_obj->getQueue();

        $this->assertEmpty($queue);
    }

    /**
     * Config is the BASE layer, not the last word — a site declares the library it wants and
     * code can still swap the file behind that same handle.
     */
    public function testRegisteringAConfigIdAgainReplacesTheConfigEntry()
    {
        $config_entries = [
            'jquery' => [ 'js' => 'var jq = 1;', ],
        ];

        $this->declareConfigAssets($config_entries);

        $assets_obj = Dj_App_Assets::getInstance();
        $assets_obj->loadConfiguredAssets();

        $this->registerAsset([ 'js' => 'var jq = 2;', 'id' => 'jquery', ]);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('var jq = 2;', $footer_html);
        $this->assertStringNotContainsString('var jq = 1;', $footer_html);
    }

    // ---------------------------------------------------------------- prerequisites

    public function testAssetRendersAfterItsPrereqEvenWhenRegisteredFirst()
    {
        $this->registerAsset([ 'js' => 'var app = 1;', 'id' => 'app', 'prereq' => 'jquery', ]);
        $this->registerAsset([ 'js' => 'var jq = 1;', 'id' => 'jquery', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $jq_pos = strpos($footer_html, 'var jq = 1;');
        $app_pos = strpos($footer_html, 'var app = 1;');

        $this->assertLessThan($app_pos, $jq_pos);
    }

    /**
     * A prerequisite is a hard constraint and a priority only a preference, so the asset moves
     * even when the priorities say the opposite.
     */
    public function testPrereqBeatsPriority()
    {
        $this->registerAsset([ 'js' => 'var app = 1;', 'id' => 'app', 'prereq' => 'jquery', 'priority' => 1, ]);
        $this->registerAsset([ 'js' => 'var jq = 1;', 'id' => 'jquery', 'priority' => 99, ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $jq_pos = strpos($footer_html, 'var jq = 1;');
        $app_pos = strpos($footer_html, 'var app = 1;');

        $this->assertLessThan($app_pos, $jq_pos);
    }

    public function testPrereqAcceptsAnArrayOfIds()
    {
        $this->registerAsset([ 'js' => 'var c = 1;', 'id' => 'c', 'prereq' => [ 'a', 'b', ], ]);
        $this->registerAsset([ 'js' => 'var b = 1;', 'id' => 'b', ]);
        $this->registerAsset([ 'js' => 'var a = 1;', 'id' => 'a', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $c_pos = strpos($footer_html, 'var c = 1;');

        $this->assertLessThan($c_pos, strpos($footer_html, 'var a = 1;'));
        $this->assertLessThan($c_pos, strpos($footer_html, 'var b = 1;'));
    }

    public function testPrereqAcceptsASeparatedString()
    {
        $this->registerAsset([ 'js' => 'var c = 1;', 'id' => 'c', 'prereq' => 'a, b', ]);
        $this->registerAsset([ 'js' => 'var b = 1;', 'id' => 'b', ]);
        $this->registerAsset([ 'js' => 'var a = 1;', 'id' => 'a', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $c_pos = strpos($footer_html, 'var c = 1;');

        $this->assertLessThan($c_pos, strpos($footer_html, 'var a = 1;'));
        $this->assertLessThan($c_pos, strpos($footer_html, 'var b = 1;'));
    }

    /**
     * A prerequisite naming something nobody registered — or something in another placement — is
     * already satisfied. Waiting for it would drop a working asset over a name that will never
     * arrive.
     */
    public function testPrereqThatWasNeverRegisteredDoesNotBlockTheAsset()
    {
        $this->registerAsset([ 'js' => 'var solo = 1;', 'id' => 'solo', 'prereq' => 'never-registered', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('var solo = 1;', $footer_html);
    }

    /**
     * A cycle is a registration bug, not a reason to drop assets off the page or to spin.
     */
    public function testPrereqCycleStillRendersEveryAsset()
    {
        $this->registerAsset([ 'js' => 'var x = 1;', 'id' => 'x', 'prereq' => 'y', ]);
        $this->registerAsset([ 'js' => 'var y = 1;', 'id' => 'y', 'prereq' => 'x', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $this->assertStringContainsString('var x = 1;', $footer_html);
        $this->assertStringContainsString('var y = 1;', $footer_html);
    }

    /**
     * Prerequisite names run through the same formatter ids do, so a plugin asking for 'jQuery'
     * finds the asset registered as 'jquery' instead of silently waiting forever.
     */
    public function testPrereqNameIsFormattedLikeAnId()
    {
        $this->registerAsset([ 'js' => 'var app = 1;', 'id' => 'app', 'prereq' => 'jQuery', ]);
        $this->registerAsset([ 'js' => 'var jq = 1;', 'id' => 'jquery', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

        $jq_pos = strpos($footer_html, 'var jq = 1;');
        $app_pos = strpos($footer_html, 'var app = 1;');

        $this->assertLessThan($app_pos, $jq_pos);
    }

    // ---------------------------------------------------------------- duplicates

    public function testIdenticalContentEmitsOnce()
    {
        $this->registerAsset([ 'js' => 'var shared = 1;', ]);
        $this->registerAsset([ 'js' => 'var shared = 1;', ]);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();
        $this->assertCount(1, $queue);

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);
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

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);
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

    /**
     * '0' is a usable handle — it survives the formatter untouched — but it is empty() in PHP,
     * so a caller who names it must not have it silently swapped for a content hash they never
     * saw and cannot deregister by.
     */
    public function testZeroIsAUsableAssetId()
    {
        $res_obj = Dj_App_Assets::register([ 'js' => 'var zero_id = 1;', 'id' => 0, ]);

        $this->assertTrue($res_obj->isSuccess());
        $this->assertEquals('0', $res_obj->id);

        $assets_obj = Dj_App_Assets::getInstance();
        $queue = $assets_obj->getQueue();

        $this->assertArrayHasKey('0', $queue);

        // And it round-trips, which is the whole point of naming a handle.
        $remove_res = Dj_App_Assets::deregister('0');
        $this->assertTrue($remove_res->isSuccess());

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

        $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);
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
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

            $this->assertStringContainsString('https://rewritten.example.com/x.js', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.add_params', ['Dj_App_Assets_Test', 'filterAddParamsToCdn']);
            $this->assertTrue($removed, 'The add_params filter leaked out of the test');
        }
    }

    /**
     * Only an EMPTY return vetoes. A listener that rebuilds the item and drops the handle has
     * said nothing about whether the asset should load, so the id resolved before the filter
     * ran stands back in rather than the registration failing in the filter's name.
     */
    public function testItemFilterDroppingTheIdGetsItBack()
    {
        Dj_App_Hooks::addFilter('app.core.assets.filter.item', ['Dj_App_Assets_Test', 'filterItemDropId']);

        try {
            $res_obj = Dj_App_Assets::register([ 'js' => 'var kept_anyway = 1;', ]);

            $this->assertTrue($res_obj->isSuccess());
            $this->assertNotEmpty($res_obj->id);

            $assets_obj = Dj_App_Assets::getInstance();
            $queue = $assets_obj->getQueue();

            $this->assertArrayHasKey($res_obj->id, $queue);

            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);
            $this->assertStringContainsString('var kept_anyway = 1;', $footer_html);
        } finally {
            $removed = Dj_App_Hooks::removeFilter('app.core.assets.filter.item', ['Dj_App_Assets_Test', 'filterItemDropId']);
            $this->assertTrue($removed, 'The item filter leaked out of the test');
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
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
            $footer_html = $assets_obj->buildHtml(Dj_App_Assets::PLACEMENT_FOOTER);

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
    public static $use_min_calls = 0;

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
     * Rebuilds the item without its handle — a listener reshaping an entry, not vetoing it.
     *
     * @param array $cur_val
     * @param array $ctx
     * @return array
     */
    public static function filterItemDropId($cur_val, $ctx = [])
    {
        unset($cur_val['id']);

        return $cur_val;
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

    /**
     * @param bool $cur_val
     * @param array $ctx
     * @return bool
     */
    public static function filterUseMinOn($cur_val, $ctx = [])
    {
        return true;
    }

    /**
     * @param bool $cur_val
     * @param array $ctx
     * @return bool
     */
    public static function filterUseMinOff($cur_val, $ctx = [])
    {
        return false;
    }

    /**
     * Answers NO the first time and YES afterwards, so a seam that is consulted once and one
     * that is consulted per asset produce visibly different pages.
     *
     * @param bool $cur_val
     * @param array $ctx
     * @return bool
     */
    public static function filterUseMinAlternating($cur_val, $ctx = [])
    {
        self::$use_min_calls++;
        $use_min = self::$use_min_calls > 1;

        return $use_min;
    }
}
