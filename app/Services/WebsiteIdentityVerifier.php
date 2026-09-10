<?php

namespace App\Services;

use App\Models\Restaurant;
use App\Support\StateAbbreviations;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;

/**
 * Decides whether a web page is actually a given restaurant's own website.
 *
 * Why this exists: the website backfill saved the first web-search result
 * that wasn't on a short social/aggregator skip list, and "guessed" domains
 * (`https://{name}.com`) on any 2xx HEAD. In production that stored
 * merriam-webster.com on 791 restaurants, britannica on 409, imdb on 249…
 * (2,443 rows on reference domains), plus thousands of same-name businesses
 * and parked domains. Those wrong sites then fed photos (og:image), social
 * links and descriptions. A liveness check can't catch any of it.
 *
 * A page is judged on two kinds of evidence:
 *   - NAME: the restaurant's name in the page identity (title, og:site_name,
 *     JSON-LD name, h1), or its tokens in the body / domain name;
 *   - LOCATION: the restaurant's phone number, street address, or city/ZIP.
 *
 * Verdicts:
 *   VERIFIED    name + location (or the exact phone number) — the only
 *               verdict under which a NEW website is ever saved;
 *   BRAND       a strong name match on a distinctive name but no location
 *               (a chain's homepage) — kept when already stored;
 *   UNCONFIRMED names it but shows no location — kept, never newly saved;
 *   REJECTED    blocked/reference/parked domain, dead link, no trace of the
 *               name, or a JSON-LD business address in another state;
 *   UNREACHABLE fetch failed / bot-blocked / robots.txt — no judgement.
 */
class WebsiteIdentityVerifier
{
    public const VERIFIED = 'verified';

    public const BRAND = 'brand';

    public const UNCONFIRMED = 'unconfirmed';

    public const REJECTED = 'rejected';

    public const UNREACHABLE = 'unreachable';

    /**
     * Hosts (and their subdomains) that are never a restaurant's own website:
     * reference/dictionary/translation sites (the web-search failure mode for
     * generic names like "Taqueria"), media, social networks, review/listing
     * aggregators and delivery marketplaces (the venue's page there is not its
     * website), search/maps, and domain-parking/marketplace hosts.
     */
    private const BLOCKED_DOMAINS = [
        'merriam-webster.com', 'britannica.com', 'dictionary.com', 'thefreedictionary.com',
        'collinsdictionary.com', 'dictionary.cambridge.org', 'oxfordlearnersdictionaries.com',
        'vocabulary.com', 'wiktionary.org', 'wikipedia.org', 'wikimedia.org', 'wikihow.com',
        'tellmeinspanish.com', 'spanishdict.com', 'wordreference.com', 'urbandictionary.com',
        'yourdictionary.com', 'thesaurus.com', 'etymonline.com', 'definitions.net', 'wordhippo.com',
        'geocountries.com', 'ancestry.com', 'translate.google.com',
        'allrecipes.com', 'foodnetwork.com', 'food.com', 'seriouseats.com', 'epicurious.com',
        'tasteofhome.com', 'delish.com', 'bonappetit.com', 'imdb.com', 'rottentomatoes.com',
        'netflix.com', 'spotify.com', 'apple.com', 'amazon.com', 'ebay.com', 'etsy.com',
        'walmart.com', 'target.com', 'reddit.com', 'quora.com', 'medium.com', 'pinterest.com',
        'tumblr.com', 'linkedin.com', 'indeed.com', 'glassdoor.com',
        'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com', 'tiktok.com',
        'threads.net', 'snapchat.com', 'nextdoor.com', 'youtube.com', 'youtu.be',
        'yelp.com', 'tripadvisor.com', 'foursquare.com', 'zomato.com', 'restaurantguru.com',
        'menupix.com', 'allmenus.com', 'menuism.com', 'zmenu.com', 'sirved.com', 'menupages.com',
        'restaurantji.com', 'opentable.com', 'resy.com', 'yellowpages.com', 'yp.com', 'bbb.org',
        'mapquest.com', 'manta.com', 'chamberofcommerce.com', 'superpages.com', 'citysearch.com',
        'local.com', 'bizapedia.com', 'opencorporates.com', 'dnb.com', 'zoominfo.com', 'groupon.com',
        'roadtrippers.com', 'wanderlog.com', 'happycow.net', 'theinfatuation.com', 'eater.com',
        'timeout.com', 'thrillist.com', 'guide.michelin.com', 'waze.com',
        'ubereats.com', 'doordash.com', 'grubhub.com', 'seamless.com', 'postmates.com',
        'caviar.com', 'slicelife.com', 'beyondmenu.com', 'menufy.com', 'chownow.com',
        'toasttab.com', 'toast.site', 'uorder.io', 'bentoobox.net',
        'google.com', 'goo.gl', 'bing.com', 'duckduckgo.com', 'yahoo.com', 'maps.apple.com',
        'godaddy.com', 'sedo.com', 'dan.com', 'afternic.com', 'hugedomains.com', 'bodis.com',
        'parkingcrew.net', 'sedoparking.com', 'above.com', 'namecheap.com', 'porkbun.com',
        'squadhelp.com', 'atom.com', 'brandbucket.com', 'undeveloped.com',
    ];

