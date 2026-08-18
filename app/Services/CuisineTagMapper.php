<?php

namespace App\Services;

use App\Models\Cuisine;

/**
 * Maps free-text AI cuisine names to seeded cuisine records.
 *
 * The AI enrichment prompt returns cuisines as free text (e.g. "Italian",
 * "Cajun/Creole", "Pizza"). Only names that normalize to a SEEDED cuisine
 * slug are returned, so generic/unseeded terms never create phantom pivot
 * rows. Mirrors the conservative normalization used by the cuisine audit
 * sweep (restaurants:audit-cuisines) so both paths agree on what counts as a
 * tag.
 */
class CuisineTagMapper
{
    /** @var array<string, int>|null normalized name => id */
    private ?array $normalizedNameToId = null;

    /**
     * Resolve free-text cuisine names to seeded cuisine ids (deduplicated,
     * seed-order stable). Names that don't match a seeded cuisine are skipped.
     *
     * @param  list<mixed>  $names
     * @return list<int>
     */
    public function idsForNames(array $names): array
    {
        $map = $this->normalizedNameToId();

        $ids = [];
        foreach ($names as $name) {
            $key = $this->normalizeName((string) $name);
            if ($key !== '' && isset($map[$key])) {
                $ids[] = $map[$key];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, int>
     */
    private function normalizedNameToId(): array
    {
        if ($this->normalizedNameToId !== null) {
            return $this->normalizedNameToId;
        }

        $this->normalizedNameToId = Cuisine::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Cuisine $cuisine) => [
                $this->normalizeName((string) $cuisine->name) => $cuisine->id,
            ])
            ->filter(fn ($id, $key) => $key !== '')
            ->all();

        return $this->normalizedNameToId;
    }

    /**
     * Normalize a cuisine name into a slug-like key ("Cajun/Creole" →
     * "cajun-creole", "Tex-Mex" → "tex-mex", "New American" → "new-american").
     */
    private function normalizeName(string $value): string
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return '';
        }

        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }
}
