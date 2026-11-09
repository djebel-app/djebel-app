<?php

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Dj_App_Cache class — per-source-file home for all its method tests.
 */
class Dj_App_Cache_Test extends TestCase
{
    private $backup_cache_method = false;

    protected function setUp(): void
    {
        $this->backup_cache_method = getenv('DJEBEL_APP_CORE_CACHE_METHOD');
        $this->setCacheMethod(null);
    }

    protected function tearDown(): void
    {
        // false means the variable was absent, and null is how set() removes one.
        $cache_method = $this->backup_cache_method === false ? null : $this->backup_cache_method;
        $this->setCacheMethod($cache_method);
    }

    /**
     * Sets the method the way a site's .env does. cfg() remembers what it resolved under the dotted
     * key and reads that first, so the remembered value is cleared through cfg's own override.
     *
     * @param string|null $cache_method null removes the variable
     */
    public function setCacheMethod($cache_method)
    {
        Dj_App_Env::set('DJEBEL_APP_CORE_CACHE_METHOD', $cache_method);

        $cfg_attribs = [ 'override' => 1, ];
        Dj_App_Config::cfg('app.core.cache_method', '', $cfg_attribs);
    }

    /**
     * The listener the filter test registers.
     *
     * @return string
     */
    public function filterCacheMethodToJson()
    {
        return Dj_App_Cache::METHOD_JSON;
    }

    /**
     * The listener the params test registers: JSON for one plugin's entries, nothing for the rest.
     *
     * @param string $cache_method
     * @param array $ctx
     * @return string
     */
    public function filterCacheMethodForOnePlugin($cache_method, $ctx = [])
    {
        if (empty($ctx['params']['plugin']) || ($ctx['params']['plugin'] != 'plugin_on_json')) {
            return $cache_method;
        }

        return Dj_App_Cache::METHOD_JSON;
    }

    /**
     * Nothing configured means PHP serialize, so a site that never heard of the setting keeps the
     * cache files it has.
     */
    public function testGetCacheMethodDefaultsToPhpSerialize()
    {
        $cache_method = Dj_App_Cache::getCacheMethod();

        $this->assertSame(Dj_App_Cache::METHOD_PHP_SERIALIZE, $cache_method);
    }

    /**
     * The env var picks the method, forgiven the case, spaces and punctuation a hand-edited .env
     * tends to carry. A spelling it can't forgive throws instead of falling back to the default,
     * so php_serialize from a dashed spelling means the spelling was read.
     */
    public function testGetCacheMethodReadsTheEnvVar()
    {
        $spellings = [
            '  JSON ' => Dj_App_Cache::METHOD_JSON,
            'PHP-Serialize' => Dj_App_Cache::METHOD_PHP_SERIALIZE,
        ];

        foreach ($spellings as $spelling => $expected_method) {
            $this->setCacheMethod($spelling);

            $cache_method = Dj_App_Cache::getCacheMethod();

            $this->assertSame($expected_method, $cache_method, "the env var spelled '{$spelling}'");
        }
    }

