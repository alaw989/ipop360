<?php

// ZCTAs whose Census internal point sits in a detached part of the ZCTA, far
// from where its addresses are, so ZipLocation would call every restaurant in
// it "far" from its own ZIP. Each override is the median pin of the prod
// restaurants citing the ZIP (2026-09-12; 83–100% of them within 8 km of it),
// with the Census radius kept. ZIP => [lat, lng, radius_km].
// Read by App\Support\ZipLocation, ahead of zip_centroids.php.

return [
    // Midtown Anchorage. The Census point is 448 km west, in the
    // Yukon–Kuskokwim region; #181 removed 7 Anchorage addresses over it.
    '99503' => [61.1907, -149.8899, 4.1],
    // Key West: the ZCTA spans the neighboring keys; its point is 12 km off.
    '33040' => [24.5590, -81.7997, 3.8],
    // Dauphin Island: the point is 12 km off, over the water.
    '36528' => [30.2547, -88.1147, 2.3],
    // Flour Bluff / NAS Corpus Christi: the point is 29 km off, on Padre Island.
    '78418' => [27.6452, -97.2781, 7.8],
];
