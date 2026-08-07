<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private function sanitize(?string $html): string
    {
        return app(HtmlSanitizer::class)->sanitize($html);
    }

    public function test_null_and_empty_input_return_empty_string(): void
    {
        $this->assertSame('', $this->sanitize(null));
        $this->assertSame('', $this->sanitize(''));
        $this->assertSame('', $this->sanitize('   '));
    }

    public function test_strips_disallowed_tags(): void
    {
        $html = '<p>Hello</p><script>alert("x")</script><style>body{}</style>';
        $result = $this->sanitize($html);

        $this->assertStringNotContainsString('script', $result);
        $this->assertStringNotContainsString('style', $result);
        $this->assertStringContainsString('p', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_keeps_allowed_tags(): void
    {
        $html = '<h2>Title</h2><p>A <strong>bold</strong> and <em>italic</em>.</p><ul><li>one</li></ul>';
        $result = $this->sanitize($html);

        $this->assertStringContainsString('<h2', $result);
        $this->assertStringContainsString('<strong>', $result);
        $this->assertStringContainsString('<em>', $result);
        $this->assertStringContainsString('<ul>', $result);
        $this->assertStringContainsString('<li>', $result);
    }

    public function test_strips_unsafe_attributes_from_allowed_tags(): void
    {
        $html = '<a href="https://example.com" onclick="steal()" style="color:red">link</a>';
        $result = $this->sanitize($html);

        $this->assertStringContainsString('https://example.com', $result);
        $this->assertStringNotContainsString('onclick', $result);
        $this->assertStringNotContainsString('color:red', $result);
    }

    public function test_removes_javascript_href(): void
    {
        $html = '<a href="javascript:alert(1)">bad</a>';
        $result = $this->sanitize($html);

        $this->assertStringNotContainsString('javascript', $result);
    }

    public function test_removes_javascript_src(): void
    {
        $html = '<img src="javascript:void(0)" alt="x">';
        $result = $this->sanitize($html);

        $this->assertStringNotContainsString('javascript', $result);
        $this->assertStringContainsString('<img', $result);
    }

    public function test_allows_relative_and_hash_urls(): void
    {
        $html = '<a href="/menu">relative</a><a href="#top">hash</a><a href="https://example.com">http</a>';
        $result = $this->sanitize($html);

        $this->assertStringContainsString('/menu', $result);
        $this->assertStringContainsString('#top', $result);
        $this->assertStringContainsString('https://example.com', $result);
    }

    public function test_allows_image_attributes(): void
    {
        $html = '<img src="https://cdn.example.com/pic.jpg" alt="photo" width="200" height="100">';
        $result = $this->sanitize($html);

        $this->assertStringContainsString('https://cdn.example.com/pic.jpg', $result);
        $this->assertStringContainsString('photo', $result);
    }

    public function test_preserves_unicode_text(): void
    {
        $html = '<p>Café naïveté 日本</p>';
        $result = html_entity_decode($this->sanitize($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertStringContainsString('Café', $result);
        $this->assertStringContainsString('naïveté', $result);
        $this->assertStringContainsString('日本', $result);
    }

    public function test_strips_nested_script_content(): void
    {
        $html = '<p>safe</p><script>document.write("xss")</script>';
        $result = $this->sanitize($html);

        $this->assertStringNotContainsString('document.write', $result);
        $this->assertStringContainsString('safe', $result);
    }

    public function test_nested_content_under_disallowed_wrapper_is_removed(): void
    {
        $html = '<div><p>wrapped</p></div>';
        $result = $this->sanitize($html);

        $this->assertStringNotContainsString('wrapped', $result);
        $this->assertStringNotContainsString('div', $result);
    }
}
