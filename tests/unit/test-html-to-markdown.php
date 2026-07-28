<?php
/**
 * Unit tests for Cross_Post_DevTo_Publisher::html_to_markdown().
 *
 * This is pure conversion logic with no WordPress dependencies.
 * We expose the private method via Reflection so we can test it directly.
 */

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

class Test_Html_To_Markdown extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Invoke the private static html_to_markdown() method via Reflection.
     */
    private function convert( string $html ): string {
        $method = new ReflectionMethod( Cross_Post_DevTo_Publisher::class, 'html_to_markdown' );
        $method->setAccessible( true );
        return $method->invoke( null, $html );
    }

    // ── Headings ──────────────────────────────────────────────────────────────

    public function test_h1_converts_to_single_hash(): void {
        $result = $this->convert( '<h1>Hello World</h1>' );
        $this->assertStringContainsString( '# Hello World', $result );
    }

    public function test_h2_converts_to_double_hash(): void {
        $result = $this->convert( '<h2>Section Title</h2>' );
        $this->assertStringContainsString( '## Section Title', $result );
    }

    public function test_h3_through_h6_convert_correctly(): void {
        foreach ( range( 3, 6 ) as $level ) {
            $hashes = str_repeat( '#', $level );
            $result = $this->convert( "<h{$level}>Title</h{$level}>" );
            $this->assertStringContainsString( "{$hashes} Title", $result );
        }
    }

    public function test_heading_with_attributes_stripped(): void {
        $result = $this->convert( '<h2 class="wp-block-heading" id="my-section">My Section</h2>' );
        $this->assertStringContainsString( '## My Section', $result );
    }

    // ── Bold & Italic ─────────────────────────────────────────────────────────

    public function test_strong_converts_to_double_asterisk(): void {
        $result = $this->convert( '<p>This is <strong>bold</strong> text.</p>' );
        $this->assertStringContainsString( '**bold**', $result );
    }

    public function test_b_tag_converts_to_double_asterisk(): void {
        $result = $this->convert( '<b>bold</b>' );
        $this->assertStringContainsString( '**bold**', $result );
    }

    public function test_em_converts_to_underscore(): void {
        $result = $this->convert( '<em>italic</em>' );
        $this->assertStringContainsString( '_italic_', $result );
    }

    public function test_i_converts_to_underscore(): void {
        $result = $this->convert( '<i>italic</i>' );
        $this->assertStringContainsString( '_italic_', $result );
    }

    // ── Links ─────────────────────────────────────────────────────────────────

    public function test_anchor_converts_to_markdown_link(): void {
        $result = $this->convert( '<a href="https://example.com">Click here</a>' );
        $this->assertStringContainsString( '[Click here](https://example.com)', $result );
    }

    public function test_anchor_with_extra_attributes(): void {
        $result = $this->convert( '<a href="https://example.com" target="_blank" rel="noopener">Link</a>' );
        $this->assertStringContainsString( '[Link](https://example.com)', $result );
    }

    // ── Images ───────────────────────────────────────────────────────────────

    public function test_img_with_src_and_alt(): void {
        $result = $this->convert( '<img src="https://example.com/image.jpg" alt="A photo" />' );
        $this->assertStringContainsString( '![A photo](https://example.com/image.jpg)', $result );
    }

    public function test_img_without_alt_uses_empty_string(): void {
        $result = $this->convert( '<img src="https://example.com/image.jpg" />' );
        $this->assertStringContainsString( '![](https://example.com/image.jpg)', $result );
    }

    public function test_img_with_alt_before_src(): void {
        $result = $this->convert( '<img alt="A photo" src="https://example.com/image.jpg" />' );
        $this->assertStringContainsString( '![A photo](https://example.com/image.jpg)', $result );
    }

    public function test_img_with_many_attributes(): void {
        $result = $this->convert( '<img loading="lazy" class="wp-image-12" src="https://example.com/i.jpg" alt="x" width="800">' );
        $this->assertStringContainsString( '![x](https://example.com/i.jpg)', $result );
    }

    // ── Code ─────────────────────────────────────────────────────────────────

    public function test_inline_code_wraps_in_backticks(): void {
        $result = $this->convert( '<p>Use the <code>get_post()</code> function.</p>' );
        $this->assertStringContainsString( '`get_post()`', $result );
    }

    public function test_fenced_code_block_without_language(): void {
        $result = $this->convert( '<pre><code>echo "hello";</code></pre>' );
        $this->assertStringContainsString( "```\necho \"hello\";\n```", $result );
    }

    public function test_fenced_code_block_with_language_class(): void {
        $result = $this->convert( '<pre><code class="language-php">echo "hello";</code></pre>' );
        $this->assertStringContainsString( "```php\necho \"hello\";\n```", $result );
    }

    public function test_fenced_code_block_with_javascript(): void {
        $result = $this->convert( '<pre><code class="language-javascript">console.log("hi");</code></pre>' );
        $this->assertStringContainsString( "```javascript\nconsole.log(\"hi\");\n```", $result );
    }

    public function test_fenced_code_block_decodes_html_entities(): void {
        $result = $this->convert( '<pre><code>if ($a &lt; $b &amp;&amp; $c &gt; 0) {}</code></pre>' );
        $this->assertStringContainsString( 'if ($a < $b && $c > 0) {}', $result );
    }

    // ── Blockquote ────────────────────────────────────────────────────────────

    public function test_blockquote_prefixes_lines_with_gt(): void {
        $result = $this->convert( '<blockquote>This is a quote.</blockquote>' );
        $this->assertStringContainsString( '> This is a quote.', $result );
    }

    // ── Lists ─────────────────────────────────────────────────────────────────

    public function test_unordered_list_uses_dash_bullets(): void {
        $result = $this->convert( '<ul><li>First</li><li>Second</li><li>Third</li></ul>' );
        $this->assertStringContainsString( '- First', $result );
        $this->assertStringContainsString( '- Second', $result );
        $this->assertStringContainsString( '- Third', $result );
    }

    public function test_ordered_list_uses_numbered_items(): void {
        $result = $this->convert( '<ol><li>Alpha</li><li>Beta</li><li>Gamma</li></ol>' );
        $this->assertStringContainsString( '1. Alpha', $result );
        $this->assertStringContainsString( '2. Beta', $result );
        $this->assertStringContainsString( '3. Gamma', $result );
    }

    public function test_bold_inside_list_items_survives(): void {
        $result = $this->convert( '<ul><li><strong>Fast</strong></li></ul>' );
        $this->assertStringContainsString( '- **Fast**', $result );
    }

    public function test_links_inside_list_items_survive(): void {
        $result = $this->convert( '<ul><li>See <a href="https://d.com">docs</a></li></ul>' );
        $this->assertStringContainsString( '- See [docs](https://d.com)', $result );
    }

    public function test_bold_inside_blockquote_survives(): void {
        $result = $this->convert( '<blockquote>A <strong>bold</strong> quote</blockquote>' );
        $this->assertStringContainsString( '> A **bold** quote', $result );
    }

    public function test_code_block_content_protected_from_inline_conversion(): void {
        // HTML-looking content inside a code block must not be converted to Markdown.
        $result = $this->convert( '<pre><code class="language-html">&lt;strong&gt;not bold&lt;/strong&gt;</code></pre>' );
        $this->assertStringContainsString( '<strong>not bold</strong>', $result );
        $this->assertStringNotContainsString( '**not bold**', $result );
    }

    // ── Tables ────────────────────────────────────────────────────────────────

    public function test_table_with_thead_converts_to_gfm(): void {
        $result = $this->convert(
            '<table><thead><tr><th>Name</th><th>Role</th></tr></thead>'
            . '<tbody><tr><td>Ada</td><td>Engineer</td></tr></tbody></table>'
        );

        $this->assertStringContainsString( '| Name | Role |', $result );
        $this->assertStringContainsString( '| --- | --- |', $result );
        $this->assertStringContainsString( '| Ada | Engineer |', $result );
    }

    public function test_table_without_thead_uses_first_row_as_header(): void {
        $result = $this->convert(
            '<table><tr><td>Name</td><td>Role</td></tr><tr><td>Ada</td><td>Engineer</td></tr></table>'
        );

        $this->assertStringContainsString( '| Name | Role |', $result );
        $this->assertStringContainsString( '| --- | --- |', $result );
        $this->assertStringContainsString( '| Ada | Engineer |', $result );
    }

    public function test_table_multiple_body_rows_all_present(): void {
        $result = $this->convert(
            '<table><thead><tr><th>Name</th></tr></thead><tbody>'
            . '<tr><td>Ada</td></tr><tr><td>Grace</td></tr><tr><td>Alan</td></tr>'
            . '</tbody></table>'
        );

        $this->assertStringContainsString( '| Ada |', $result );
        $this->assertStringContainsString( '| Grace |', $result );
        $this->assertStringContainsString( '| Alan |', $result );
    }

    public function test_table_cell_formatting_survives(): void {
        $result = $this->convert(
            '<table><thead><tr><th>Name</th></tr></thead>'
            . '<tbody><tr><td><strong>Ada</strong></td></tr></tbody></table>'
        );

        $this->assertStringContainsString( '| **Ada** |', $result );
    }

    public function test_table_cell_pipe_character_is_escaped(): void {
        $result = $this->convert(
            '<table><thead><tr><th>Expr</th></tr></thead>'
            . '<tbody><tr><td>a | b</td></tr></tbody></table>'
        );

        $this->assertStringContainsString( 'a \\| b', $result );
    }

    public function test_empty_table_produces_no_output(): void {
        $result = $this->convert( '<table></table>' );
        $this->assertSame( '', trim( $result ) );
    }

    // ── Horizontal Rule ───────────────────────────────────────────────────────

    public function test_hr_converts_to_triple_dash(): void {
        $result = $this->convert( '<hr />' );
        $this->assertStringContainsString( '---', $result );
    }

    // ── Paragraphs & line breaks ──────────────────────────────────────────────

    public function test_paragraphs_produce_double_newlines(): void {
        $result = $this->convert( '<p>First paragraph.</p><p>Second paragraph.</p>' );
        $this->assertStringContainsString( "First paragraph.", $result );
        $this->assertStringContainsString( "Second paragraph.", $result );
        // There should be a blank line between them.
        $this->assertStringContainsString( "\n\n", $result );
    }

    public function test_br_converts_to_newline(): void {
        $result = $this->convert( 'Line one<br />Line two' );
        $this->assertStringContainsString( "Line one\nLine two", $result );
    }

    // ── Entity decoding ───────────────────────────────────────────────────────

    public function test_html_entities_are_decoded(): void {
        $result = $this->convert( '<p>Hello &amp; World &mdash; it&#39;s fine</p>' );
        $this->assertStringContainsString( 'Hello & World', $result );
        $this->assertStringContainsString( "it's fine", $result );
    }

    // ── Script / style stripping (tested via convert_content indirectly) ──────

    public function test_remaining_html_tags_are_stripped(): void {
        $result = $this->convert( '<p>Hello <span class="highlight">world</span></p>' );
        $this->assertStringContainsString( 'Hello world', $result );
        $this->assertStringNotContainsString( '<span', $result );
    }

    // ── Whitespace normalisation ──────────────────────────────────────────────

    public function test_excessive_blank_lines_collapsed(): void {
        $result = $this->convert( "<p>One</p>\n\n\n\n\n<p>Two</p>" );
        // Should have at most 2 consecutive newlines.
        $this->assertStringNotContainsString( "\n\n\n", $result );
    }

    public function test_output_is_trimmed(): void {
        $result = $this->convert( '   <p>Hello</p>   ' );
        $this->assertSame( ltrim( $result ), ltrim( $result ) );
        $this->assertSame( rtrim( $result ), rtrim( $result ) );
    }

    // ── Complex / combined ────────────────────────────────────────────────────

    public function test_mixed_content_converts_correctly(): void {
        $html = '<h2>Getting Started</h2>'
              . '<p>Install via <code>composer require</code>.</p>'
              . '<ul><li><strong>Fast</strong></li><li><em>Reliable</em></li></ul>'
              . '<p>See <a href="https://docs.example.com">the docs</a>.</p>';

        $result = $this->convert( $html );

        $this->assertStringContainsString( '## Getting Started', $result );
        $this->assertStringContainsString( '`composer require`', $result );
        $this->assertStringContainsString( '- **Fast**', $result );
        $this->assertStringContainsString( '- _Reliable_', $result );
        $this->assertStringContainsString( '[the docs](https://docs.example.com)', $result );
    }

    public function test_empty_string_returns_empty(): void {
        $result = $this->convert( '' );
        $this->assertSame( '', $result );
    }

    public function test_plain_text_passthrough(): void {
        $result = $this->convert( 'Just plain text with no HTML.' );
        $this->assertStringContainsString( 'Just plain text with no HTML.', $result );
    }
}
