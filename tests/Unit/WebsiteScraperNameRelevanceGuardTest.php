<?php

namespace Tests\Unit;

use App\Services\RestaurantWebsiteScraperService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract for the name-relevance guard on Wikimedia/Wikipedia image search.
 *
 * Live regression (2026-08-15): the --verify photo re-source used
 * searchWikimediaCommons/searchWikipediaImage, which take the FIRST keyword
 * search hit regardless of accuracy. Results included "Fixins Soul Kitchen" →
 * a 1917 poetry book, "Mrs. White's Golden Rule Cafe" → a novel, "American
 * Dive" → a 1924 photo of a person diving. A broken-but-Google image was
 * replaced with a WRONG image — worse.
 *
 * The guard: a Wikimedia/Wikipedia match is only accepted when the matched
 * page/file TITLE contains the restaurant name (token-level, e.g.
 * "Fixins Soul Kitchen" must appear in the title). Keyword-fragment hits are
 * rejected and the search falls through to the next source.
 */
class WebsiteScraperNameRelevanceGuardTest extends TestCase
{
    public function test_rejects_wikimedia_title_that_lacks_restaurant_name(): void
    {
        Http::fake([
            // Commons search returns a book title that shares a keyword but is
            // not the restaurant.
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'Songs of the schrapnel shell, and other verses (1917)']]]])
                ->push(['query' => ['pages' => [['imageinfo' => [['url' => 'https://upload.wikimedia.org/wikipedia/commons/poetry.png']]]]]]),
        ]);

        $service = $this->app->make(RestaurantWebsiteScraperService::class);

        // searchAnyImage with no website → falls to Wikimedia → must REJECT
        // (title "Songs of the schrapnel shell..." does not contain "Fixins
        // Soul Kitchen"), then Wikipedia, then Google CSE (no key → null).
        $result = $service->searchAnyImage('Fixins Soul Kitchen', 'Austin', 'TX');

        $this->assertNull($result, 'a Wikimedia hit whose title lacks the restaurant name must be rejected');
    }

    public function test_accepts_wikimedia_title_that_contains_restaurant_name(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'Fixins Soul Kitchen, Austin Texas (exterior)']]]])
                ->push(['query' => ['pages' => [['imageinfo' => [['url' => 'https://upload.wikimedia.org/wikipedia/commons/fixins.jpg']]]]]]),
        ]);

        $service = $this->app->make(RestaurantWebsiteScraperService::class);

        $result = $service->searchAnyImage('Fixins Soul Kitchen', 'Austin', 'TX');

        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/fixins.jpg', $result, 'a Wikimedia hit whose title contains the restaurant name must be accepted');
    }

    public function test_rejects_wikipedia_title_that_lacks_restaurant_name(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => []]])
                ->push([]),
            // Wikipedia search returns an unrelated article.
            'en.wikipedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'Golden mediocrity. A novel']]]])
                ->push([]),
        ]);

        $service = $this->app->make(RestaurantWebsiteScraperService::class);

        $result = $service->searchAnyImage("Mrs. White's Golden Rule Cafe", 'Austin', 'TX');

        $this->assertNull($result, 'a Wikipedia hit whose title lacks the restaurant name must be rejected');
    }

    public function test_falls_through_to_next_source_when_wikimedia_rejected(): void
    {
        Http::fake([
            // Commons returns an off-name hit (rejected), Wikipedia returns the
            // actual restaurant page (accepted).
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'The Mediterranean shores of America']]]])
                ->push(['query' => ['pages' => [['imageinfo' => [['url' => 'https://upload.wikimedia.org/wikipedia/commons/wrong.png']]]]]]),
            'en.wikipedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'Pita & Naan']]]])
                ->push(['query' => ['pages' => [['thumbnail' => ['source' => 'https://upload.wikimedia.org/wikipedia/en/pitanaan.jpg']]]]]),
        ]);

        $service = $this->app->make(RestaurantWebsiteScraperService::class);

        $result = $service->searchAnyImage('Pita & Naan', 'Lincoln', 'Nebraska');

        $this->assertSame('https://upload.wikimedia.org/wikipedia/en/pitanaan.jpg', $result, 'must fall through to Wikipedia when the Commons hit is off-name');
    }

    public function test_rejects_wikipedia_title_with_partial_name_mismatch(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => []]])
                ->push([]),
            // "American Dive" vs "American Divers" — not the same venue, but a
            // loose title check would pass. The guard must match the full name.
            'en.wikipedia.org/*' => Http::sequence()
                ->push(['query' => ['search' => [['title' => 'American Divers Association']]]])
                ->push([]),
        ]);

        $service = $this->app->make(RestaurantWebsiteScraperService::class);

        $result = $service->searchAnyImage('American Dive', 'Austin', 'TX');

        $this->assertNull($result, 'a partial/token-substring Wikipedia title match must be rejected when the full name is absent');
    }
}
