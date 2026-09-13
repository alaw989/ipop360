/**
 * What each ranking signal is called on the page. The stored breakdown keeps
 * the scorer's labels (PopularityScoreService::SIGNAL_LABELS), which other
 * parts of the app key colors on; these are the words a diner reads.
 */
const SIGNAL_NAMES: Record<string, string> = {
    'Quality': 'Ratings',
    'Google Rating': 'Ratings',
    'Google Reviews': 'Number of reviews',
    'Verified Presence': 'Verified details',
    'Profile Completeness': 'Complete listing',
    'Proximity': 'Distance',
    'Award': 'Awards',
    'Cuisine Match': 'Matches your search',
    'Social Presence': 'Social profiles',
    'Website Traffic': 'Website visits',
    'Page Views': 'Page views',
    'Social Link Clicks': 'Social profile clicks',
    'Menu Clicks': 'Menu views',
    'Directions Clicks': 'Directions requests',
    'Call Clicks': 'Calls',
}

export function signalName(label: string): string {
    return SIGNAL_NAMES[label] ?? label
}
