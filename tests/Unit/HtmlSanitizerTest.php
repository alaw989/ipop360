<?php

namespace Tests\Unit;

use App\Services\HtmlSanitizer;
use Tests\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new HtmlSanitizer;
    }

    public function test_null_and_blank_inputs_return_empty_string(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize(''));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function test_keeps_allowed_tags_and_text(): void
    {
        $out = $this->sanitizer->sanitize('<p>Hi <strong>there</strong> and <em>more</em></p>');

        $this->assertEqualsWithDelta(1, substr_count($out, '<p>'), 0, 'keeps allowed paragraph tag');
        $this->assertStringContainsString('<strong>there</strong>', $out);
        $this->assertStringContainsString('<em>more</em>', $out);
        $this->assertStringContainsString('Hi there and more', trim(strip_tags($out)));
    }

    public function test_removes_disallowed_tags_with_their_subtree(): void
    {
        $out = $this->sanitizer->sanitize('<p>keep</p><script>alert(1)</script><h1>gone</h1><li>dropped</li>');

        $this->assertStringContainsString('<p>keep</p>', $out);
        $this->assertStringContainsString('<li>dropped</li>', $out, 'li is an allowed tag and kept itself');
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('h1', $out);
        $this->assertStringNotContainsString('alert', $out, 'script body pruned with its tag');
    }

    public function test_strips_disallowed_attributes_from_links(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="https://x.com" onclick="steal()" target="_blank" rel="nofollow">l</a>'
        );

        $this->assertStringContainsString('href="https://x.com"', $out);
        $this->assertStringContainsString('target="_blank"', $out);
        $this->assertStringContainsString('rel="nofollow"', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('steal()', $out);
    }

    public function test_strips_disallowed_attributes_from_images(): void
    {
        $out = $this->sanitizer->sanitize(
            '<img src="https://x/i.png" alt="a" onerror="x()" width="100">'
        );

        $this->assertStringContainsString('src="https://x/i.png"', $out);
        $this->assertStringContainsString('alt="a"', $out);
        $this->assertStringContainsString('width="100"', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    public function test_strips_all_attributes_from_plain_tags(): void
    {
        $out = $this->sanitizer->sanitize('<p class="big" id="x" style="color:red">text</p>');

        $this->assertSame('<p>text</p>', trim($out));
    }

    public function test_removes_unsafe_href_but_keeps_relative_and_fragment(): void
    {
        $out = $this->sanitizer->sanitize(
            '<a href="javascript:alert(1)">bad</a>'
            .'<a href="#" >frag</a>'
            .'<a href="/relative">rel</a>'
        );

        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('<a>bad</a>', $out, 'anchor kept but javascript href stripped');
        $this->assertStringContainsString('href="#"', $out);
        $this->assertStringContainsString('href="/relative"', $out);
    }

    public function test_strips_non_http_src_and_keeps_https(): void
    {
        $out = $this->sanitizer->sanitize(
            '<img src="data:image/png;base64,AAAA"><img src="https://cdn/x.jpg">'
        );

        $this->assertStringNotContainsString('data:', $out);
        $this->assertStringContainsString('src="https://cdn/x.jpg"', $out);
    }

    public function test_non_ascii_characters_round_trip(): void
    {
        $out = $this->sanitizer->sanitize('<p>Café Grill</p>');

        $this->assertStringContainsString('Caf'.'é', html_entity_decode($out));
        $this->assertStringContainsString('Grill', $out);
    }

    public function test_keeps_missing_attribute_href_as_empty_anchor(): void
    {
        $out = $this->sanitizer->sanitize('<a href="">empty</a>');

        $this->assertStringContainsString('<a>empty</a>', trim($out));
    }
}