    /** Phrases that mark a domain-parking / for-sale / expired placeholder page. */
    private const PARKED_MARKERS = [
        'this domain is for sale', 'domain is for sale', 'buy this domain', 'this domain may be for sale',
        'domain name is for sale', 'is available for purchase', 'parked free', 'domain parking',
        'this web page is parked', 'this domain has expired', 'domain has expired',
        'sedoparking', 'hugedomains', 'related searches', 'sponsored listings',
    ];

    /**
     * Bot-challenge / WAF interstitials served with HTTP 200 — no judgement
     * can be made from them (they must never read as "no name on the page").
     */
    private const CHALLENGE_MARKERS = [
        'cf-chl', 'challenge-platform', '<title>just a moment', 'checking your browser before accessing',
        'enable javascript and cookies to continue', '_incapsula_resource', 'pardon our interruption',
        'attention required! | cloudflare', '<title>access denied',
    ];

    /**
     * URL paths that are reference/content pages on ANY host — a stored
     * "website" like onthisday.com/events/date/1897 or …/dictionary/pho is
     * never a restaurant's homepage, whether or not the page can be fetched.
     */
    private const REFERENCE_PATH_PATTERN = '#/(dictionary|definition|define|translate|translation|thesaurus|wiki|grammar|lyrics|recipes?|events/date|title/tt\d+|name/nm\d+)(/|$|\?|-)#i';

    /** A page title naming a reference/entertainment page, not a business. */
    private const REFERENCE_TITLE_PATTERN = '/\b(definition|meaning|dictionary|encyclopedia|wikipedia|wiki|recipes?|how to|translations?|translate|synonyms?|pronunciation|lyrics|imdb|trailer|movie)\b/i';

    /** Words that carry no identity in a restaurant name. */
    private const NAME_STOPWORDS = [
        'the', 'and', 'of', 'a', 'an', 'at', 'on', 'in', 'by', 'de', 'del', 'la', 'el', 'los', 'las',
        'le', 'du', 'di', 'da', 'y', 'restaurant', 'restaurants', 'restaurante', 'llc', 'inc', 'co',
        'company', 'corp',
    ];

    /**
     * Food/decor words shared by thousands of restaurant names. A name made
     * only of these ("China Wok", "Taqueria", "Pho House") is not distinctive:
     * matching it proves nothing without location evidence.
     */
    private const GENERIC_NAME_TOKENS = [
        'taqueria', 'taco', 'tacos', 'pizza', 'pizzeria', 'pho', 'sushi', 'thai', 'chinese', 'china',
        'mexican', 'mexico', 'italian', 'italia', 'indian', 'india', 'bbq', 'barbecue', 'burger',
        'burgers', 'wings', 'wing', 'noodle', 'noodles', 'ramen', 'deli', 'bakery', 'donut', 'donuts',
        'diner', 'steakhouse', 'steak', 'seafood', 'grill', 'grille', 'cafe', 'coffee', 'kitchen', 'bar',
        'pub', 'cantina', 'tavern', 'buffet', 'wok', 'garden', 'palace', 'house', 'king', 'golden',
        'dragon', 'express', 'food', 'foods', 'bistro', 'eatery', 'shop', 'market', 'spot', 'corner',
        'place', 'station', 'hut', 'bowl', 'bowls', 'fresh', 'best', 'new', 'little', 'big', 'star',
        'super', 'royal', 'happy', 'lucky', 'asian', 'japanese', 'korean', 'vietnamese', 'greek',
        'mediterranean', 'cuisine', 'chicken', 'fish', 'fried', 'hot', 'dog', 'dogs', 'sandwich',
        'sandwiches', 'subs', 'sub', 'bagel', 'bagels', 'juice', 'tea', 'boba', 'cream', 'ice',
        'creamery', 'catering', 'cocina', 'comida', 'mariscos', 'antojitos', 'pollo', 'city', 'town',
        'american', 'southern', 'soul', 'cajun', 'bar-b-q', 'brew', 'brewing', 'brewery', 'wine',
    ];