    /**
     * The site's [app] cache_method wins over the env var, which only speaks when the site sets
     * nothing. The options go back as they were, so no later test reads the site's json.
     */
    public function testGetCacheMethodPrefersTheSiteSetting()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);
        $options_obj = Dj_App_Options::getInstance();

        try {
            $options_data = $options_obj->toArray();
            $site_options_data = $options_data;
            $site_options_data['app']['cache_method'] = Dj_App_Cache::METHOD_JSON;
            $options_obj->setData($site_options_data);

            $cache_method = Dj_App_Cache::getCacheMethod();
        } finally {
            $options_obj->setData($options_data);
        }

        $this->assertSame(Dj_App_Cache::METHOD_JSON, $cache_method);
    }

    /**
     * A listener on app.cache.method gets the last word over the method the site set, and comes
     * off again, so no later test runs under it.
     */
    public function testGetCacheMethodLetsAFilterDecide()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);
        Dj_App_Hooks::addFilter('app.cache.method', [ $this, 'filterCacheMethodToJson' ]);

        try {
            $cache_method = Dj_App_Cache::getCacheMethod();
        } finally {
            $is_removed = Dj_App_Hooks::removeFilter('app.cache.method', [ $this, 'filterCacheMethodToJson' ]);
        }

        $this->assertSame(Dj_App_Cache::METHOD_JSON, $cache_method);
        $this->assertTrue($is_removed, 'the test listener came off');
    }

    /**
     * A name that is neither method throws, so a typo can't leave a site on PHP serialize while
     * its owner believes JSON is on.
     */
    public function testGetCacheMethodThrowsOnUnknownName()
    {
        $this->setCacheMethod('jsn');

        $error_code = '';

        try {
            $cache_method = Dj_App_Cache::getCacheMethod();
        } catch (Dj_App_Exception $e) {
            $error_code = $e->getErrorCode();
        }

        $this->assertSame('app.core.cache.unknown_method', $error_code);
    }

    /**
     * One entry can ask for JSON on a site that doesn't use it, forgiven the same spellings a
     * site's setting is.
     */
    public function testGetCacheMethodLetsAnEntryAskForJson()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);

        $entry_params = [ 'cache_method' => '  JSON ', ];
        $cache_method = Dj_App_Cache::getCacheMethod($entry_params);

        $this->assertSame(Dj_App_Cache::METHOD_JSON, $cache_method);
    }

    /**
     * An entry can't take a site off JSON in any spelling: a site set to JSON must never reach
     * PHP's unserialize for what sits in its cache dir.
     */
    public function testGetCacheMethodKeepsAJsonSiteOnJson()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_JSON);

        $spellings = [ Dj_App_Cache::METHOD_PHP_SERIALIZE, 'PHP-Serialize', ];

        foreach ($spellings as $spelling) {
            $entry_params = [ 'cache_method' => $spelling, ];
            $cache_method = Dj_App_Cache::getCacheMethod($entry_params);

            $this->assertSame(Dj_App_Cache::METHOD_JSON, $cache_method, "an entry asking for '{$spelling}'");
        }
    }

    /**
     * A typo in an entry's key throws on every site, the same as one in the site's setting. A key
     * that formats to nothing throws too, instead of reading as no method and leaving JSON.
     */
    public function testGetCacheMethodThrowsOnAnUnknownEntryMethod()
    {
        $site_methods = [ Dj_App_Cache::METHOD_PHP_SERIALIZE, Dj_App_Cache::METHOD_JSON, ];
        $bad_names = [ 'jsn', '---', ];

        foreach ($site_methods as $site_method) {
            $this->setCacheMethod($site_method);

            foreach ($bad_names as $bad_name) {
                $error_code = '';
                $entry_params = [ 'cache_method' => $bad_name, ];

                try {
                    $cache_method = Dj_App_Cache::getCacheMethod($entry_params);
                } catch (Dj_App_Exception $e) {
                    $error_code = $e->getErrorCode();
                }

                $this->assertSame('app.core.cache.unknown_method', $error_code, "'{$bad_name}' on a {$site_method} site");
            }
        }
    }

    /**
     * A listener is told the params of the entry, so a site can pick the method per plugin, and an
     * entry it has no opinion on keeps the site's method.
     */
    public function testGetCacheMethodGivesTheFilterTheParams()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);
        Dj_App_Hooks::addFilter('app.cache.method', [ $this, 'filterCacheMethodForOnePlugin' ]);

        try {
            $plugin_params = [ 'plugin' => 'plugin_on_json', ];
            $plugin_method = Dj_App_Cache::getCacheMethod($plugin_params);
            $other_method = Dj_App_Cache::getCacheMethod();
        } finally {
            $is_removed = Dj_App_Hooks::removeFilter('app.cache.method', [ $this, 'filterCacheMethodForOnePlugin' ]);
        }

        $this->assertSame(Dj_App_Cache::METHOD_JSON, $plugin_method);
        $this->assertSame(Dj_App_Cache::METHOD_PHP_SERIALIZE, $other_method);
        $this->assertTrue($is_removed, 'the test listener came off');
    }

    /**
     * An entry written as JSON on a PHP serialize site comes back through a get() that names no
     * method. The write is the thing under test, so this test writes a file.
     */
    public function testSetWritesOneEntryAsJsonOnAPhpSerializeSite()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);

        $data = [ 'title' => 'Здравей', ];
        $cache_params = [ 'plugin' => 'dj-app-cache-test', ];
        $json_params = $cache_params;
        $json_params['cache_method'] = Dj_App_Cache::METHOD_JSON;

        $set_res = Dj_App_Cache::set('one_entry_json', $data, $json_params);

        $this->assertTrue($set_res->isSuccess(), "set() failed: {$set_res->msg}");

        $cache_dir = Dj_App_Cache::getCacheDir($cache_params);
        $read_res = Dj_App_File_Util::read($cache_dir . '/one_entry_json.php');

        $this->assertStringContainsString('// ::dj_end::{"meta":', $read_res->output);

        $cached_data = Dj_App_Cache::get('one_entry_json', $cache_params);

        $this->assertSame($data, $cached_data);
    }

    /**
     * A value written under each method comes back identical, Cyrillic, floats, booleans, a NUL
     * byte and nesting included, from a file in that method's format. The NUL byte sits inside a
     * serialized string, where a trim that strips every NUL would break the read. The write is the
     * thing under test, so this test writes a file.
     */
    public function testSetGetRoundTripsInBothMethods()
    {
        $data = [
            'title' => 'Здравей, свят',
            'url' => '/blog/hello-dj-abc123def456',
            'sort_order' => 3,
            'rating' => 1.0,
            'tags' => [ 'php', 'cache', ],
            'is_draft' => false,
            'binary_note' => "a\0b",
        ];

        $cache_params = [ 'plugin' => 'dj-app-cache-test', ];
        $cache_dir = Dj_App_Cache::getCacheDir($cache_params);

        $file_starts = [
            Dj_App_Cache::METHOD_PHP_SERIALIZE => '// ::dj_end::a:2:{s:4:"meta";',
            Dj_App_Cache::METHOD_JSON => '// ::dj_end::{"meta":',
        ];

        foreach ($file_starts as $cache_method => $file_start) {
            $this->setCacheMethod($cache_method);
            $cache_key = 'round_trip_' . $cache_method;

            $set_res = Dj_App_Cache::set($cache_key, $data, $cache_params);

            $this->assertTrue($set_res->isSuccess(), "set() failed under {$cache_method}: {$set_res->msg}");

            $read_res = Dj_App_File_Util::read($cache_dir . '/' . $cache_key . '.php');

            $this->assertStringContainsString($file_start, $read_res->output, "{$cache_method} file format");

            $cached_data = Dj_App_Cache::get($cache_key, $cache_params);

            $this->assertSame($data, $cached_data, "{$cache_method} round trip");
        }
    }

    /**
     * Under JSON a value it can't hold as written is not cached at all: set() reports why and get()
     * finds nothing, so no entry comes back with a byte replaced or a number turned into a string.
     * A byte that isn't valid UTF-8 is refused in a key as much as in a value, since a replaced one
     * in a key or a file name points the caller at something that was never stored. Each key is
     * new on every run, so no file from an earlier run can answer for it.
     */
    public function testJsonCacheRefusesAValueItCannotHold()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_JSON);

        $data_cases = [
            'infinity' => [ 'rating' => INF, 'sort_order' => 5, ],
            'not_a_number' => [ 'rating' => NAN, 'sort_order' => 5, ],
            'invalid_byte_in_value' => [ 'file' => "/blog/Здравей_\xC2_свят.md", 'sort_order' => 5, ],
            'invalid_byte_in_key' => [ "caf\xE9" => 'Чист текст', 'sort_order' => 5, ],
        ];

        $cache_params = [ 'plugin' => 'dj-app-cache-test', ];

        foreach ($data_cases as $case_name => $data) {
            $cache_key = $case_name . '_' . uniqid();
            $set_res = Dj_App_Cache::set($cache_key, $data, $cache_params);

            $this->assertTrue($set_res->isError(), "set() reports the {$case_name} case");
            $this->assertNotEmpty($set_res->error_detail, "the {$case_name} case says why");

            $cached_data = Dj_App_Cache::get($cache_key, $cache_params);

            $this->assertNull($cached_data, "get() finds nothing for the {$case_name} case");
        }
    }

    /**
     * Data JSON can't hold leaves a line in the app log with the reason, since the page then
     * builds that data on every request and nothing else says why. Only what the write appends is
     * read back, so the test creates and removes no file.
     */
    public function testWriteLogsDataItCannotEncode()
    {
        $this->setCacheMethod(Dj_App_Cache::METHOD_JSON);

        $log_file = Dj_App_Log::file();
        $log_size_before = is_file($log_file) ? filesize($log_file) : 0;

        $data = [ 'rating' => INF, ];
        $cache_params = [ 'plugin' => 'dj-app-cache-test', ];
        $set_res = Dj_App_Cache::set('cannot_encode_' . uniqid(), $data, $cache_params);

        $this->assertTrue($set_res->isError(), 'set() reports the value it cannot encode');

        $read_res = Dj_App_File_Util::readPartially($log_file, 4096, $log_size_before);

        $this->assertStringContainsString('Cache data could not be encoded', $read_res->output);
        $this->assertStringContainsString('Inf and NaN cannot be JSON encoded', $read_res->output);
    }

    /**
     * A ttl that isn't a number of seconds is refused at the write, so it can never become a file
     * that no read can tell the age of.
     */
    public function testSetRefusesATtlThatIsNotANumber()
    {
        $cache_params = [ 'plugin' => 'dj-app-cache-test', 'ttl' => '4 hours', ];
        $cache_key = 'bad_ttl_' . uniqid();
        $data = [ 'title' => 'hello', ];

        $set_res = Dj_App_Cache::set($cache_key, $data, $cache_params);

        $this->assertTrue($set_res->isError(), 'set() reports a ttl that is not a number');

        $cached_data = Dj_App_Cache::get($cache_key, $cache_params);

        $this->assertNull($cached_data);
    }

    /**
     * A cache file holding an object in its data reads as a miss, and the object's class never
     * wakes up: its __wakeup throws, so a wake would fail this test with that exception.
     */
    public function testReadRefusesAnObjectInTheData()
    {
        $cache_file = DJEBEL_APP_TEST_DATA_DIR . '/cache/object_in_data.php';

        $cached_data = Dj_App_Cache::read($cache_file);

        $this->assertNull($cached_data);
    }

    /**
     * A file that decodes but is not the meta + data array write() makes reads as a miss, instead
     * of reaching the caller with no TTL ever checked.
     */
    public function testReadRefusesAFileWriteDidNotMake()
    {
        $cache_file = DJEBEL_APP_TEST_DATA_DIR . '/cache/plain_array.php';

        $cached_data = Dj_App_Cache::read($cache_file);

        $this->assertNull($cached_data);
    }

    /**
     * A file whose created_at isn't a number reads as a miss, instead of failing the expiry sum on
     * every request that reads it.
     */
    public function testReadRefusesAMetaThatIsNotANumber()
    {
        $cache_file = DJEBEL_APP_TEST_DATA_DIR . '/cache/meta_not_a_number.php';

        $cached_data = Dj_App_Cache::read($cache_file);

        $this->assertNull($cached_data);
    }

    /**
     * The file says how it was written: JSON reads on any site, since one entry may be JSON on a
     * site that isn't, and PHP serialize only on a site set to php_serialize, so a site set to JSON
     * never runs unserialize().
     */
    public function testReadPicksTheDecoderFromTheFile()
    {
        $serialize_file = DJEBEL_APP_TEST_DATA_DIR . '/cache/php_serialize.php';
        $json_file = DJEBEL_APP_TEST_DATA_DIR . '/cache/json.php';
        $expected_data = [ 'title' => 'hello', ];

        $this->setCacheMethod(Dj_App_Cache::METHOD_PHP_SERIALIZE);

        $serialize_read = Dj_App_Cache::read($serialize_file);

        $this->assertSame($expected_data, $serialize_read, 'php_serialize reads its own file');

        $json_read = Dj_App_Cache::read($json_file);

        $this->assertSame($expected_data, $json_read, 'php_serialize also reads a JSON file');

        $this->setCacheMethod(Dj_App_Cache::METHOD_JSON);

        $json_read = Dj_App_Cache::read($json_file);

        $this->assertSame($expected_data, $json_read, 'json reads its own file');

        $serialize_read = Dj_App_Cache::read($serialize_file);

        $this->assertNull($serialize_read, 'json leaves a PHP serialize file alone');
    }
}

/**
 * Wakes only if unserialize() builds it, and then fails the test that read it.
 */
class Dj_App_Cache_Test_Wakeup_Probe
{
    /**
     * @throws RuntimeException always
     */
    public function __wakeup()
    {
        throw new RuntimeException('Dj_App_Cache_Test_Wakeup_Probe was built by unserialize()');
    }
}
