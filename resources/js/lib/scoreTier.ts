export type ScoreTierKey = 'elite' | 'top' | 'popular' | 'rising';

export interface ScoreTier {
    key: ScoreTierKey;
    label: string;
}

/** The popularity tier a score falls in, or null below 0.4. */
export function scoreTier(score: number | string | null | undefined): ScoreTier | null {
    const value = Number(score ?? 0);
    if (value >= 0.9) return { key: 'elite', label: 'Elite' };
    if (value >= 0.8) return { key: 'top', label: 'Top rated' };
    if (value >= 0.6) return { key: 'popular', label: 'Popular' };
    if (value >= 0.4) return { key: 'rising', label: 'Rising' };
    return null;
}

/**
 * The one badge a restaurant photo may carry: an award, or one of the two
 * top tiers. Most photos carry none, so the badge means something when it's
 * there.
 */
export function photoBadge(restaurant: { has_award?: boolean | null; popularity_score?: number | string | null }): string | null {
    if (restaurant.has_award) return 'Award winner';
    const tier = scoreTier(restaurant.popularity_score);
    return tier && (tier.key === 'elite' || tier.key === 'top') ? tier.label : null;
}