    /** Sub-pages checked for location evidence when the homepage lacks it. */
    private const EXTRA_PATHS = ['/contact', '/contact-us', '/location', '/locations', '/about', '/about-us', '/visit', '/find-us', '/hours'];

    /** JSON-LD @type fragments that describe a physical business location. */
    private const LOCAL_BUSINESS_TYPE_PATTERN = '/(Restaurant|FoodEstablishment|LocalBusiness|Cafe|Coffee|Bar|Pub|Bakery|Brewery|Winery|Store)/i';

    private const MAX_TEXT_CHARS = 400000;

    public function __construct(private RestaurantWebsiteScraperService $scraper) {}

    /**
     * Cheap pre-check (no fetch): is this URL on a host that is never a
     * restaurant's own website?
     */
    public function isBlockedUrl(string $url): bool
    {
        $host = $this->hostOf($url);
        if ($host === null) {
            return true;
        }

        foreach (self::BLOCKED_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  bool  $domainCountsAsName  false when the URL was GUESSED from the
     *                                    name (`https://{name}.com`): its host then matches the name by
     *                                    construction and proves nothing — name evidence must come from the page
     */
    public function verify(Restaurant $restaurant, string $url, bool $domainCountsAsName = true): WebsiteIdentityVerdict
    {
        $url = $this->normalizeUrl($url);
        if ($url === null) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'invalid_url');
        }

        if ($this->isBlockedUrl($url)) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'blocked_domain');
        }

        if (preg_match(self::REFERENCE_PATH_PATTERN, (string) parse_url($url, PHP_URL_PATH)) === 1) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'reference_url');
        }

        $timeout = max(1, (int) config('restaurant-finder.data_integrity.website_verify_timeout', 8));
        $page = $this->scraper->fetchHtml($url, $timeout);

        if ($page === null) {
            return new WebsiteIdentityVerdict(self::UNREACHABLE, 'fetch_failed');
        }
        if (in_array($page['status'], [404, 410], true)) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'dead_link');
        }
        if ($page['status'] < 200 || $page['status'] >= 300) {
            return new WebsiteIdentityVerdict(self::UNREACHABLE, 'http_'.$page['status']);
        }
        if ($this->isBlockedUrl($page['final_url'])) {
            // Redirected onto a parking/marketplace/aggregator host.
            return new WebsiteIdentityVerdict(self::REJECTED, 'blocked_domain');
        }

        $head = strtolower(substr($page['body'], 0, 20000));
        foreach (self::CHALLENGE_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                return new WebsiteIdentityVerdict(self::UNREACHABLE, 'bot_challenge');
            }
        }

        $facts = $this->restaurantFacts($restaurant);
        $evidence = $this->pageEvidence($page['body']);

        if ($evidence['parked']) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'parked_domain');
        }

        $hostLabel = $domainCountsAsName ? $this->hostLabel($page['final_url']) : '';
        $verdict = $this->judge($facts, $evidence, $hostLabel);

        // The page names the venue but shows no location: the address/phone
        // usually lives on a contact/location page — check a bounded few.
        if (in_array($verdict->status, [self::BRAND, self::UNCONFIRMED], true)) {
            $evidence = $this->mergeExtraPages($page['final_url'], $evidence);
            $verdict = $this->judge($facts, $evidence, $hostLabel);
        }

        return $verdict;
    }

    /**
     * @param  array{name: string, compact: string, tokens: list<string>, distinctive: bool, phone: ?string, street: ?array{0: string, 1: string}, city: ?string, zip: ?string, state: ?string}  $facts
     * @param  array{identity: string, text: string, phones: array<string, true>, states: list<string>, parked: bool, reference: bool}  $evidence
     */
    private function judge(array $facts, array $evidence, string $hostLabel): WebsiteIdentityVerdict
    {
        $identity = $evidence['identity'];
        $text = $evidence['text'];

        $nameStrong = $this->nameInIdentity($facts, $identity)
            || (strlen($facts['compact']) >= 6 && str_contains($hostLabel, $facts['compact']));
        $nameInDomain = $facts['tokens'] !== [] && $this->allTokensIn($facts['tokens'], $hostLabel, false);
        $nameInBody = $facts['tokens'] !== [] && $this->allTokensIn($facts['tokens'], $text, true);

        $phone = $facts['phone'] !== null && isset($evidence['phones'][$facts['phone']]);
        $street = $facts['street'] !== null && $this->streetIn($facts['street'], $text);
        $city = $facts['city'] !== null && $this->wordIn($facts['city'], $text);
        $zip = $facts['zip'] !== null && $this->wordIn($facts['zip'], $text);

        $found = array_keys(array_filter([
            'name_identity' => $nameStrong, 'name_domain' => $nameInDomain, 'name_body' => $nameInBody,
            'phone' => $phone, 'street' => $street, 'city' => $city, 'zip' => $zip,
        ]));

        if ($phone) {
            return new WebsiteIdentityVerdict(self::VERIFIED, 'phone', $found);
        }

        // A JSON-LD business address in a different state (and no phone
        // match) is a same-name venue elsewhere, e.g. another city's location page.
        if ($facts['state'] !== null && $evidence['states'] !== [] && ! in_array($facts['state'], $evidence['states'], true)) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'location_mismatch', $found);
        }

        if ($street && ($nameStrong || $nameInDomain)) {
            return new WebsiteIdentityVerdict(self::VERIFIED, 'street', $found);
        }

        if ($nameStrong && ($city || $zip)) {
            return new WebsiteIdentityVerdict(self::VERIFIED, 'name_and_city', $found);
        }

        if ($evidence['reference']) {
            return new WebsiteIdentityVerdict(self::REJECTED, 'reference_page', $found);
        }

        if (! $nameStrong && ! $nameInDomain && ! $nameInBody) {
            // A near-empty page (JS app shell, splash image) can't be judged
            // by absence; only a content-rich page that never names the
            // venue is positively someone else's.
            return strlen($text) < 200
                ? new WebsiteIdentityVerdict(self::UNCONFIRMED, 'thin_page', $found)
                : new WebsiteIdentityVerdict(self::REJECTED, 'no_name_evidence', $found);
        }

        if ($nameStrong && $facts['distinctive']) {
            return new WebsiteIdentityVerdict(self::BRAND, 'name_only', $found);
        }

        return new WebsiteIdentityVerdict(self::UNCONFIRMED, 'no_location_evidence', $found);
    }

    /**
     * @param  array{name: string, compact: string, tokens: list<string>, distinctive: bool, phone: ?string, street: ?array{0: string, 1: string}, city: ?string, zip: ?string, state: ?string}  $facts
     */
    private function nameInIdentity(array $facts, string $identity): bool
    {
        if ($identity === '') {
            return false;
        }

        $compactIdentity = str_replace(' ', '', $identity);
        if (strlen($facts['compact']) >= 4 && str_contains($compactIdentity, $facts['compact'])) {
            return true;
        }

        // Every identifying token in the identity text. A lone short token
        // ("Pho") is too weak to count as a strong match on its own.
        $tokens = $facts['tokens'];

        return $tokens !== []
            && (count($tokens) >= 2 || strlen($tokens[0]) >= 5)
            && $this->allTokensIn($tokens, $identity, true);
    }

    /**
     * @param  list<string>  $tokens
     */
    private function allTokensIn(array $tokens, string $haystack, bool $wholeWord): bool
    {
        foreach ($tokens as $token) {
            $hit = $wholeWord ? $this->wordIn($token, $haystack) : (strlen($token) >= 3 && str_contains($haystack, $token));
            if (! $hit) {
                return false;
            }
        }

        return true;
    }

    private function wordIn(string $needle, string $haystack): bool
    {
        return $needle !== '' && preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/', $haystack) === 1;
    }

    /**
     * @param  array{0: string, 1: string}  $street  [house number, first street word]
     */
    private function streetIn(array $street, string $text): bool
    {
        [$number, $word] = $street;
        $pattern = '/(?<![0-9])'.preg_quote($number, '/').'(?![0-9]).{0,24}?(?<![a-z0-9])'.preg_quote($word, '/').'(?![a-z0-9])/s';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * @return array{name: string, compact: string, tokens: list<string>, distinctive: bool, phone: ?string, street: ?array{0: string, 1: string}, city: ?string, zip: ?string, state: ?string}
     */
    private function restaurantFacts(Restaurant $restaurant): array
    {
        $name = $this->normalizeText((string) $restaurant->name);
        $words = $name === '' ? [] : explode(' ', $name);

        $tokens = array_values(array_filter(
            $words,
            fn (string $w) => (strlen($w) >= 3 || ctype_digit($w)) && ! in_array($w, self::NAME_STOPWORDS, true)
        ));
        if ($tokens === []) {
            $tokens = array_values(array_filter($words, fn (string $w) => strlen($w) >= 2));
        }

        $distinctive = false;
        foreach ($tokens as $token) {
            if (! in_array($token, self::GENERIC_NAME_TOKENS, true) && ! ctype_digit($token)) {
                $distinctive = true;
                break;
            }
        }

        $compact = str_replace(' ', '', (string) preg_replace('/^the /', '', $name));

        $digits = preg_replace('/\D+/', '', (string) $restaurant->phone) ?? '';
        $phone = strlen($digits) >= 10 ? substr($digits, -10) : null;

        $street = null;
        $address = $this->normalizeText((string) $restaurant->address);
        if (preg_match('/(?<![0-9])(\d{1,6}[a-z]?) (?:(?:n|s|e|w|ne|nw|se|sw|north|south|east|west) )?([a-z0-9]{2,})/', $address, $m) === 1) {
            $street = [$m[1], $m[2]];
        }

        $city = $this->normalizeText((string) $restaurant->city);
        $zip = null;
        if (preg_match('/\b(\d{5})(?:-\d{4})?\b/', (string) $restaurant->postal_code.' '.(string) $restaurant->address, $zm) === 1) {
            $zip = $zm[1];
        }

        return [
            'name' => $name,
            'compact' => $compact,
            'tokens' => $tokens,
            'distinctive' => $distinctive,
            'phone' => $phone,
            'street' => $street,
            'city' => strlen($city) >= 3 ? $city : null,
            'zip' => $zip,
            'state' => StateAbbreviations::toAbbreviation($restaurant->state),
        ];
    }

    /**
     * @return array{identity: string, text: string, phones: array<string, true>, states: list<string>, parked: bool, reference: bool}
     */
    private function pageEvidence(string $html): array
    {
        $empty = ['identity' => '', 'text' => '', 'phones' => [], 'states' => [], 'parked' => false, 'reference' => false];
        if (trim($html) === '') {
            return $empty;
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        if (! $loaded) {
            return $empty;
        }

        $xpath = new DOMXPath($dom);
        $identityParts = [];

        $title = trim((string) $xpath->evaluate('string(//title)'));
        $identityParts[] = $title;
        foreach (["//meta[@property='og:site_name']/@content", "//meta[@property='og:title']/@content", "//meta[@name='application-name']/@content", '//h1'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false) {
                foreach ($nodes as $i => $node) {
                    if ($i >= 3) {
                        break;
                    }
                    if ($node instanceof DOMNode) {
                        $identityParts[] = (string) $node->textContent;
                    }
                }
            }
        }

        $phones = [];
        $states = [];
        $scripts = $xpath->query("//script[@type='application/ld+json']");
        if ($scripts !== false) {
            foreach ($scripts as $script) {
                if ($script instanceof DOMElement) {
                    $this->collectJsonLd($script->textContent, $identityParts, $phones, $states);
                }
            }
        }

        $telLinks = $xpath->query("//a[starts-with(@href, 'tel:')]");
        if ($telLinks !== false) {
            foreach ($telLinks as $link) {
                if ($link instanceof DOMElement) {
                    $this->addPhone($link->getAttribute('href'), $phones);
                }
            }
        }

        $removals = $xpath->query('//script|//style|//noscript|//template|//svg');
        if ($removals !== false) {
            foreach (iterator_to_array($removals) as $node) {
                if ($node instanceof DOMNode) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $rawText = mb_substr((string) preg_replace('/\s+/u', ' ', (string) $dom->textContent), 0, self::MAX_TEXT_CHARS);
        preg_match_all('/(?:\+?1[\s.\-]?)?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}/', $rawText, $phoneMatches);
        foreach ($phoneMatches[0] as $candidate) {
            $this->addPhone($candidate, $phones);
        }

        $text = $this->normalizeText($rawText);
        $lowerTitle = mb_strtolower($title);
        $parked = false;
        foreach (self::PARKED_MARKERS as $marker) {
            if (str_contains($lowerTitle, $marker) || (strlen($text) < 20000 && str_contains($text, $this->normalizeText($marker)))) {
                $parked = true;
                break;
            }
        }

        return [
            'identity' => $this->normalizeText(implode(' ', $identityParts)),
            'text' => $text,
            'phones' => $phones,
            'states' => array_values(array_unique($states)),
            'parked' => $parked,
            'reference' => preg_match(self::REFERENCE_TITLE_PATTERN, $title) === 1,
        ];
    }

    /**
     * Pull names, phones and business-address states out of a JSON-LD block
     * (any nesting, incl. @graph).
     *
     * @param  list<string>  $identityParts
     * @param  array<string, true>  $phones
     * @param  list<string>  $states
     */
    private function collectJsonLd(string $json, array &$identityParts, array &$phones, array &$states): void
    {
        $data = json_decode(trim($json), true);
        if (! is_array($data)) {
            return;
        }

        // Only arrays are ever pushed, so every popped node is an array.
        $stack = [$data];
        while ($stack !== []) {
            $node = array_pop($stack);

            $type = $node['@type'] ?? null;
            $typeText = is_array($type) ? implode(' ', array_filter($type, 'is_string')) : (is_string($type) ? $type : '');
            $isBusiness = $typeText !== '' && preg_match(self::LOCAL_BUSINESS_TYPE_PATTERN, $typeText) === 1;

            if ($isBusiness) {
                if (is_string($node['name'] ?? null)) {
                    $identityParts[] = $node['name'];
                }
                if (is_string($node['telephone'] ?? null)) {
                    $this->addPhone($node['telephone'], $phones);
                }
                $address = $node['address'] ?? null;
                if (is_array($address) && is_string($address['addressRegion'] ?? null)) {
                    $abbr = StateAbbreviations::toAbbreviation($address['addressRegion']);
                    if ($abbr !== null) {
                        $states[] = $abbr;
                    }
                }
            }

            foreach ($node as $child) {
                if (is_array($child)) {
                    $stack[] = $child;
                }
            }
        }
    }

    /**
     * @param  array<string, true>  $phones
     */
    private function addPhone(string $raw, array &$phones): void
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) >= 10) {
            $phones[substr($digits, -10)] = true;
        }
    }

    /**
     * Fetch up to website_verify_max_extra_pages contact/location sub-pages
     * and fold their text/phones/states into the evidence (identity stays the
     * homepage's).
     *
     * @param  array{identity: string, text: string, phones: array<string, true>, states: list<string>, parked: bool, reference: bool}  $evidence
     * @return array{identity: string, text: string, phones: array<string, true>, states: list<string>, parked: bool, reference: bool}
     */
    private function mergeExtraPages(string $baseUrl, array $evidence): array
    {
        $max = max(0, (int) config('restaurant-finder.data_integrity.website_verify_max_extra_pages', 2));
        $parts = parse_url($baseUrl);
        if ($max === 0 || $parts === false || empty($parts['host'])) {
            return $evidence;
        }

        $root = ($parts['scheme'] ?? 'https').'://'.$parts['host'];
        $timeout = max(1, (int) config('restaurant-finder.data_integrity.website_verify_timeout', 8));
        $fetched = 0;
        $attempts = 0;

        foreach (self::EXTRA_PATHS as $path) {
            if ($fetched >= $max || $attempts >= $max * 2) {
                break;
            }
            $attempts++;

            $page = $this->scraper->fetchHtml($root.$path, $timeout);
            if ($page === null || $page['status'] < 200 || $page['status'] >= 300) {
                continue;
            }
            $fetched++;

            $extra = $this->pageEvidence($page['body']);
            $evidence['text'] .= ' '.$extra['text'];
            $evidence['phones'] += $extra['phones'];
            $evidence['states'] = array_values(array_unique([...$evidence['states'], ...$extra['states']]));
        }

        return $evidence;
    }

    /**
     * ASCII-fold, lowercase, drop apostrophes, "&" → "and", non-alphanumerics
     * → single spaces.
     */
    private function normalizeText(string $value): string
    {
        $value = Str::ascii($value);
        $value = strtolower($value);
        $value = str_replace(['&', "'", '`'], [' and ', '', ''], $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim($value);
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        $host = $this->hostOf($url);

        return $host !== null && str_contains($host, '.') ? $url : null;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return (string) preg_replace('/^www\./', '', strtolower($host));
    }

    /**
     * The registrable-ish label of a host with separators removed
     * ("www.khues-kitchen.com" → "khueskitchen", "locations.chipotle.com" →
     * "locationschipotle").
     */
    private function hostLabel(string $url): string
    {
        $host = $this->hostOf($url) ?? '';
        $host = (string) preg_replace('/\.[a-z]{2,}(\.[a-z]{2})?$/', '', $host);

        return (string) preg_replace('/[^a-z0-9]+/', '', $host);
    }
}
