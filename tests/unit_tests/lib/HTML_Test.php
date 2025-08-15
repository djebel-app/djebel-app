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
        $this->assertEquals('', $result);
        
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
}
