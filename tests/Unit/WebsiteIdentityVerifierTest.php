<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pins WebsiteIdentityVerifier's verdicts. The motivating prod failures: the
 * website backfill stored merriam-webster/britannica/imdb pages, parked
 * domains and same-name businesses as restaurant websites because nothing
 * checked the page was actually THIS restaurant's.
 */
class WebsiteIdentityVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        Config::set('restaurant-finder.data_integrity.website_verify_max_extra_pages', 2);
    }

    /** @param array<string, mixed> $overrides */
    private function khue(array $overrides = []): Restaurant
    {
        return new Restaurant(array_merge([
            'name' => "Khue's Kitchen",
            'address' => '693 Raymond Ave',
            'city' => 'St Paul',
            'state' => 'MN',
            'postal_code' => '55114',
            'phone' => '(612) 600-9139',
        ], $overrides));
    }

    /** @param array<string, mixed> $pages url pattern => response */
    private function fake(array $pages): void
    {
        Http::fake(array_merge($pages, ['*' => Http::response('', 404)]));
    }

    private function html(string $title, string $body, string $head = ''): string
    {
        return "<html><head><title>{$title}</title>{$head}</head><body>{$body}</body></html>";
    }

    private function verifier(): WebsiteIdentityVerifier
    {
        return $this->app->make(WebsiteIdentityVerifier::class);
    }

    public function test_blocked_reference_domain_is_rejected_without_fetching(): void
    {
        Http::fake();

        $verdict = $this->verifier()->verify($this->khue(), 'https://www.merriam-webster.com/dictionary/taqueria');

        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status);
        $this->assertSame('blocked_domain', $verdict->reason);
        Http::assertNothingSent();
    }

    public function test_reference_path_on_any_host_is_rejected_without_fetching(): void
    {
        Http::fake();

        foreach (['https://www.onthisday.com/events/date/1897', 'https://words.example/dictionary/pho', 'https://m.example.org/title/tt0472033/'] as $url) {
            $verdict = $this->verifier()->verify($this->khue(), $url);
            $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status, $url);
            $this->assertSame('reference_url', $verdict->reason, $url);
        }
        Http::assertNothingSent();
    }

    public function test_menu_and_location_paths_are_not_mistaken_for_reference_pages(): void
    {
        $this->fake(['https://khueskitchen.example/locations/st-paul' => Http::response($this->html("Khue's Kitchen", '<p>(612) 600-9139</p>'))]);

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $this->verifier()->verify($this->khue(), 'https://khueskitchen.example/locations/st-paul')->status);
    }

    public function test_blocked_check_covers_subdomains(): void
    {
        $this->assertTrue($this->verifier()->isBlockedUrl('https://dictionary.cambridge.org/x'));
        $this->assertTrue($this->verifier()->isBlockedUrl('https://m.yelp.com/biz/khues-kitchen'));
        $this->assertFalse($this->verifier()->isBlockedUrl('https://khues.menufy.com/'), 'white-label ordering sites are judged on content');
        $this->assertFalse($this->verifier()->isBlockedUrl('https://www.khueskitchen.com/'));
        $this->assertFalse($this->verifier()->isBlockedUrl('https://locations.chipotle.com/tx/austin'));
    }

    public function test_phone_on_the_page_verifies(): void
    {
        $this->fake(['https://www.khueskitchen.com/' => Http::response($this->html('Home', '<footer>Call us: 612.600.9139</footer>'.str_repeat(' menu', 60)))]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://www.khueskitchen.com/');

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $verdict->status);
        $this->assertSame('phone', $verdict->reason);
        $this->assertTrue($verdict->acceptsNew());
    }

    public function test_tel_link_counts_as_the_phone(): void
    {
        $this->fake(['https://khues.example/' => Http::response($this->html('Welcome', '<a href="tel:+16126009139">Call</a>'))]);

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $this->verifier()->verify($this->khue(), 'https://khues.example/')->status);
    }

    public function test_name_plus_street_verifies(): void
    {
        $this->fake(['https://www.khueskitchen.com/' => Http::response($this->html(
            "Khue's Kitchen | Vietnamese in St Paul",
            '<p>Find us at 693 Raymond Avenue</p>'
        ))]);

        $verdict = $this->verifier()->verify($this->khue(['phone' => null]), 'https://www.khueskitchen.com/');

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $verdict->status);
        $this->assertSame('street', $verdict->reason);
    }

    public function test_strong_name_plus_city_verifies(): void
    {
        $this->fake(['https://eat.example/' => Http::response($this->html(
            'Welcome',
            '<p>The best pho in St. Paul, Minnesota.</p>',
            '<meta property="og:site_name" content="Khue’s Kitchen">'
        ))]);

        $verdict = $this->verifier()->verify($this->khue(['phone' => null, 'address' => null]), 'https://eat.example/');

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $verdict->status);
        $this->assertSame('name_and_city', $verdict->reason);
    }

    public function test_json_ld_business_in_another_state_is_rejected(): void
    {
        $jsonLd = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Restaurant","name":"Khue\'s Kitchen","address":{"@type":"PostalAddress","addressLocality":"Austin","addressRegion":"Texas"},"telephone":"512-555-0100"}</script>';
        $this->fake(['https://khueskitchen.example/' => Http::response($this->html("Khue's Kitchen", '<p>Austin favorite</p>', $jsonLd))]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://khueskitchen.example/');

        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status);
        $this->assertSame('location_mismatch', $verdict->reason);
    }

    public function test_parked_domain_is_rejected(): void
    {
        $this->fake(['https://www.khueskitchen.com/' => Http::response($this->html('khueskitchen.com', '<h2>This domain is for sale!</h2><p>Related searches: restaurants</p>'))]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://www.khueskitchen.com/');

        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status);
        $this->assertSame('parked_domain', $verdict->reason);
    }

    public function test_unknown_reference_page_is_rejected(): void
    {
        $restaurant = new Restaurant(['name' => 'Pho', 'city' => 'Dallas', 'state' => 'TX', 'phone' => '2145550100']);
        $this->fake(['https://words.example/pho' => Http::response($this->html(
            'Pho Definition & Meaning',
            '<p>Pho is a Vietnamese soup consisting of broth, rice noodles and herbs.</p>'.str_repeat('<p>Etymology and usage notes.</p>', 20)
        ))]);

        $verdict = $this->verifier()->verify($restaurant, 'https://words.example/pho');

        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status);
        $this->assertSame('reference_page', $verdict->reason);
    }

    public function test_content_rich_page_that_never_names_the_venue_is_rejected(): void
    {
        $this->fake(['https://acme-plumbing.example/' => Http::response($this->html(
            'Acme Plumbing | Drains & Water Heaters',
            str_repeat('<p>Licensed plumbers serving the metro since 1990. Call for a free estimate.</p>', 10)
        ))]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://acme-plumbing.example/');

        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $verdict->status);
        $this->assertSame('no_name_evidence', $verdict->reason);
        $this->assertTrue($verdict->isRejected());
    }

    public function test_thin_page_without_name_is_undecided_not_rejected(): void
    {
        $this->fake(['https://app.example/' => Http::response($this->html('', '<div id="app"></div>'))]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://app.example/');

        $this->assertSame(WebsiteIdentityVerifier::UNCONFIRMED, $verdict->status);
        $this->assertSame('thin_page', $verdict->reason);
        $this->assertFalse($verdict->acceptsNew());
    }

    public function test_distinctive_chain_homepage_without_location_is_brand(): void
    {
        $restaurant = new Restaurant(['name' => 'Panda Express', 'address' => '100 Main St', 'city' => 'Austin', 'state' => 'TX', 'phone' => '5125550100']);
        $this->fake(['https://www.pandaexpress.com/' => Http::response($this->html(
            'Panda Express | Chinese Food Delivery & Takeout',
            '<p>Order Orange Chicken online.</p>'
        ))]);

        $verdict = $this->verifier()->verify($restaurant, 'https://www.pandaexpress.com/');

        $this->assertSame(WebsiteIdentityVerifier::BRAND, $verdict->status);
        $this->assertFalse($verdict->acceptsNew(), 'a brand homepage is kept when stored but never newly saved');
    }

    public function test_generic_name_without_location_is_only_unconfirmed(): void
    {
        $restaurant = new Restaurant(['name' => 'China Wok', 'city' => 'Tulsa', 'state' => 'OK', 'phone' => '9185550100']);
        $this->fake(['https://chinawok.example/' => Http::response($this->html('China Wok - Best Chinese Food', '<p>Lunch specials daily.</p>'))]);

        $this->assertSame(WebsiteIdentityVerifier::UNCONFIRMED, $this->verifier()->verify($restaurant, 'https://chinawok.example/')->status);
    }

    public function test_location_evidence_on_a_contact_page_verifies(): void
    {
        $this->fake([
            'https://www.khueskitchen.com/' => Http::response($this->html("Khue's Kitchen", '<p>Modern Vietnamese.</p>')),
            'https://www.khueskitchen.com/contact' => Http::response($this->html('Contact', '<p>Phone: (612) 600-9139</p>')),
        ]);

        $verdict = $this->verifier()->verify($this->khue(), 'https://www.khueskitchen.com/');

        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $verdict->status);
        $this->assertSame('phone', $verdict->reason);
    }

    public function test_guessed_domain_cannot_vouch_for_the_name(): void
    {
        // https://{name}.com was GUESSED: the host matches by construction. A
        // page that is someone else's (different name in content) must not
        // pass on the host alone, even though it mentions the same city.
        $this->fake(['https://www.khueskitchen.com/' => Http::response($this->html(
            'Sunrise Diner',
            '<p>Breakfast all day in St Paul.</p>'.str_repeat('<p>Pancakes and eggs.</p>', 15)
        ))]);

        $guessed = $this->verifier()->verify($this->khue(['phone' => null]), 'https://www.khueskitchen.com/', domainCountsAsName: false);
        $this->assertFalse($guessed->acceptsNew());
        $this->assertSame(WebsiteIdentityVerifier::REJECTED, $guessed->status);

        $trusted = $this->verifier()->verify($this->khue(['phone' => null]), 'https://www.khueskitchen.com/');
        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $trusted->status, 'a host that IS the name plus the city verifies when the URL was not guessed');
    }

    public function test_dead_link_is_rejected_and_bot_block_is_unreachable(): void
    {
        $this->fake([
            'https://gone.example/' => Http::response('', 404),
            'https://blocked.example/' => Http::response('Forbidden', 403),
            'https://cf.example/' => Http::response('<html><head><title>Just a moment...</title></head><body><div id="cf-chl-widget"></div></body></html>', 200),
        ]);

        $this->assertSame('dead_link', $this->verifier()->verify($this->khue(), 'https://gone.example/')->reason);

        $blocked = $this->verifier()->verify($this->khue(), 'https://blocked.example/');
        $this->assertSame(WebsiteIdentityVerifier::UNREACHABLE, $blocked->status);
        $this->assertNull($blocked->storedIdentity());

        $challenge = $this->verifier()->verify($this->khue(), 'https://cf.example/');
        $this->assertSame(WebsiteIdentityVerifier::UNREACHABLE, $challenge->status);
        $this->assertSame('bot_challenge', $challenge->reason);
    }
}
