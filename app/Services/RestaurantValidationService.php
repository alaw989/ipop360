<?php

namespace App\Services;

class RestaurantValidationService
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function normalize(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_string($value)) {
                $attributes[$key] = trim($value);
            }
        }

        if (! empty($attributes['website_url'])) {
            $attributes['website_url'] = $this->normalizeUrl($attributes['website_url']);
        }

        if (! empty($attributes['photo_url'])) {
            $attributes['photo_url'] = $this->normalizeUrl($attributes['photo_url']);
        }

        if (! empty($attributes['menu_url'])) {
            $attributes['menu_url'] = $this->normalizeUrl($attributes['menu_url']);
        }

        if (isset($attributes['google_rating'])) {
            $attributes['google_rating'] = $this->clampRating((float) $attributes['google_rating']);
        }

        if (isset($attributes['yelp_rating'])) {
            $attributes['yelp_rating'] = $this->clampRating((float) $attributes['yelp_rating']);
        }

        if (isset($attributes['latitude'])) {
            $attributes['latitude'] = $this->clampLatitude((float) $attributes['latitude']);
        }

        if (isset($attributes['longitude'])) {
            $attributes['longitude'] = $this->clampLongitude((float) $attributes['longitude']);
        }

        if (! empty($attributes['phone'])) {
            $attributes['phone'] = $this->normalizePhone($attributes['phone']);
        }

        if (! empty($attributes['price_range'])) {
            $attributes['price_range'] = $this->normalizePriceRange($attributes['price_range']);
        }

        if (! empty($attributes['name'])) {
            $attributes['name'] = mb_substr($attributes['name'], 0, 255);
        }

        if (isset($attributes['google_review_count'])) {
            $attributes['google_review_count'] = max(0, (int) $attributes['google_review_count']);
        }

        if (isset($attributes['yelp_review_count'])) {
            $attributes['yelp_review_count'] = max(0, (int) $attributes['yelp_review_count']);
        }

        return $attributes;
    }

    public function normalizeUrl(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $url = trim($url);

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if ($host && ! preg_match('/[a-zA-Z0-9]/', $host)) {
            return null;
        }

        return $url;
    }

    public function clampRating(?float $rating): ?float
    {
        if ($rating === null) {
            return null;
        }

        return round(max(0.0, min(5.0, $rating)), 1);
    }

    public function clampLatitude(?float $lat): ?float
    {
        if ($lat === null) {
            return null;
        }

        return max(-90.0, min(90.0, $lat));
    }

    public function clampLongitude(?float $lng): ?float
    {
        if ($lng === null) {
            return null;
        }

        return max(-180.0, min(180.0, $lng));
    }

    public function normalizePhone(?string $phone): ?string
    {
        if (empty($phone)) {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (strlen($digits) === 0) {
            return null;
        }

        return $digits;
    }

    public function normalizePriceRange(?string $priceRange): ?string
    {
        if (empty($priceRange)) {
            return null;
        }

        $priceRange = trim($priceRange);

        $dollarCount = substr_count($priceRange, '$');
        if ($dollarCount > 0) {
            return str_repeat('$', min(4, $dollarCount));
        }

        return $priceRange;
    }
}
