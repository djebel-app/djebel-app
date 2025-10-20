<?php

use PHPUnit\Framework\TestCase;

class HTML_Test extends TestCase {

    public function setUp() : void {
    }

    public function tearDown() : void {
    }

    public function testRemoveTagBasic()
    {
        $content = 'Before <div>Content to remove</div> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = 'Before  After';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagCaseInsensitive()
    {
        $content = 'Before <DIV>Content to remove</DIV> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = 'Before  After';
        
        $this->assertEquals($expected, $result);
        
        // Test with mixed case
        $content = 'Before <DiV>Content to remove</dIv> After';
        $result = Djebel_App_HTML::removeTag('DIV', $content);
        $expected = 'Before  After';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagWithAttributes()
    {
        $content = 'Before <div class="test" id="myDiv">Content to remove</div> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = 'Before  After';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagMultiple()
    {
        $content = 'Before <div>First</div> Middle <div>Second</div> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = 'Before  Middle  After';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagNested()
    {
        $content = 'Before <div>Outer <span>nested</span> content</div> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = 'Before  After';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagNoClosingTag()
    {
        $content = 'Before <div>Content without closing tag After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        // Should return original content when no closing tag is found
        $expected = $content;
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagNotFound()
    {
        $content = 'Before <span>Content</span> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        // Should return original content when tag is not found
        $expected = $content;
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagEmptyInputs()
    {
        // Empty tag
        $content = 'Before <div>Content</div> After';
        $result = Djebel_App_HTML::removeTag('', $content);
        $this->assertEquals($content, $result);
        
        // Empty content
        $result = Djebel_App_HTML::removeTag('div', '');
        $this->assertEmpty($result);
        
        // Null content
        $result = Djebel_App_HTML::removeTag('div', null);
        $this->assertEquals(null, $result);
    }

    public function testRemoveTagInvalidTag()
    {
        $content = 'Before <div>Content</div> After';
        
        // Tag with special characters should be sanitized
        $result = Djebel_App_HTML::removeTag('di<v>', $content);
        $expected = 'Before  After'; // Should still work as 'div'
        $this->assertEquals($expected, $result);
        
        // Tag with only special characters should return original content
        $result = Djebel_App_HTML::removeTag('<>', $content);
        $this->assertEquals($content, $result);
    }

    public function testRemoveTagSelfClosing()
    {
        // Self-closing tags should not be affected since we look for opening/closing pairs
        $content = 'Before <img src="test.jpg" /> After';
        $result = Djebel_App_HTML::removeTag('img', $content);
        $expected = $content; // Should remain unchanged
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagDebugNested()
    {
        // Simple nested case first
        $content = '<div>Outer <div>Inner</div> Content</div>';
        $result = Djebel_App_HTML::removeTag('div', $content);
        $expected = '';
        
        $this->assertEquals($expected, $result);
    }

    public function testRemoveTagComplexHTML()
    {
        $content = '
            <html>
                <head><title>Test</title></head>
                <body>
                    <div class="container">
                        <p>Paragraph content</p>
                        <div id="inner">Inner div</div>
                    </div>
                    <footer>Footer content</footer>
                </body>
            </html>
        ';
        
        $result = Djebel_App_HTML::removeTag('div', $content);
        
        // Both div tags should be removed (note: the outer div contains the paragraph, so it gets removed too)
        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringNotContainsString('</div>', $result);
        
        // Other tags should remain
        $this->assertStringContainsString('<html>', $result);
        $this->assertStringContainsString('<footer>Footer content</footer>', $result);
        
        // The paragraph was inside the div, so it should be removed too
        $this->assertStringNotContainsString('<p>Paragraph content</p>', $result);
    }

    public function testRemoveTagSelectiveRemoval()
    {
        // Test that only specific tags are removed, not their siblings
        $content = '
            <html>
                <body>
                    <p>Before div</p>
                    <div class="container">
                        <p>Inside div</p>
                    </div>
                    <p>After div</p>
                </body>
            </html>
        ';
        
        $result = Djebel_App_HTML::removeTag('div', $content);
        
        // Div should be removed
        $this->assertStringNotContainsString('<div', $result);
        $this->assertStringNotContainsString('</div>', $result);
        
        // Paragraphs outside div should remain
        $this->assertStringContainsString('<p>Before div</p>', $result);
        $this->assertStringContainsString('<p>After div</p>', $result);
        
        // Paragraph inside div should be removed
        $this->assertStringNotContainsString('<p>Inside div</p>', $result);
    }

    public function testRemoveTagAvoidsFalsePositives()
    {
        // Test that isValidTagMatch prevents false positive matches
        $content = 'Before <divider>Content</divider> <div>Real div</div> After';
        $result = Djebel_App_HTML::removeTag('div', $content);
        
        // The <divider> tag should remain untouched
        $this->assertStringContainsString('<divider>Content</divider>', $result);
        
        // The real <div> tag should be removed
        $this->assertStringNotContainsString('<div>Real div</div>', $result);
        
        // Final result check
        $expected = 'Before <divider>Content</divider>  After';
        $this->assertEquals($expected, $result);
    }

    // Tests for escAttr() - HTML attribute escaping

    public function testEscAttrBasic()
    {
        $input = 'normal text';
        $result = Djebel_App_HTML::escAttr($input);

        $this->assertEquals('normal text', $result);
    }

    public function testEscAttrDoubleQuotes()
    {
        $input = 'text with "quotes"';
        $result = Djebel_App_HTML::escAttr($input);

        $this->assertEquals('text with &quot;quotes&quot;', $result);
    }

    public function testEscAttrSingleQuotes()
    {
        $input = "text with 'quotes'";
        $result = Djebel_App_HTML::escAttr($input);

        $this->assertEquals('text with &#039;quotes&#039;', $result);
    }

    public function testEscAttrSpecialChars()
    {
        $input = '<script>alert("XSS")</script>';
        $result = Djebel_App_HTML::escAttr($input);

        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $result);
    }

    public function testEscAttrAmpersand()
    {
        $input = 'Tom & Jerry';
        $result = Djebel_App_HTML::escAttr($input);

        $this->assertEquals('Tom &amp; Jerry', $result);
    }

    public function testEscAttrEmpty()
    {
        $this->assertEmpty(Djebel_App_HTML::escAttr(''));
        $this->assertEmpty(Djebel_App_HTML::escAttr(null));
    }

    public function testEscAttrNumeric()
    {
        $result = Djebel_App_HTML::escAttr(123);
        $this->assertEquals('123', $result);

        $result = Djebel_App_HTML::escAttr(0);
        $this->assertEquals('0', $result);
    }

    public function testEscAttrNonScalar()
    {
        $array = [ 'test', ];
        $result = Djebel_App_HTML::escAttr($array);

        $this->assertEmpty($result);
    }

    // Tests for escHtml() - HTML content escaping

    public function testEscHtmlBasic()
    {
        $input = 'normal text';
        $result = Djebel_App_HTML::escHtml($input);

        $this->assertEquals('normal text', $result);
    }

    public function testEscHtmlScriptTag()
    {
        $input = '<script>alert("XSS")</script>';
        $result = Djebel_App_HTML::escHtml($input);

        $this->assertEquals('&lt;script&gt;alert(&quot;XSS&quot;)&lt;/script&gt;', $result);
    }

    public function testEscHtmlHtmlTags()
    {
        $input = '<div class="test">Content</div>';
        $result = Djebel_App_HTML::escHtml($input);

        $this->assertEquals('&lt;div class=&quot;test&quot;&gt;Content&lt;/div&gt;', $result);
    }

    public function testEscHtmlSpecialChars()
    {
        $input = '< > & " \'';
        $result = Djebel_App_HTML::escHtml($input);

        $this->assertEquals('&lt; &gt; &amp; &quot; &#039;', $result);
    }

    public function testEscHtmlEmpty()
    {
        $this->assertEmpty(Djebel_App_HTML::escHtml(''));
        $this->assertEmpty(Djebel_App_HTML::escHtml(null));
    }

    public function testEscHtmlNumeric()
    {
        $result = Djebel_App_HTML::escHtml(456);
        $this->assertEquals('456', $result);

        $result = Djebel_App_HTML::escHtml(0);
        $this->assertEquals('0', $result);
    }

    public function testEscHtmlNonScalar()
    {
        $object = new stdClass();
        $result = Djebel_App_HTML::escHtml($object);

        $this->assertEmpty($result);
    }

    // Tests for escUrl() - URL escaping and validation

    public function testEscUrlHttps()
    {
        $input = 'https://example.com/page';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertEquals('https://example.com/page', $result);
    }

    public function testEscUrlHttp()
    {
        $input = 'http://example.com/page';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertEquals('http://example.com/page', $result);
    }

    public function testEscUrlRelative()
    {
        $input = '/path/to/page';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertEquals('/path/to/page', $result);
    }

    public function testEscUrlWithQueryString()
    {
        $input = 'https://example.com/page?foo=bar&baz=qux';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertEquals('https://example.com/page?foo=bar&amp;baz=qux', $result);
    }

    public function testEscUrlInvalidProtocol()
    {
        $result = Djebel_App_HTML::escUrl('javascript:alert("XSS")');
        $this->assertEmpty($result);

        $result = Djebel_App_HTML::escUrl('data:text/html,<script>alert("XSS")</script>');
        $this->assertEmpty($result);

        $result = Djebel_App_HTML::escUrl('ftp://example.com');
        $this->assertEmpty($result);
    }

    public function testEscUrlEmpty()
    {
        $this->assertEmpty(Djebel_App_HTML::escUrl(''));
        $this->assertEmpty(Djebel_App_HTML::escUrl(null));
    }

    public function testEscUrlNonString()
    {
        $result = Djebel_App_HTML::escUrl(123);
        $this->assertEmpty($result);

        $result = Djebel_App_HTML::escUrl([ 'url', ]);
        $this->assertEmpty($result);
    }

    public function testEscUrlCaseInsensitive()
    {
        $input = 'HTTPS://EXAMPLE.COM/PAGE';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertEquals('HTTPS://EXAMPLE.COM/PAGE', $result);
    }

    public function testEscUrlWithSpecialChars()
    {
        $input = 'https://example.com/page?name=<script>alert("XSS")</script>';
        $result = Djebel_App_HTML::escUrl($input);

        $this->assertStringContainsString('&lt;script&gt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    // Tests for encodeEntities() backward compatibility

    public function testEncodeEntitiesDelegatesToEscHtml()
    {
        $input = '<div>Test & Content</div>';
        $result = Djebel_App_HTML::encodeEntities($input);
        $expected = Djebel_App_HTML::escHtml($input);

        $this->assertEquals($expected, $result);
    }

    // Tests for standalone wrapper functions

    public function testDjEscAttrFunction()
    {
        $input = 'test "value"';
        $result = dj_esc_attr($input);
        $expected = Djebel_App_HTML::escAttr($input);

        $this->assertEquals($expected, $result);
    }

    public function testDjEscHtmlFunction()
    {
        $input = '<script>alert("test")</script>';
        $result = dj_esc_html($input);
        $expected = Djebel_App_HTML::escHtml($input);

        $this->assertEquals($expected, $result);
    }

    public function testDjEscUrlFunction()
    {
        $input = 'https://example.com/page';
        $result = dj_esc_url($input);
        $expected = Djebel_App_HTML::escUrl($input);

        $this->assertEquals($expected, $result);
    }

    public function testDjEscFunction()
    {
        $input = '<div>Content</div>';
        $result = dj_esc($input);
        $expected = dj_esc_html($input);

        $this->assertEquals($expected, $result);
    }

    // Real-world usage tests

    public function testEscAttrRealWorld()
    {
        $user_input = $_GET['name'] ?? 'John <script>alert("XSS")</script>';
        $name = dj_esc_attr($user_input);

        $html = "<input name='username' value='$name' />";

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscHtmlRealWorld()
    {
        $user_comment = 'Great post! <script>steal_cookies()</script>';
        $safe_comment = dj_esc_html($user_comment);

        $html = "<p>$safe_comment</p>";

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscUrlRealWorld()
    {
        $redirect = 'https://example.com/page?next=/dashboard&user=admin';
        $safe_redirect = dj_esc_url($redirect);

        $html = "<a href='$safe_redirect'>Click here</a>";

        $this->assertStringContainsString('https://example.com', $html);
    }

    // Tests for decHtml() - HTML entity decoding

    public function testDecHtmlBasic()
    {
        $input = '&lt;div&gt;Content&lt;/div&gt;';
        $result = Djebel_App_HTML::decHtml($input);

        $this->assertEquals('<div>Content</div>', $result);
    }

    public function testDecHtmlSpecialChars()
    {
        $input = '&lt; &gt; &amp; &quot;';
        $result = Djebel_App_HTML::decHtml($input);

        $this->assertEquals('< > & "', $result);
    }

    public function testDecHtmlScript()
    {
        $input = '&lt;script&gt;alert(&quot;test&quot;)&lt;/script&gt;';
        $result = Djebel_App_HTML::decHtml($input);

        $this->assertEquals('<script>alert("test")</script>', $result);
    }

    public function testDecHtmlNoEntities()
    {
        $input = 'plain text';
        $result = Djebel_App_HTML::decHtml($input);

        $this->assertEquals('plain text', $result);
    }

    public function testDecodeEntitiesDelegatesToDecHtml()
    {
        $input = '&lt;div&gt;Test &amp; Content&lt;/div&gt;';
        $result = Djebel_App_HTML::decodeEntities($input);
        $expected = Djebel_App_HTML::decHtml($input);

        $this->assertEquals($expected, $result);
    }

    // Tests for decode wrapper functions

    public function testDjDecHtmlFunction()
    {
        $input = '&lt;div&gt;Content&lt;/div&gt;';
        $result = dj_dec_html($input);
        $expected = Djebel_App_HTML::decHtml($input);

        $this->assertEquals($expected, $result);
    }

    public function testDjDecFunction()
    {
        $input = '&lt;div&gt;Content&lt;/div&gt;';
        $result = dj_dec($input);
        $expected = dj_dec_html($input);

        $this->assertEquals($expected, $result);
    }

    // Round-trip tests (encode then decode)

    public function testRoundTripEscapeAndDecode()
    {
        $original = '<div class="test">Content & More</div>';

        $escaped = dj_esc_html($original);
        $decoded = dj_dec_html($escaped);

        $this->assertEquals($original, $decoded);
    }

    public function testRoundTripShortFunctions()
    {
        $original = '<script>alert("XSS")</script>';

        $escaped = dj_esc($original);
        $decoded = dj_dec($escaped);

        $this->assertEquals($original, $decoded);
    }
}
