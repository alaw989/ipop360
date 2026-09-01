<?php

namespace Tests\Unit;

use App\Services\CuisineMatcher;
use Tests\TestCase;

/**
 * Compilability drift-guard for the cuisine-keyword lexicon (spec-097).
 *
 * config/cuisine-keywords.php intentionally uses raw `.` as a regex
 * separator-wildcard (documented at the top of that file; preg_quote would
 * regress recall), so the keyword fragments are dropped into preg_match
 * patterns unquoted. A future malformed keyword (e.g. `pho+`, an unbalanced
 * paren/bracket) would make preg_match fail with a compile error — a 500 on
 * every search for that cuisine. This test builds every pattern exactly as
 * CuisineMatcher does and asserts each one compiles cleanly.
 */
class CuisineKeywordRegexGuardTest extends TestCase
{
    private CuisineMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = $this->app->make(CuisineMatcher::class);
    }

    /**
     * The per-cuisine ON pattern CuisineMatcher::matchesEvidence() builds:
     * '/'.implode('|', $this->keywordsFor([$cuisineSlug])).'/i'.
     */
    public function test_every_cuisine_on_pattern_compiles(): void
    {
        $slugs = array_keys(config('cuisine-keywords.cuisines', []));

        $this->assertNotEmpty($slugs, 'config/cuisine-keywords.php has no cuisines');

        foreach ($slugs as $slug) {
            $pattern = '/'.implode('|', $this->matcher->keywordsFor([$slug])).'/i';

            $this->assertSame(
                PREG_NO_ERROR,
                $this->compileError($pattern),
                "Cuisine '{$slug}' has a keyword that breaks its on-pattern regex: {$pattern}"
            );
        }
    }

    /**
     * The combined cross-cuisine RIVAL pattern CuisineMatcher::matchesRivalEvidence()
     * builds: every other cuisine's keywords minus those shared with the tagged
     * cuisine, joined into one '/i' pattern.
     */
    public function test_every_cuisine_rival_pattern_compiles(): void
    {
        $cuisines = config('cuisine-keywords.cuisines', []);

        foreach (array_keys($cuisines) as $slug) {
            $onSet = array_flip($this->matcher->keywordsFor([$slug]));

            $rivals = [];
            foreach ($cuisines as $otherSlug => $keywords) {
                if ($otherSlug === $slug) {
                    continue;
                }
                foreach ($keywords as $keyword) {
                    if (isset($onSet[$keyword])) {
                        continue;
                    }
                    $rivals[] = $keyword;
                }
            }
            $rivals = array_values(array_unique($rivals));

            $this->assertNotEmpty($rivals, "Cuisine '{$slug}' has an empty rival set");
            $pattern = '/'.implode('|', $rivals).'/i';

            $this->assertSame(
                PREG_NO_ERROR,
                $this->compileError($pattern),
                "Cuisine '{$slug}' has a keyword that breaks its rival-pattern regex: {$pattern}"
            );
        }
    }

    private function compileError(string $pattern): int
    {
        $result = @preg_match($pattern, '');

        return $result === false ? PREG_INTERNAL_ERROR : preg_last_error();
    }
}
